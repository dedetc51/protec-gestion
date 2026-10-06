<?php

namespace Tests\Feature\Admin;

use App\Authorization\AuthorizationContext;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Membership;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignmentHistoryRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_final_admin_cannot_schedule_an_expiry_without_continuous_successor(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));
        $actor = $this->admin();
        $original = $actor->roleAssignments()->sole();

        $this->actingAs($actor)->put(route('admin.assignments.update', $actor), [
            'memberships' => [], 'assignments' => [$original->only(['role_id', 'scope_type', 'scope_id']) + ['ends_at' => '2026-10-07 12:00:00']],
            'represented' => ['assignment_ids' => [$original->id]],
        ])->assertSessionHasErrors('assignments');

        $this->assertNull($original->fresh()->ends_at);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_departing_admin_cannot_leave_only_a_temporary_admin(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));
        $actor = $this->admin();
        $this->admin(['ends_at' => '2026-10-07 12:00:00']);

        $this->actingAs($actor)->put(route('admin.assignments.update', $actor), [
            'memberships' => [], 'assignments' => [], 'represented' => ['assignment_ids' => $actor->roleAssignments()->pluck('id')->all()],
        ])->assertSessionHasErrors('assignments');

        $this->assertTrue($actor->canIn('technical.manage', AuthorizationContext::global()));
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_adjacent_future_handover_keeps_continuous_access(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));
        $actor = $this->admin();
        $successor = $this->admin(['starts_at' => '2026-10-07 12:00:00']);
        $original = $actor->roleAssignments()->sole();

        $this->actingAs($actor)->put(route('admin.assignments.update', $actor), [
            'memberships' => [], 'assignments' => [$original->only(['role_id', 'scope_type', 'scope_id']) + ['ends_at' => '2026-10-07 12:00:00']],
            'represented' => ['assignment_ids' => [$original->id]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->travelTo(Carbon::parse('2026-10-07 12:00:00'));
        $this->assertFalse($actor->canIn('technical.manage', AuthorizationContext::global()));
        $this->assertTrue($successor->canIn('technical.manage', AuthorizationContext::global()));
    }

    public function test_future_handover_with_a_gap_is_rejected(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));
        $actor = $this->admin();
        $this->admin(['starts_at' => '2026-10-07 12:00:01']);
        $original = $actor->roleAssignments()->sole();

        $this->actingAs($actor)->put(route('admin.assignments.update', $actor), [
            'memberships' => [], 'assignments' => [$original->only(['role_id', 'scope_type', 'scope_id']) + ['ends_at' => '2026-10-07 12:00:00']],
            'represented' => ['assignment_ids' => [$original->id]],
        ])->assertSessionHasErrors('assignments');

        $this->assertNull($original->fresh()->ends_at);
    }

    public function test_unchanged_form_preserves_both_bootstrap_intervals(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));
        $subject = $this->admin(['starts_at' => '2026-10-07 12:00:00']);
        $roleId = $subject->roleAssignments()->sole()->role_id;
        $bridge = RoleAssignment::create(['user_id' => $subject->id, 'role_id' => $roleId, 'scope_type' => 'global', 'scope_id' => null, 'starts_at' => now(), 'ends_at' => '2026-10-07 12:00:00']);
        $before = $subject->roleAssignments()->orderBy('id')->get()->toArray();
        $this->actingAs($this->admin());

        $this->put(route('admin.assignments.update', $subject), $this->formPayload($subject))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($before, $subject->roleAssignments()->orderBy('id')->get()->toArray());
        $this->assertTrue($subject->canIn('technical.manage', AuthorizationContext::global()));
        $this->travelTo(Carbon::parse('2026-10-07 12:00:00'));
        $this->assertTrue($subject->canIn('technical.manage', AuthorizationContext::global()));
        $this->assertSame('2026-10-07 12:00:00', $bridge->fresh()->ends_at->toDateTimeString());
    }

    public function test_unchanged_form_preserves_records_in_inactive_organizations(): void
    {
        $subject = User::factory()->create();
        $inactive = Branch::factory()->create();
        $inactive->forceFill(['deactivated_at' => now()])->save();
        $inactiveDepartment = Department::factory()->create();
        $inactiveDepartment->forceFill(['deactivated_at' => now()])->save();
        $active = Branch::factory()->create();
        Membership::factory()->create(['user_id' => $subject->id, 'branch_id' => $inactive->id]);
        Membership::factory()->create(['user_id' => $subject->id, 'branch_id' => $active->id]);
        RoleAssignment::create(['user_id' => $subject->id, 'role_id' => Role::where('slug', 'volunteer')->sole()->id, 'scope_type' => 'branch', 'scope_id' => $inactive->id]);
        RoleAssignment::create(['user_id' => $subject->id, 'role_id' => Role::where('slug', 'operations-manager')->sole()->id, 'scope_type' => 'department', 'scope_id' => $inactiveDepartment->id]);
        $membershipBefore = $subject->memberships()->orderBy('id')->get()->toArray();
        $assignmentBefore = $subject->roleAssignments()->orderBy('id')->get()->toArray();
        $this->actingAs($this->admin());

        $this->put(route('admin.assignments.update', $subject), $this->formPayload($subject))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($membershipBefore, $subject->memberships()->orderBy('id')->get()->toArray());
        $this->assertSame($assignmentBefore, $subject->roleAssignments()->orderBy('id')->get()->toArray());
    }

    public function test_omission_without_an_explicit_record_reference_preserves_history(): void
    {
        $subject = User::factory()->create();
        $membership = Membership::factory()->create(['user_id' => $subject->id]);
        $this->actingAs($this->admin())->put(route('admin.assignments.update', $subject), ['memberships' => [], 'assignments' => []])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($membership->fresh()->ends_at);
    }

    public function test_president_cannot_forge_foreign_represented_record_ids(): void
    {
        $subject = User::factory()->create();
        $own = Branch::factory()->create();
        Membership::factory()->create(['user_id' => $subject->id, 'branch_id' => $own->id]);
        $foreign = Membership::factory()->create(['user_id' => $subject->id]);
        $actor = User::factory()->create();
        RoleAssignment::create(['user_id' => $actor->id, 'role_id' => Role::where('slug', 'department-president')->sole()->id, 'scope_type' => 'department', 'scope_id' => $own->department_id]);

        $this->actingAs($actor)->put(route('admin.assignments.update', $subject), ['memberships' => [], 'assignments' => [], 'represented' => ['membership_ids' => [$foreign->id]]])->assertForbidden();

        $this->assertNull($foreign->fresh()->ends_at);
        $this->assertDatabaseCount('audit_events', 0);
    }

    private function admin(array $dates = []): User
    {
        $user = User::factory()->create();
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('slug', 'technical-admin')->sole()->id, 'scope_type' => 'global', 'scope_id' => null] + $dates);

        return $user;
    }

    public function test_empty_future_windows_are_preserved_but_never_selected_for_editing_or_overlap(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));
        $subject = User::factory()->create();
        $branch = Branch::factory()->create();
        $membership = Membership::factory()->create(['user_id' => $subject->id, 'branch_id' => $branch->id, 'starts_at' => '2026-10-08 12:00:00', 'ends_at' => '2026-10-08 12:00:00']);
        $roleId = Role::where('slug', 'volunteer')->sole()->id;
        $empty = RoleAssignment::create(['user_id' => $subject->id, 'role_id' => $roleId, 'scope_type' => 'branch', 'scope_id' => $branch->id, 'starts_at' => '2026-10-08 12:00:00', 'ends_at' => '2026-10-08 12:00:00']);
        $this->actingAs($this->admin());
        $this->put(route('admin.assignments.update', $subject), $this->formPayload($subject))->assertRedirect()->assertSessionHasNoErrors();
        $this->put(route('admin.assignments.update', $subject), ['memberships' => [['branch_id' => $branch->id]], 'assignments' => [['role_id' => $roleId, 'scope_type' => 'branch', 'scope_id' => $branch->id]]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('2026-10-08 12:00:00', $empty->fresh()->ends_at->toDateTimeString());
        $this->assertSame('2026-10-08 12:00:00', $membership->fresh()->ends_at->toDateTimeString());
        $this->assertTrue($subject->canIn('equipment.view', AuthorizationContext::branch($branch)));
    }

    private function formPayload(User $subject): array
    {
        $response = $this->get(route('admin.assignments.edit', $subject));
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

        return $payload;
    }
}
