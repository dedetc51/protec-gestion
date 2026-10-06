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
}
