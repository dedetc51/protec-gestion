<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Department;
use App\Models\DepartmentRolePermission;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_navigation_and_placeholders_are_accessible(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
        RoleAssignment::factory()->for($admin)->create(['role_id' => Role::where('slug', 'technical-admin')->value('id')]);
        $this->actingAs($admin)->get('/dashboard')->assertOk()
            ->assertSeeInOrder(['Accueil', 'Matériel', 'Véhicules', 'Administration', 'Déconnexion'])
            ->assertSee('aria-controls="sidebar"', false)->assertSee('aria-current="page"', false)
            ->assertDontSee('Opérationnel');
        $this->actingAs($admin)->get('/equipment')->assertSee('Module prochainement disponible');
        $this->actingAs($admin)->get('/vehicles')->assertSee('Module prochainement disponible');
    }

    public function test_members_cannot_access_administration(): void
    {
        $member = User::factory()->create(['role' => 'member', 'must_change_password' => false]);
        $this->actingAs($member)->get('/admin')->assertForbidden();
    }

    public function test_mobile_menu_supports_focus_on_open_and_escape_focus_return(): void
    {
        $script = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("sidebar.querySelector('a')?.focus()", $script);
        $this->assertStringContainsString("event.key==='Escape'", $script);
        $this->assertStringContainsString('toggle.focus()', $script);
    }

    public function test_scoped_technical_admin_sees_all_administration_links_and_landing(): void
    {
        $admin = $this->actor('technical-admin');
        $department = Department::factory()->create();

        $this->actingAs($admin)->get('/dashboard')
            ->assertSee('href="'.route('admin.organization.index').'"', false)
            ->assertSee('href="'.route('admin.assignments.index').'"', false)
            ->assertSee('href="'.route('admin.permissions.index').'"', false)
            ->assertSee('href="'.route('admin.permissions.department', $department).'"', false);
        $this->get('/admin')->assertOk()->assertSee('Règles générales')->assertSee('Départements et antennes');
    }

    public function test_president_sees_only_own_scoped_links_and_can_open_landing(): void
    {
        $own = Department::factory()->create(['name' => 'Marne']);
        $other = Department::factory()->create(['name' => 'Département confidentiel']);

        $this->actingAs($this->actor('department-president', $own))->get('/dashboard')
            ->assertSee('href="'.route('admin.index').'"', false)
            ->assertSee('href="'.route('admin.organization.index').'"', false)
            ->assertSee('href="'.route('admin.assignments.index').'"', false)
            ->assertSee('href="'.route('admin.permissions.department', $own).'"', false)
            ->assertDontSee('href="'.route('admin.permissions.index').'"', false)
            ->assertDontSee('href="'.route('admin.permissions.department', $other).'"', false);
        $this->get('/admin')->assertOk()->assertSee('Marne')->assertDontSee('Département confidentiel')->assertDontSee('Règles générales');
    }

    public function test_volunteer_and_legacy_admin_without_assignment_see_no_administration(): void
    {
        foreach ([User::factory()->create(), User::factory()->create(['role' => 'admin'])] as $actor) {
            $this->actingAs($actor)->get('/dashboard')->assertDontSee('href="'.route('admin.index').'"', false)
                ->assertDontSee('href="'.route('admin.organization.index').'"', false)
                ->assertDontSee('href="'.route('admin.assignments.index').'"', false)
                ->assertDontSee('href="'.route('admin.permissions.index').'"', false);
            $this->get('/admin')->assertForbidden();
        }
    }

    public function test_navigation_hides_revoked_department_permissions(): void
    {
        $department = Department::factory()->create();
        $president = $this->actor('department-president', $department);
        DepartmentRolePermission::create(['department_id' => $department->id, 'role_id' => Role::where('slug', 'department-president')->value('id'), 'permission_id' => Permission::where('key', 'permissions.manage_department')->value('id'), 'state' => 'deny']);

        $this->actingAs($president)->get('/dashboard')->assertSee('href="'.route('admin.organization.index').'"', false)
            ->assertDontSee('href="'.route('admin.permissions.department', $department).'"', false);
    }

    public function test_active_branch_volunteer_sees_no_administration_links(): void
    {
        $user = User::factory()->create();
        $branch = Branch::factory()->create();
        Membership::factory()->for($user)->for($branch)->create();
        RoleAssignment::factory()->for($user)->create(['role_id' => Role::where('slug', 'volunteer')->value('id'), 'scope_type' => 'branch', 'scope_id' => $branch->id]);

        $this->actingAs($user)->get('/dashboard')->assertDontSee('aria-label="Administration de l’association"', false)
            ->assertDontSee('href="'.route('admin.index').'"', false);
        $this->get('/admin')->assertForbidden();
    }

    public function test_expired_responsibility_cannot_open_administration_landing(): void
    {
        $this->freezeTime();
        $president = $this->actor('department-president', Department::factory()->create());
        $president->roleAssignments()->update(['ends_at' => now()]);

        $this->actingAs($president)->get('/admin')->assertForbidden();
        $this->get('/dashboard')->assertDontSee('href="'.route('admin.index').'"', false);
    }

    private function actor(string $slug, ?Department $department = null): User
    {
        $user = User::factory()->create();
        RoleAssignment::factory()->for($user)->create(['role_id' => Role::where('slug', $slug)->value('id'), 'scope_type' => $department ? 'department' : 'global', 'scope_id' => $department?->id]);

        return $user;
    }
}
