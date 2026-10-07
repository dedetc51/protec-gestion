<?php

namespace Tests\Feature\Admin;

use App\Authorization\AuthorizationContext;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\DepartmentRolePermission;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\PermissionMatrixService;
use App\Services\ScopedPermissionResolver;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PermissionMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_matrix_requires_authentication_and_management_permission(): void
    {
        $department = Department::factory()->create();
        $this->get('/admin/permissions')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin/permissions')->assertForbidden();
        $this->get('/admin/permissions/departments/'.$department->id)->assertForbidden();
        $this->put('/admin/permissions/global', [])->assertForbidden();
        $this->put('/admin/permissions/departments/'.$department->id, [])->assertForbidden();
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_admin_updates_global_booleans_with_safe_before_after_audit(): void
    {
        $actor = $this->actor('technical-admin');
        [$roleId, $permissionId] = $this->pair('volunteer', 'equipment.update');
        $payload = $this->payload();
        $payload['cells'][$roleId][$permissionId] = '1';

        $this->actingAs($actor)->put('/admin/permissions/global', $payload)->assertRedirect('/admin/permissions')->assertSessionHasNoErrors();

        $this->assertDatabaseHas('role_permissions', ['role_id' => $roleId, 'permission_id' => $permissionId, 'granted' => true]);
        $audit = AuditEvent::where('event', 'permissions.global.updated')->firstOrFail();
        $this->assertSame($actor->id, $audit->actor_id);
        $this->assertSame('global', $audit->metadata['scope_type']);
        $this->assertContains(['role_id' => $roleId, 'permission_id' => $permissionId, 'before' => false, 'after' => true], $audit->metadata['changes']);
        $payload = $this->payload();
        unset($payload['cells'][$roleId][$permissionId]);
        $this->put('/admin/permissions/global', $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('role_permissions', ['role_id' => $roleId, 'permission_id' => $permissionId, 'granted' => false]);
        [$technicalId, $technicalPermissionId] = $this->pair('technical-admin', 'technical.manage');
        $this->assertDatabaseHas('role_permissions', ['role_id' => $technicalId, 'permission_id' => $technicalPermissionId, 'granted' => true]);
    }

    public function test_president_cannot_access_global_or_another_departments_matrix(): void
    {
        $own = Department::factory()->create(['name' => 'Département autorisé']);
        $other = Department::factory()->create(['name' => 'Département secret']);
        $this->actingAs($this->actor('department-president', $own))->get('/admin/permissions')->assertRedirect('/admin/permissions/departments/'.$own->id);
        $this->get('/admin/permissions/departments/'.$own->id)->assertSee('Département autorisé')->assertDontSee('Département secret')->assertDontSee('Règles générales');
        $this->get('/admin/permissions/departments/'.$other->id)->assertForbidden();
        $this->put('/admin/permissions/global', $this->payload())->assertForbidden();
        $this->put('/admin/permissions/departments/'.$other->id, $this->payload(true))->assertForbidden();
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_president_saves_grant_deny_and_inherit_in_own_department(): void
    {
        $department = Department::factory()->create();
        [$roleId, $grantId] = $this->pair('volunteer', 'equipment.update');
        [, $denyId] = $this->pair('volunteer', 'equipment.view');
        [, $inheritId] = $this->pair('volunteer', 'vehicles.view');
        DepartmentRolePermission::create(['department_id' => $department->id, 'role_id' => $roleId, 'permission_id' => $inheritId, 'state' => 'deny']);
        $payload = $this->payload(true);
        $payload['cells'][$roleId][$grantId] = 'grant';
        $payload['cells'][$roleId][$denyId] = 'deny';
        $actor = $this->actor('department-president', $department);

        $this->actingAs($actor)->put('/admin/permissions/departments/'.$department->id, $payload)->assertRedirect('/admin/permissions/departments/'.$department->id)->assertSessionHasNoErrors();

        $this->assertDatabaseHas('department_role_permissions', ['department_id' => $department->id, 'role_id' => $roleId, 'permission_id' => $grantId, 'state' => 'grant']);
        $this->assertDatabaseHas('department_role_permissions', ['department_id' => $department->id, 'role_id' => $roleId, 'permission_id' => $denyId, 'state' => 'deny']);
        $this->assertDatabaseMissing('department_role_permissions', ['department_id' => $department->id, 'permission_id' => $inheritId]);
        $this->assertDatabaseHas('audit_events', ['event' => 'permissions.department.updated', 'actor_id' => $actor->id]);
        $this->assertContains(['role_id' => $roleId, 'permission_id' => $inheritId, 'before' => 'deny', 'after' => 'inherit'], AuditEvent::firstOrFail()->metadata['changes']);
    }

    #[DataProvider('forbiddenCells')]
    public function test_forged_technical_cells_are_rejected_without_writes(bool $departmental, string $slug, string $key): void
    {
        $department = Department::factory()->create();
        $payload = $this->payload($departmental);
        [$roleId, $permissionId] = $this->pair($slug, $key);
        $payload['cells'][$roleId][$permissionId] = $departmental ? 'grant' : '1';
        $before = DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->map(fn (object $row): array => (array) $row)->all();
        $actor = $this->actor($departmental ? 'department-president' : 'technical-admin', $departmental ? $department : null);

        $this->actingAs($actor)->put($departmental ? '/admin/permissions/departments/'.$department->id : '/admin/permissions/global', $payload)->assertSessionHasErrors('cells.'.$roleId.'.'.$permissionId);

        $this->assertSame($before, DB::table('role_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->map(fn (object $row): array => (array) $row)->all());
        $this->assertDatabaseCount('department_role_permissions', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public static function forbiddenCells(): array
    {
        return [
            'global technical permission' => [false, 'volunteer', 'technical.manage'],
            'department technical permission' => [true, 'volunteer', 'technical.manage'],
            'global technical role' => [false, 'technical-admin', 'equipment.view'],
            'department technical role' => [true, 'technical-admin', 'equipment.view'],
        ];
    }

    #[DataProvider('malformedPayloads')]
    public function test_malformed_or_incomplete_submissions_are_atomic(string $case, bool $departmental, string $error): void
    {
        $department = Department::factory()->create();
        $payload = $this->payload($departmental);
        [$roleId, $permissionId] = $this->pair('volunteer', 'equipment.update');
        $payload['cells'][$roleId][$permissionId] = $departmental ? 'grant' : '1';
        match ($case) {
            'state' => $payload['cells'][$roleId][$permissionId] = 'invalid',
            'unknown' => $payload['cells'][$roleId][999999] = $departmental ? 'grant' : '1',
            'role' => $payload['cells'][999999] = [$permissionId => $departmental ? 'grant' : '1'],
            'shape' => $payload['cells'] = 'invalid',
            'sentinel' => $payload['submission_complete'] = null,
            'manifest' => $payload['represented'][$roleId] = (string) $permissionId,
            'missing' => $payload['cells'][$roleId] = [],
        };
        $error = match ($case) {
            'state' => 'cells.'.$roleId.'.'.$permissionId,
            'unknown' => 'cells.'.$roleId.'.999999',
            'role' => 'cells.999999.'.$permissionId,
            default => $error,
        };

        $this->actingAs($this->actor('technical-admin'))->put($departmental ? '/admin/permissions/departments/'.$department->id : '/admin/permissions/global', $payload)->assertSessionHasErrors($error);

        $this->assertDatabaseMissing('role_permissions', ['role_id' => $roleId, 'permission_id' => $permissionId, 'granted' => true]);
        $this->assertDatabaseCount('department_role_permissions', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public static function malformedPayloads(): array
    {
        return [
            'global invalid boolean' => ['state', false, 'cells'],
            'department invalid state' => ['state', true, 'cells'],
            'unknown permission' => ['unknown', false, 'cells'],
            'unknown role' => ['role', true, 'cells'],
            'invalid shape' => ['shape', false, 'cells'],
            'global truncation' => ['sentinel', false, 'submission_complete'],
            'department truncation' => ['sentinel', true, 'submission_complete'],
            'global incomplete manifest' => ['manifest', false, 'represented'],
            'department incomplete manifest' => ['manifest', true, 'represented'],
            'department missing cells' => ['missing', true, 'cells'],
        ];
    }

    public function test_same_resolver_observes_global_and_department_changes_immediately(): void
    {
        $department = Department::factory()->create();
        $user = $this->actor('operations-deputy', $department);
        [$roleId, $permissionId] = $this->pair('operations-deputy', 'equipment.delete');
        $resolver = app(ScopedPermissionResolver::class);
        $context = AuthorizationContext::department($department);
        $this->assertFalse($resolver->allows($user, 'equipment.delete', $context));
        $payload = $this->payload();
        $payload['cells'][$roleId][$permissionId] = '1';

        $this->actingAs($this->actor('technical-admin'))->put('/admin/permissions/global', $payload)->assertSessionHasNoErrors();
        $this->assertTrue($resolver->allows($user, 'equipment.delete', $context));
        $payload = $this->payload(true);
        $payload['cells'][$roleId][$permissionId] = 'deny';
        $this->put('/admin/permissions/departments/'.$department->id, $payload)->assertSessionHasNoErrors();
        $this->assertFalse($resolver->allows($user, 'equipment.delete', $context));
        $payload['cells'][$roleId][$permissionId] = 'inherit';
        $this->put('/admin/permissions/departments/'.$department->id, $payload)->assertSessionHasNoErrors();
        $this->assertTrue($resolver->allows($user, 'equipment.delete', $context));
    }

    public function test_services_authorize_direct_calls_before_writes(): void
    {
        $department = Department::factory()->create();
        $actor = $this->actor('department-president', $department);
        try {
            app(PermissionMatrixService::class)->replaceGlobal($this->payload()['cells'], $actor);
            $this->fail('Global mutation must require technical authorization.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        try {
            app(PermissionMatrixService::class)->replaceDepartment(Department::factory()->create(), $this->payload(true)['cells'], $actor);
            $this->fail('A foreign department must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_direct_service_rejects_a_partial_matrix_without_writes(): void
    {
        try {
            app(PermissionMatrixService::class)->replaceGlobal([], $this->actor('technical-admin'));
            $this->fail('A partial matrix must not be written.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('cells', $exception->errors());
        }
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_audit_failure_rolls_back_all_matrix_writes(): void
    {
        $actor = $this->actor('technical-admin');
        $payload = $this->payload();
        [$roleId, $permissionId] = $this->pair('volunteer', 'equipment.update');
        $payload['cells'][$roleId][$permissionId] = '1';
        Event::listen('eloquent.creating: '.AuditEvent::class, function (): void {
            throw new \RuntimeException('Audit unavailable');
        });
        try {
            app(PermissionMatrixService::class)->replaceGlobal($payload['cells'], $actor);
            $this->fail('An audit failure must roll back the mutation.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.AuditEvent::class);
        }
        $this->assertDatabaseMissing('role_permissions', ['role_id' => $roleId, 'permission_id' => $permissionId, 'granted' => true]);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_matrix_renders_accessible_labels_and_escaped_names(): void
    {
        $department = Department::factory()->create(['name' => '<script>department</script>']);
        $this->actingAs($this->actor('technical-admin'))->get('/admin/permissions')->assertSee('<caption', false)->assertSee('scope="col"', false)->assertSee('scope="row"', false)->assertSee('Rechercher une permission')->assertSee('Rôle affiché')->assertSee('Administration technique')->assertSee('submission_complete', false);
        $this->get('/admin/permissions/departments/'.$department->id)->assertSee('&lt;script&gt;department&lt;/script&gt;', false)->assertDontSee('<script>department</script>', false)->assertSee('Héritée')->assertSee('Accordée')->assertSee('Refusée');
    }

    public function test_deactivated_or_expired_managers_cannot_update(): void
    {
        $department = Department::factory()->create();
        $actor = $this->actor('department-president', $department);
        $actor->roleAssignments()->update(['ends_at' => now()->subDay()]);
        $this->actingAs($actor)->put('/admin/permissions/departments/'.$department->id, $this->payload(true))->assertForbidden();
        $admin = $this->actor('technical-admin');
        $admin->forceFill(['deactivated_at' => now()])->save();
        $this->actingAs($admin)->put('/admin/permissions/global', $this->payload())->assertForbidden();
        $this->assertDatabaseCount('audit_events', 0);
    }

    #[DataProvider('matrixScopes')]
    public function test_rendered_form_preserves_every_role_and_state_without_javascript(bool $departmental): void
    {
        $department = Department::factory()->create();
        [$roleId, $permissionId] = $this->pair('volunteer', 'equipment.update');
        if ($departmental) {
            DepartmentRolePermission::create(['department_id' => $department->id, 'role_id' => $roleId, 'permission_id' => $permissionId, 'state' => 'grant']);
        }
        $url = $departmental ? '/admin/permissions/departments/'.$department->id : '/admin/permissions';
        $response = $this->actingAs($this->actor('technical-admin'))->get($url);
        $document = new \DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $controls = (new \DOMXPath($document))->query('//form[@id="matrix-form"]//input | //form[@id="matrix-form"]//select');
        $pairs = [];
        foreach ($controls as $control) {
            if ($control->getAttribute('type') === 'checkbox' && ! $control->hasAttribute('checked')) {
                continue;
            }
            $value = $control->getAttribute('value');
            if ($control->tagName === 'select') {
                foreach ($control->getElementsByTagName('option') as $option) {
                    if ($option->hasAttribute('selected')) {
                        $value = $option->getAttribute('value');
                    }
                }
            }
            $pairs[] = urlencode($control->getAttribute('name')).'='.urlencode($value);
        }
        $this->assertLessThan(1000, count($pairs));
        parse_str(implode('&', $pairs), $payload);

        $this->put($departmental ? $url : '/admin/permissions/global', $payload)->assertRedirect($url)->assertSessionHasNoErrors();

        $this->assertSame([], AuditEvent::firstOrFail()->metadata['changes']);
        if ($departmental) {
            $this->assertDatabaseHas('department_role_permissions', ['department_id' => $department->id, 'role_id' => $roleId, 'permission_id' => $permissionId, 'state' => 'grant']);
        }
    }

    public static function matrixScopes(): array
    {
        return ['global' => [false], 'department' => [true]];
    }

    #[DataProvider('incompleteGlobalSubmissions')]
    public function test_rejected_incomplete_global_form_retry_preserves_persisted_grants(string $case): void
    {
        [$roleId, $permissionId] = $this->pair('volunteer', 'equipment.view');
        [, $changedPermissionId] = $this->pair('volunteer', 'equipment.update');
        $payload = $this->payload();
        unset($payload['cells'][$roleId][$permissionId]);
        $payload['cells'][$roleId][$changedPermissionId] = '1';
        if ($case === 'missing marker') {
            unset($payload['submission_complete']);
        } elseif ($case === 'stale catalog') {
            Role::factory()->create(['allows_global' => false, 'allows_department' => true, 'allows_branch' => false]);
        } else {
            $payload['represented'][$roleId] = (string) $changedPermissionId;
        }
        $this->actingAs($this->actor('technical-admin'))->from('/admin/permissions')
            ->put('/admin/permissions/global', $payload)->assertRedirect('/admin/permissions')->assertSessionHasErrors();
        $this->assertDatabaseHas('role_permissions', ['role_id' => $roleId, 'permission_id' => $permissionId, 'granted' => true]);

        $document = new \DOMDocument;
        $document->loadHTML($this->get('/admin/permissions')->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $controls = (new \DOMXPath($document))->query('//form[@id="matrix-form"]//input');
        $pairs = [];
        foreach ($controls as $control) {
            if ($control->getAttribute('type') === 'checkbox' && ! $control->hasAttribute('checked')) {
                continue;
            }
            $pairs[] = urlencode($control->getAttribute('name')).'='.urlencode($control->getAttribute('value'));
        }
        parse_str(implode('&', $pairs), $retry);
        $this->put('/admin/permissions/global', $retry)->assertRedirect('/admin/permissions')->assertSessionHasNoErrors();

        $this->assertDatabaseHas('role_permissions', ['role_id' => $roleId, 'permission_id' => $permissionId, 'granted' => true]);
        $this->assertDatabaseMissing('role_permissions', ['role_id' => $roleId, 'permission_id' => $changedPermissionId, 'granted' => true]);
        $this->assertSame([], AuditEvent::firstOrFail()->metadata['changes']);
    }

    public static function incompleteGlobalSubmissions(): array
    {
        return [
            'missing marker' => ['missing marker'],
            'incomplete manifest' => ['incomplete manifest'],
            'stale catalog' => ['stale catalog'],
        ];
    }

    public function test_complete_rejected_global_form_preserves_legitimate_unchecked_values(): void
    {
        [$roleId, $permissionId] = $this->pair('volunteer', 'equipment.view');
        [, $invalidPermissionId] = $this->pair('volunteer', 'equipment.update');
        $payload = $this->payload();
        unset($payload['cells'][$roleId][$permissionId]);
        $payload['cells'][$roleId][$invalidPermissionId] = 'invalid';
        $this->actingAs($this->actor('technical-admin'))->from('/admin/permissions')
            ->put('/admin/permissions/global', $payload)->assertRedirect('/admin/permissions')->assertSessionHasErrors('cells.'.$roleId.'.'.$invalidPermissionId);

        $document = new \DOMDocument;
        $document->loadHTML($this->get('/admin/permissions')->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $checkbox = (new \DOMXPath($document))->query('//input[@id="cell-'.$roleId.'-'.$permissionId.'"]')->item(0);

        $this->assertFalse($checkbox->hasAttribute('checked'));
        $this->assertDatabaseHas('role_permissions', ['role_id' => $roleId, 'permission_id' => $permissionId, 'granted' => true]);
        $this->assertDatabaseCount('audit_events', 0);
    }

    private function actor(string $slug, ?Department $department = null): User
    {
        $user = User::factory()->create();
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('slug', $slug)->firstOrFail()->id, 'scope_type' => $department ? 'department' : 'global', 'scope_id' => $department?->id]);

        return $user;
    }

    private function pair(string $slug, string $key): array
    {
        return [Role::where('slug', $slug)->value('id'), Permission::where('key', $key)->value('id')];
    }

    private function payload(bool $departmental = false): array
    {
        $permissions = Permission::orderBy('id')->get()->filter(fn (Permission $permission): bool => PermissionCatalog::isDelegable($permission->key));
        $cells = [];
        $represented = [];
        foreach (Role::with('permissions')->where('allows_department', true)->orWhere('allows_branch', true)->orderBy('id')->get() as $role) {
            $represented[$role->id] = implode(',', $permissions->modelKeys());
            foreach ($permissions as $permission) {
                $cells[$role->id][$permission->id] = $departmental ? 'inherit' : (string) (int) (bool) $role->permissions->firstWhere('id', $permission->id)?->pivot->granted;
            }
        }

        return ['cells' => $cells, 'represented' => $represented, 'submission_complete' => '1'];
    }
}
