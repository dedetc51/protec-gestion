<?php

namespace Tests\Feature\Admin;

use App\Authorization\AuthorizationContext;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Membership;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class MemberAssignmentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_permission_and_searches_with_escaped_output(): void
    {
        $subject = User::factory()->create(['name' => '<script>alert(1)</script>', 'email' => 'find@example.test']);
        User::factory()->create(['name' => 'Absent']);
        $this->get('/admin/assignments')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin/assignments')->assertForbidden();
        $this->actingAs($this->actor())->get('/admin/assignments?q=find@example.test')->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('Absent');
        $this->get('/admin/assignments/'.$subject->id.'/edit')->assertSee('fieldset', false)->assertSee('legend', false)->assertSee('Appartenances aux antennes');
    }

    public function test_admin_assigns_multiple_branches_roles_and_department_responsibility(): void
    {
        $subject = User::factory()->create();
        $first = Branch::factory()->create();
        $second = Branch::factory()->create();
        $assignments = [$this->assignment('branch-manager', 'branch', $first->id), $this->assignment('equipment-manager', 'branch', $first->id), $this->assignment('volunteer', 'branch', $second->id), $this->assignment('operations-manager', 'department', $first->department_id)];
        $this->actingAs($this->actor())->put('/admin/assignments/'.$subject->id, ['memberships' => [['branch_id' => $first->id], ['branch_id' => $second->id]], 'assignments' => $assignments])->assertRedirect();
        $this->assertSame(2, $subject->memberships()->active()->count());
        $this->assertSame(4, $subject->roleAssignments()->active()->count());
        $this->assertTrue($subject->canIn('equipment.update', AuthorizationContext::branch($first)));
        $event = AuditEvent::where('event', 'members.assignments.replaced')->firstOrFail();
        $this->assertSame($subject->id, $event->metadata['target_id']);
        $this->assertCount(4, $event->metadata['after']['assignment_ids']);
        $this->assertArrayNotHasKey('email', $event->metadata);
    }

    public function test_president_replaces_only_own_records_and_rejects_forged_scopes(): void
    {
        $subject = User::factory()->create();
        $own = Branch::factory()->create();
        $foreign = Branch::factory()->create();
        Membership::factory()->create(['user_id' => $subject->id, 'branch_id' => $own->id]);
        $foreignMembership = Membership::factory()->create(['user_id' => $subject->id, 'branch_id' => $foreign->id]);
        $foreignAssignment = RoleAssignment::create(['user_id' => $subject->id] + $this->assignment('volunteer', 'branch', $foreign->id));
        $this->actingAs($this->actor($own->department))->put('/admin/assignments/'.$subject->id, ['memberships' => [['branch_id' => $foreign->id]], 'assignments' => []])->assertForbidden();
        $this->put('/admin/assignments/'.$subject->id, ['memberships' => [], 'assignments' => [$this->assignment('operations-manager', 'department', $foreign->department_id)]])->assertForbidden();
        $this->put('/admin/assignments/'.$subject->id, ['memberships' => [], 'assignments' => [$this->assignment('technical-admin', 'global', null)]])->assertForbidden();
        $this->assertDatabaseCount('audit_events', 0);
        $this->put('/admin/assignments/'.$subject->id, ['memberships' => [], 'assignments' => [], 'represented' => ['membership_ids' => $subject->memberships()->where('branch_id', $own->id)->pluck('id')->all()]])->assertRedirect();
        $this->assertNull($foreignMembership->fresh()->ends_at);
        $this->assertNull($foreignAssignment->fresh()->ends_at);
        $this->assertSame(1, $subject->memberships()->active()->count());
    }

    public function test_foreign_only_member_cannot_be_viewed_or_modified_by_president(): void
    {
        $subject = User::factory()->create();
        Membership::factory()->create(['user_id' => $subject->id]);
        $this->actingAs($this->actor(Department::factory()->create()))->get('/admin/assignments/'.$subject->id.'/edit')->assertForbidden();
        $this->put('/admin/assignments/'.$subject->id, ['memberships' => [], 'assignments' => []])->assertForbidden();
    }

    public function test_invalid_pairs_dates_duplicates_and_missing_membership_do_not_write(): void
    {
        $subject = User::factory()->create();
        $branch = Branch::factory()->create();
        $this->actingAs($this->actor());
        $this->put('/admin/assignments/'.$subject->id, ['memberships' => [], 'assignments' => [$this->assignment('department-president', 'branch', $branch->id)]])->assertSessionHasErrors('assignments.0.role_id');
        $this->put('/admin/assignments/'.$subject->id, ['memberships' => [['branch_id' => $branch->id, 'starts_at' => '2026-10-08', 'ends_at' => '2026-10-07']], 'assignments' => []])->assertSessionHasErrors('memberships.0.ends_at');
        $this->put('/admin/assignments/'.$subject->id, ['memberships' => [['branch_id' => $branch->id], ['branch_id' => $branch->id]], 'assignments' => []])->assertSessionHasErrors('memberships.1.branch_id');
        $this->put('/admin/assignments/'.$subject->id, ['memberships' => [], 'assignments' => [$this->assignment('volunteer', 'branch', $branch->id)]])->assertSessionHasErrors('assignments.0.scope_id');
        $this->assertDatabaseCount('memberships', 0);
        $this->assertSame(1, RoleAssignment::count());
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_unchanged_rows_keep_identity_and_reassignment_preserves_ended_history(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));
        $subject = User::factory()->create();
        $branch = Branch::factory()->create();
        $membership = Membership::factory()->create(['user_id' => $subject->id, 'branch_id' => $branch->id]);
        $assignment = RoleAssignment::create(['user_id' => $subject->id] + $this->assignment('volunteer', 'branch', $branch->id));
        $this->actingAs($this->actor());
        $payload = ['memberships' => [['branch_id' => $branch->id]], 'assignments' => [$this->assignment('volunteer', 'branch', $branch->id)]];
        $this->put('/admin/assignments/'.$subject->id, $payload)->assertRedirect();
        $this->assertSame([$membership->id], $subject->memberships()->pluck('id')->all());
        $this->put('/admin/assignments/'.$subject->id, ['memberships' => [], 'assignments' => []] + $this->represented($subject))->assertRedirect();
        $this->assertTrue($assignment->fresh()->ends_at->eq(now()));
        $this->travel(1)->hour();
        $this->put('/admin/assignments/'.$subject->id, $payload)->assertRedirect();
        $this->assertSame(2, $subject->memberships()->count());
        $this->assertSame(2, $subject->roleAssignments()->count());
        $this->assertNotSame($assignment->id, $subject->roleAssignments()->active()->first()->id);
    }

    public function test_future_and_expired_dates_are_preserved_and_never_authorize(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));
        $subject = User::factory()->create();
        $branch = Branch::factory()->create();
        $dates = ['starts_at' => '2026-10-07', 'ends_at' => '2026-10-09'];
        $this->actingAs($this->actor())->put('/admin/assignments/'.$subject->id, ['memberships' => [['branch_id' => $branch->id] + $dates], 'assignments' => [$this->assignment('volunteer', 'branch', $branch->id) + $dates]])->assertRedirect();
        $this->assertFalse($subject->canIn('equipment.view', AuthorizationContext::branch($branch)));
        $this->travel(2)->days();
        $this->assertTrue($subject->canIn('equipment.view', AuthorizationContext::branch($branch)));
        $this->travel(2)->days();
        $this->assertFalse($subject->canIn('equipment.view', AuthorizationContext::branch($branch)));
    }

    public function test_final_admin_cannot_be_removed_or_replaced_with_future_assignment(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor)->put('/admin/assignments/'.$actor->id, ['memberships' => [], 'assignments' => []] + $this->represented($actor))->assertSessionHasErrors('assignments');
        $this->put('/admin/assignments/'.$actor->id, ['memberships' => [], 'assignments' => [$this->assignment('technical-admin', 'global', null) + ['starts_at' => now()->addDay()->toDateTimeString()]]] + $this->represented($actor))->assertSessionHasErrors('assignments');
        $this->assertTrue($actor->canIn('technical.manage', AuthorizationContext::global()));
        $this->assertDatabaseCount('audit_events', 0);
    }

    private function actor(?Department $department = null): User
    {
        $user = User::factory()->create();
        RoleAssignment::create(['user_id' => $user->id] + $this->assignment($department ? 'department-president' : 'technical-admin', $department ? 'department' : 'global', $department?->id));

        return $user;
    }

    public function test_form_submission_requires_its_final_marker_and_preserves_sparse_field_indices(): void
    {
        $subject = User::factory()->create();
        $branch = Branch::factory()->create();
        $this->actingAs($this->actor());
        $this->put('/admin/assignments/'.$subject->id, ['selection_mode' => '1', 'memberships' => [], 'assignments' => []])->assertSessionHasErrors('submission_complete');
        $payload = ['selection_mode' => '1', 'submission_complete' => '1', 'memberships' => [7 => ['enabled' => '1', 'branch_id' => $branch->id]], 'assignments' => []];
        $this->put('/admin/assignments/'.$subject->id, $payload)->assertRedirect();
        $this->assertDatabaseHas('memberships', ['user_id' => $subject->id, 'branch_id' => $branch->id]);
    }

    public function test_removing_one_technical_admin_succeeds_only_with_another_active_admin(): void
    {
        $subject = $this->actor();
        $actor = $this->actor();
        $this->actingAs($actor)->put('/admin/assignments/'.$subject->id, ['memberships' => [], 'assignments' => []] + $this->represented($subject))->assertRedirect();
        $this->assertFalse($subject->canIn('technical.manage', AuthorizationContext::global()));
        $this->assertTrue($actor->canIn('technical.manage', AuthorizationContext::global()));
        $this->assertSame(2, RoleAssignment::count());
    }

    public function test_deactivated_second_admin_does_not_satisfy_final_admin_invariant(): void
    {
        $actor = $this->actor();
        $this->actor()->forceFill(['deactivated_at' => now()])->save();
        $this->actingAs($actor)->put('/admin/assignments/'.$actor->id, ['memberships' => [], 'assignments' => []] + $this->represented($actor))->assertSessionHasErrors('assignments');
        $this->assertSame(2, RoleAssignment::active()->count());
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_changed_current_dates_split_history_and_new_expired_windows_remain_inactive(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));
        $subject = User::factory()->create();
        $branch = Branch::factory()->create();
        $history = Membership::factory()->create(['user_id' => $subject->id, 'branch_id' => $branch->id, 'starts_at' => '2026-10-01']);
        $this->actingAs($this->actor())->put('/admin/assignments/'.$subject->id, ['memberships' => [['branch_id' => $branch->id, 'starts_at' => '2026-10-01', 'ends_at' => '2026-10-09']], 'assignments' => []] + $this->represented($subject))->assertRedirect();
        $this->assertSame('2026-10-06 12:00:00', $history->fresh()->ends_at->toDateTimeString());
        $this->assertDatabaseHas('memberships', ['user_id' => $subject->id, 'branch_id' => $branch->id, 'starts_at' => '2026-10-06 12:00:00', 'ends_at' => '2026-10-09 00:00:00']);
        $other = Branch::factory()->create();
        $this->put('/admin/assignments/'.$subject->id, ['memberships' => [['branch_id' => $other->id, 'starts_at' => '2026-09-01', 'ends_at' => '2026-09-02']], 'assignments' => [$this->assignment('volunteer', 'branch', $other->id) + ['starts_at' => '2026-09-01', 'ends_at' => '2026-09-02']]] + $this->represented($subject))->assertRedirect();
        $this->assertSame(0, $subject->memberships()->active()->count());
        $this->assertFalse($subject->canIn('equipment.view', AuthorizationContext::branch($other)));
    }

    public function test_audit_failure_rolls_back_all_member_mutations(): void
    {
        $actor = $this->actor();
        $subject = User::factory()->create();
        $branch = Branch::factory()->create();
        Event::listen('eloquent.creating: '.AuditEvent::class, function (): void {
            throw new \RuntimeException('Audit unavailable');
        });
        try {
            $this->withoutExceptionHandling()->actingAs($actor)->put('/admin/assignments/'.$subject->id, ['memberships' => [['branch_id' => $branch->id]], 'assignments' => []]);
            $this->fail('The audit failure must abort the transaction.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.AuditEvent::class);
        }
        $this->assertDatabaseCount('memberships', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    private function assignment(string $slug, string $scope, ?int $id): array
    {
        return ['role_id' => Role::where('slug', $slug)->firstOrFail()->id, 'scope_type' => $scope, 'scope_id' => $id];
    }

    private function represented(User $subject): array
    {
        return ['represented' => ['membership_ids' => $subject->memberships()->pluck('id')->all(), 'assignment_ids' => $subject->roleAssignments()->pluck('id')->all()]];
    }

    public function test_saving_rendered_form_unchanged_preserves_second_precision_and_row_identity(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));
        $subject = User::factory()->create();
        $branch = Branch::factory()->create();
        $membership = Membership::factory()->create(['user_id' => $subject->id, 'branch_id' => $branch->id, 'starts_at' => '2026-10-01 11:22:33']);
        $assignment = RoleAssignment::create(['user_id' => $subject->id] + $this->assignment('volunteer', 'branch', $branch->id) + ['starts_at' => '2026-10-01 11:22:33']);
        $response = $this->actingAs($this->actor())->get('/admin/assignments/'.$subject->id.'/edit');
        $document = new \DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $inputs = (new \DOMXPath($document))->query('//form[@action="'.route('admin.assignments.update', $subject).'"]//input');
        $pairs = [];
        foreach ($inputs as $input) {
            if ($input->getAttribute('type') === 'checkbox' && ! $input->hasAttribute('checked')) {
                continue;
            }
            $pairs[] = urlencode($input->getAttribute('name')).'='.urlencode($input->getAttribute('value'));
        }
        parse_str(implode('&', $pairs), $payload);
        $this->put('/admin/assignments/'.$subject->id, $payload)->assertRedirect();
        $this->assertSame([$membership->id], $subject->memberships()->pluck('id')->all());
        $this->assertSame([$assignment->id], $subject->roleAssignments()->pluck('id')->all());
    }

    public function test_expired_membership_can_have_an_unspecified_start_date(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));
        $subject = User::factory()->create();
        $branch = Branch::factory()->create();
        $this->actingAs($this->actor())->put('/admin/assignments/'.$subject->id, ['memberships' => [['branch_id' => $branch->id, 'ends_at' => '2026-09-01']], 'assignments' => []])->assertRedirect();
        $this->assertDatabaseHas('memberships', ['user_id' => $subject->id, 'branch_id' => $branch->id, 'starts_at' => null, 'ends_at' => '2026-09-01 00:00:00']);
        $this->assertSame(0, $subject->memberships()->active()->count());
    }
}
