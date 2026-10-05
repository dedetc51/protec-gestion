<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_navigation_and_placeholders_are_accessible(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'must_change_password' => false]);
        $this->actingAs($admin)->get('/dashboard')->assertOk()
            ->assertSeeInOrder(['Accueil', 'Matériel', 'Véhicules', 'Administration', 'Déconnexion'])
            ->assertSee('aria-controls="sidebar"', false)->assertSee('aria-current="page"', false);
        $this->actingAs($admin)->get('/equipment')->assertSee('Module prochainement disponible');
        $this->actingAs($admin)->get('/vehicles')->assertSee('Module prochainement disponible');
    }

    public function test_members_cannot_access_administration(): void
    {
        $member = User::factory()->create(['role' => 'member', 'must_change_password' => false]);
        $this->actingAs($member)->get('/admin')->assertForbidden();
    }
}
