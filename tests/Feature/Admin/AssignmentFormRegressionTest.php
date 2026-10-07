<?php

namespace Tests\Feature\Admin;

use App\Authorization\AuthorizationContext;
use App\Http\Requests\Admin\UpdateMemberAssignmentsRequest;
use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\Membership;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AssignmentFormRegressionTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('incompleteForms')]
    public function test_rejected_incomplete_or_stale_form_retry_preserves_persisted_periods(string $problem): void
    {
        [$actor, $subject, $branch, $membership, $assignment] = $this->fixture();
        $edit = '/admin/assignments/'.$subject->id.'/edit?scope=branch:'.$branch->id;
        $payload = $this->serialize($this->actingAs($actor)->get($edit)->assertOk()->getContent());
        foreach ($payload['memberships'] as &$row) {
            unset($row['enabled']);
            $row['starts_at'] = '2020-01-01T00:00:00';
        }
        unset($row);
        foreach ($payload['assignments'] as &$row) {
            unset($row['enabled']);
            $row['starts_at'] = '2020-01-01T00:00:00';
        }
        unset($row);
        if ($problem === 'missing marker') {
            unset($payload['submission_complete']);
        } elseif ($problem === 'missing row') {
            array_pop($payload['assignments']);
        } elseif ($problem === 'changed identity') {
            $index = array_key_first($payload['memberships']);
            $payload['memberships'][$index]['branch_id'] = Branch::factory()->create()->id;
        } elseif ($problem === 'missing date') {
            unset($payload['assignments'][0]['ends_at']);
        } else {
            Role::factory()->create(['allows_branch' => true]);
        }

        $this->from($edit)->put('/admin/assignments/'.$subject->id, $payload)->assertRedirect($edit)->assertSessionHasErrors($problem === 'missing marker' ? 'submission_complete' : 'editor_token');
        $this->assertNull($membership->fresh()->ends_at);
        $this->assertNull($assignment->fresh()->ends_at);
        $this->assertDatabaseCount('audit_events', 0);
        $retry = $this->serialize($this->get($edit)->assertOk()->getContent());
        $this->put('/admin/assignments/'.$subject->id, $retry)->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame([$membership->id], $subject->memberships()->pluck('id')->all());
        $this->assertSame([$assignment->id], $subject->roleAssignments()->pluck('id')->all());
        $this->assertSame('2026-10-01 11:22:33', $membership->fresh()->starts_at->toDateTimeString());
        $this->assertSame('2026-10-01 11:22:33', $assignment->fresh()->starts_at->toDateTimeString());
        $this->assertNull($membership->fresh()->ends_at);
        $this->assertNull($assignment->fresh()->ends_at);
        $this->assertTrue($subject->canIn('equipment.view', AuthorizationContext::branch($branch)));
        $this->assertSame(0, AuditEvent::whereIn('event', ['members.role.revoked', 'members.membership.ended'])->count());
    }

    public static function incompleteForms(): array
    {
        return array_map(fn (string $problem): array => [$problem], ['missing marker', 'missing row', 'changed identity', 'missing date', 'catalog added']);
    }

    public function test_complete_matching_invalid_form_restores_deliberate_unchecks_and_dates(): void
    {
        [$actor, $subject, $branch, $membership, $assignment] = $this->fixture();
        $equipment = RoleAssignment::factory()->for($subject)->create(['role_id' => Role::where('slug', 'equipment-manager')->value('id'), 'scope_type' => 'branch', 'scope_id' => $branch->id]);
        $edit = '/admin/assignments/'.$subject->id.'/edit?scope=branch:'.$branch->id;
        $payload = $this->serialize($this->actingAs($actor)->get($edit)->assertOk()->getContent());
        foreach ($payload['assignments'] as $index => &$row) {
            if ((int) $row['role_id'] === $assignment->role_id) {
                unset($row['enabled']);
            }
            if ((int) $row['role_id'] === $equipment->role_id) {
                $row['starts_at'] = '2026-10-03T00:00:00';
                $row['ends_at'] = '2026-10-02T00:00:00';
                $invalidIndex = $index;
            }
        }
        unset($row);

        $this->from($edit)->put('/admin/assignments/'.$subject->id, $payload)->assertSessionHasErrors('assignments.'.$invalidIndex.'.ends_at')->assertSessionDoesntHaveErrors('editor_token');
        $retry = $this->serialize($this->get($edit)->assertOk()->getContent());

        foreach ($retry['assignments'] as $row) {
            if ((int) $row['role_id'] === $assignment->role_id) {
                $this->assertArrayNotHasKey('enabled', $row);
            }
            if ((int) $row['role_id'] === $equipment->role_id) {
                $this->assertSame('1', $row['enabled']);
                $this->assertSame('2026-10-03T00:00:00', $row['starts_at']);
                $this->assertSame('2026-10-02T00:00:00', $row['ends_at']);
            }
        }
        $this->assertNull($membership->fresh()->ends_at);
        $this->assertNull($assignment->fresh()->ends_at);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_seventeen_branches_save_with_the_default_php_input_limit_and_preserve_other_periods(): void
    {
        [$actor, $subject, $branch, $membership, $assignment] = $this->fixture();
        $others = Branch::factory()->count(16)->create(['department_id' => $branch->department_id]);
        $otherMembership = Membership::factory()->for($subject)->for($others->first())->create();
        $otherAssignment = RoleAssignment::factory()->for($subject)->create(['role_id' => $assignment->role_id, 'scope_type' => 'branch', 'scope_id' => $others->first()->id, 'starts_at' => now()->addWeek()]);
        $response = $this->actingAs($actor)->get('/admin/assignments/'.$subject->id.'/edit?scope=branch:'.$branch->id)->assertOk();
        $pairs = $this->pairs($response->getContent());
        $this->assertLessThan(800, count($pairs));
        parse_str(implode('&', $pairs), $payload);
        $this->assertSame('1', $payload['submission_complete']);

        $this->put('/admin/assignments/'.$subject->id, $payload)->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame([$membership->id, $otherMembership->id], $subject->memberships()->orderBy('id')->pluck('id')->all());
        $this->assertSame([$assignment->id, $otherAssignment->id], $subject->roleAssignments()->orderBy('id')->pluck('id')->all());
        $this->assertNull($otherMembership->fresh()->ends_at);
        $this->assertNull($otherAssignment->fresh()->ends_at);
        $this->assertSame(0, AuditEvent::whereIn('event', ['members.role.revoked', 'members.membership.ended'])->count());
    }

    public function test_full_role_pages_stay_bounded_and_unshown_periods_survive_an_explicit_revocation(): void
    {
        [$actor, $subject, $branch, , $volunteer] = $this->fixture();
        $roles = Role::factory()->count(100)->sequence(fn (Sequence $sequence): array => ['name' => sprintf('Z responsabilité %03d', $sequence->index)])->create(['allows_branch' => true]);
        foreach (Role::where('allows_branch', true)->where('id', '!=', $volunteer->role_id)->get() as $role) {
            RoleAssignment::factory()->for($subject)->create(['role_id' => $role->id, 'scope_type' => 'branch', 'scope_id' => $branch->id]);
        }
        $before = $subject->roleAssignments()->orderBy('id')->get()->toArray();
        $edit = '/admin/assignments/'.$subject->id.'/edit?scope=branch:'.$branch->id;
        $html = $this->actingAs($actor)->get($edit)->assertOk()->assertSee('Page suivante')->getContent();
        $pairs = $this->pairs($html);
        $this->assertLessThanOrEqual(610, count($pairs));
        $payload = $this->serialize($html);
        $this->assertCount(100, $payload['assignments']);
        $this->assertSame('1', $payload['submission_complete']);
        $this->put('/admin/assignments/'.$subject->id, $payload)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($before, $subject->roleAssignments()->orderBy('id')->get()->toArray());

        $next = $this->serialize($this->get($edit.'&page=2')->assertOk()->assertSee('Page précédente')->getContent());
        $revokedRoleId = (int) $next['assignments'][0]['role_id'];
        unset($next['assignments'][0]['enabled']);
        $this->put('/admin/assignments/'.$subject->id, $next)->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(110, $subject->roleAssignments()->active()->count());
        $this->assertNotNull($subject->roleAssignments()->where('role_id', $revokedRoleId)->sole()->ends_at);
        $this->assertSame(1, AuditEvent::where('event', 'members.role.revoked')->count());
        $this->assertSame(0, AuditEvent::where('event', 'members.membership.ended')->count());
    }

    public function test_separate_scope_saves_accumulate_memberships_and_roles_across_departments(): void
    {
        [$actor, $subject, $first, $membership, $assignment] = $this->fixture();
        $second = Branch::factory()->create();
        $edit = '/admin/assignments/'.$subject->id.'/edit?scope=branch:'.$second->id;
        $payload = $this->serialize($this->actingAs($actor)->get($edit)->assertOk()->getContent());
        $payload['memberships'][0]['enabled'] = '1';
        foreach ($payload['assignments'] as &$row) {
            if ((int) $row['role_id'] === $assignment->role_id) {
                $row['enabled'] = '1';
            }
        }
        unset($row);
        $this->put('/admin/assignments/'.$subject->id, $payload)->assertSessionHasNoErrors()->assertRedirect();
        $department = $this->serialize($this->get('/admin/assignments/'.$subject->id.'/edit?scope=department:'.$second->department_id)->assertOk()->getContent());
        foreach ($department['assignments'] as &$row) {
            if ((int) $row['role_id'] === Role::where('slug', 'operations-manager')->value('id')) {
                $row['enabled'] = '1';
            }
        }
        unset($row);
        $this->put('/admin/assignments/'.$subject->id, $department)->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(2, $subject->memberships()->active()->count());
        $this->assertSame(3, $subject->roleAssignments()->active()->count());
        $this->assertNull($membership->fresh()->ends_at);
        $this->assertNull($assignment->fresh()->ends_at);
        $this->assertTrue($subject->canIn('equipment.view', AuthorizationContext::branch($first)));
        $this->assertTrue($subject->canIn('equipment.view', AuthorizationContext::branch($second)));
        $this->assertSame(0, AuditEvent::whereIn('event', ['members.role.revoked', 'members.membership.ended'])->count());
    }

    public function test_president_cannot_select_a_foreign_editor_scope(): void
    {
        [, $subject, $branch] = $this->fixture();
        $president = User::factory()->create();
        RoleAssignment::factory()->for($president)->create(['role_id' => Role::where('slug', 'department-president')->value('id'), 'scope_type' => 'department', 'scope_id' => $branch->department_id]);
        $foreign = Branch::factory()->create();

        $this->actingAs($president)->get('/admin/assignments/'.$subject->id.'/edit?scope=branch:'.$foreign->id)->assertForbidden();
        $this->get('/admin/assignments/'.$subject->id.'/edit?scope=department:'.$foreign->department_id)->assertForbidden();
        $this->get('/admin/assignments/'.$subject->id.'/edit?scope=global')->assertForbidden();
    }

    #[DataProvider('forgedContexts')]
    public function test_editor_manifest_cannot_be_reused_for_another_actor_subject_or_scope(string $problem): void
    {
        [$actor, $subject, $branch] = $this->fixture();
        $payload = $this->serialize($this->actingAs($actor)->get('/admin/assignments/'.$subject->id.'/edit?scope=branch:'.$branch->id)->assertOk()->getContent());
        if ($problem === 'actor') {
            $this->actingAs($this->admin());
        } elseif ($problem === 'subject') {
            $subject = User::factory()->create();
        } elseif ($problem === 'scope') {
            $payload['memberships'][0]['branch_id'] = Branch::factory()->create()->id;
        } else {
            $payload['editor_token'] = 'forged';
        }

        $this->put('/admin/assignments/'.$subject->id, $payload)->assertSessionHasErrors('editor_token');
        $this->assertDatabaseCount('audit_events', 0);
    }

    public static function forgedContexts(): array
    {
        return array_map(fn (string $problem): array => [$problem], ['actor', 'subject', 'scope', 'signature']);
    }

    #[DataProvider('malformedGlobalForms')]
    public function test_global_editor_rejects_structurally_missing_or_malformed_controls(string $problem): void
    {
        [$actor, $subject] = $this->fixture();
        $payload = $this->serialize($this->actingAs($actor)->get('/admin/assignments/'.$subject->id.'/edit?scope=global')->assertOk()->getContent());
        if ($problem === 'missing nullable identity') {
            unset($payload['assignments'][0]['scope_id']);
        } elseif ($problem === 'numeric selection mode') {
            $payload['selection_mode'] = 1;
        } else {
            $payload['assignments'][0]['enabled'] = null;
        }

        $this->put('/admin/assignments/'.$subject->id, $payload)->assertSessionHasErrors('editor_token');
        $this->assertDatabaseCount('audit_events', 0);
    }

    public static function malformedGlobalForms(): array
    {
        return array_map(fn (string $problem): array => [$problem], ['missing nullable identity', 'numeric selection mode', 'null enabled value']);
    }

    #[DataProvider('concurrentRevocations')]
    public function test_editor_rejects_a_revocation_committed_after_form_validation(string $revocation): void
    {
        [$actor, $subject, $branch, $membership, $assignment] = $this->fixture();
        $otherActor = $this->admin();
        $edit = '/admin/assignments/'.$subject->id.'/edit?scope=branch:'.$branch->id;
        $retained = $this->serialize($this->actingAs($actor)->get($edit)->assertOk()->getContent());
        $revoked = $this->serialize($this->actingAs($otherActor)->get($edit)->assertOk()->getContent());
        foreach ($revoked['assignments'] as &$row) {
            unset($row['enabled']);
        }
        unset($row);
        if ($revocation === 'membership') {
            unset($revoked['memberships'][0]['enabled']);
        }
        $interleaved = false;
        $this->app->afterResolving(UpdateMemberAssignmentsRequest::class, function (UpdateMemberAssignmentsRequest $request) use (&$interleaved, $otherActor, $actor, $subject, $revoked): void {
            if ($interleaved) {
                return;
            }
            $request->validated();
            $interleaved = true;
            $this->actingAs($otherActor)->put('/admin/assignments/'.$subject->id, $revoked)->assertSessionHasNoErrors()->assertRedirect();
            $this->actingAs($actor);
        });

        $this->actingAs($actor)->from($edit)->put('/admin/assignments/'.$subject->id, $retained)->assertSessionHasErrors('editor_token')->assertRedirect($edit);

        $this->assertTrue($interleaved);
        $this->assertSame([$membership->id], $subject->memberships()->pluck('id')->all());
        $this->assertSame([$assignment->id], $subject->roleAssignments()->pluck('id')->all());
        $this->assertTrue($assignment->fresh()->ends_at->eq(now()));
        if ($revocation === 'membership') {
            $this->assertTrue($membership->fresh()->ends_at->eq(now()));
        } else {
            $this->assertNull($membership->fresh()->ends_at);
        }
        $this->assertFalse($subject->canIn('equipment.view', AuthorizationContext::branch($branch)));
        $this->assertSame(0, AuditEvent::where('actor_id', $actor->id)->count());
        $this->assertDatabaseHas('audit_events', ['actor_id' => $otherActor->id, 'event' => 'members.role.revoked']);
    }

    public static function concurrentRevocations(): array
    {
        return ['role period' => ['role'], 'membership period' => ['membership']];
    }

    private function fixture(): array
    {
        $this->travelTo(now()->setDate(2026, 10, 7)->setTime(12, 0));
        $actor = $this->admin();
        $subject = User::factory()->create();
        $branch = Branch::factory()->create();
        $membership = Membership::factory()->for($subject)->for($branch)->create(['starts_at' => '2026-10-01 11:22:33']);
        $assignment = RoleAssignment::factory()->for($subject)->create(['role_id' => Role::where('slug', 'volunteer')->value('id'), 'scope_type' => 'branch', 'scope_id' => $branch->id, 'starts_at' => '2026-10-01 11:22:33']);

        return [$actor, $subject, $branch, $membership, $assignment];
    }

    private function admin(): User
    {
        $actor = User::factory()->create();
        RoleAssignment::factory()->for($actor)->create(['role_id' => Role::where('slug', 'technical-admin')->value('id')]);

        return $actor;
    }

    private function serialize(string $html): array
    {
        parse_str(implode('&', $this->pairs($html)), $payload);

        return $payload;
    }

    private function pairs(string $html): array
    {
        $document = new DOMDocument;
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $inputs = (new DOMXPath($document))->query('//form[@id="assignment-form"]//input[@name and not(@disabled)]');
        $pairs = [];
        foreach ($inputs as $input) {
            if ($input->getAttribute('type') === 'checkbox' && ! $input->hasAttribute('checked')) {
                continue;
            }
            $pairs[] = urlencode($input->getAttribute('name')).'='.urlencode($input->getAttribute('value'));
        }

        return $pairs;
    }
}
