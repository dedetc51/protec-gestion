<?php

namespace Tests\Feature\Admin;

use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_requires_authentication_and_management_permission(): void
    {
        $this->get('/admin/organization')->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->get('/admin/organization')->assertForbidden();
    }

    public function test_admin_creates_normalized_departments_and_rejects_duplicates(): void
    {
        $actor = $this->actor('technical-admin');
        $this->actingAs($actor)->post('/admin/organization/departments', ['name' => ' Haute   Marne '])->assertRedirect();
        $this->post('/admin/organization/departments', ['name' => 'haute marne'])->assertSessionHasErrors('name');
        $this->assertDatabaseHas('departments', ['name' => 'Haute Marne']);
        $this->assertDatabaseHas('audit_events', ['event' => 'organization.department.created', 'actor_id' => $actor->id]);
    }

    public function test_president_sees_own_department_but_cannot_mutate_departments(): void
    {
        $own = Department::factory()->create(['name' => 'Visible']);
        $other = Department::factory()->create(['name' => 'Secret']);
        $this->actingAs($this->actor('department-president', $own))->get('/admin/organization')->assertSee('Visible')->assertDontSee('Secret');
        $this->post('/admin/organization/departments', ['name' => 'Autre'])->assertForbidden();
        $this->patch('/admin/organization/departments/'.$own->id, ['name' => 'Renommé'])->assertForbidden();
        $this->delete('/admin/organization/departments/'.$own->id)->assertForbidden();
        $this->post('/admin/organization/branches', ['department_id' => $other->id, 'name' => 'Interdit'])->assertForbidden();
        $this->assertDatabaseMissing('branches', ['name' => 'Interdit']);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_president_manages_own_branches_and_forged_foreign_ids_return_403(): void
    {
        $own = Department::factory()->create();
        $foreign = Branch::factory()->create();
        $this->actingAs($this->actor('department-president', $own))->post('/admin/organization/branches', ['department_id' => $own->id, 'name' => ' Centre   ville '])->assertRedirect();
        $branch = Branch::where('name', 'Centre ville')->firstOrFail();
        $this->patch('/admin/organization/branches/'.$branch->id, ['department_id' => $own->id, 'name' => 'Nord'])->assertRedirect();
        $this->patch('/admin/organization/branches/'.$branch->id, ['department_id' => $foreign->department_id, 'name' => 'Déplacée'])->assertForbidden();
        $this->patch('/admin/organization/branches/'.$foreign->id, ['department_id' => $own->id, 'name' => 'Intrusion'])->assertForbidden();
        $this->delete('/admin/organization/branches/'.$foreign->id)->assertForbidden();
        $this->delete('/admin/organization/branches/'.$branch->id)->assertRedirect();
        $this->assertNotNull($branch->fresh()->deactivated_at);
        $this->assertDatabaseHas('branches', ['id' => $branch->id, 'department_id' => $own->id, 'name' => 'Nord']);
        $this->assertSame(3, AuditEvent::count());
    }

    public function test_admin_deactivates_department_without_deleting_branches(): void
    {
        $department = Department::factory()->create();
        $branch = Branch::factory()->create(['department_id' => $department->id]);
        $this->actingAs($this->actor('technical-admin'))->delete('/admin/organization/departments/'.$department->id)->assertRedirect();
        $this->assertNotNull($department->fresh()->deactivated_at);
        $this->assertModelExists($branch);
        $this->assertDatabaseHas('audit_events', ['event' => 'organization.department.deactivated']);
    }

    private function actor(string $slug, ?Department $department = null): User
    {
        $user = User::factory()->create();
        RoleAssignment::create(['user_id' => $user->id, 'role_id' => Role::where('slug', $slug)->firstOrFail()->id, 'scope_type' => $department ? 'department' : 'global', 'scope_id' => $department?->id]);

        return $user;
    }

    public function test_malformed_organization_input_is_rejected_without_writes(): void
    {
        $this->actingAs($this->actor('technical-admin'))->post('/admin/organization/departments', ['name' => ['invalid']])->assertSessionHasErrors('name');
        $this->post('/admin/organization/branches', ['name' => 'Invalid', 'department_id' => [1]])->assertForbidden();
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_branch_names_are_unique_after_normalization_within_each_department(): void
    {
        $first = Department::factory()->create();
        $second = Department::factory()->create();
        $this->actingAs($this->actor('technical-admin'))->post('/admin/organization/branches', ['department_id' => $first->id, 'name' => ' Centre   Ville '])->assertRedirect();
        $this->post('/admin/organization/branches', ['department_id' => $first->id, 'name' => 'centre ville'])->assertSessionHasErrors(['name' => 'Ce nom d’antenne existe déjà dans ce département.']);
        $this->post('/admin/organization/branches', ['department_id' => $second->id, 'name' => 'Centre Ville'])->assertRedirect();
        $this->assertSame(2, Branch::where('name', 'Centre Ville')->count());
    }
}
