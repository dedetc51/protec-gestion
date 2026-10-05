<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InitialPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_temporary_user_is_confined_until_password_is_changed(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);
        foreach (['/dashboard', '/equipment', '/vehicles', '/admin'] as $uri) {
            $this->actingAs($user)->get($uri)->assertRedirect('/change-initial-password');
        }
        $this->actingAs($user)->get('/change-initial-password')->assertOk();
    }

    public function test_current_password_and_strong_confirmation_are_required_then_flag_is_cleared(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Old-password-42'), 'must_change_password' => true]);
        $this->actingAs($user)->patch('/change-initial-password', [
            'current_password' => 'wrong', 'password' => 'weak', 'password_confirmation' => 'different',
        ])->assertSessionHasErrors(['current_password', 'password']);

        $this->actingAs($user)->patch('/change-initial-password', [
            'current_password' => 'Old-password-42', 'password' => 'New-strong-password-84!', 'password_confirmation' => 'New-strong-password-84!',
        ])->assertRedirect('/dashboard');
        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check('New-strong-password-84!', $user->fresh()->password));
    }
}
