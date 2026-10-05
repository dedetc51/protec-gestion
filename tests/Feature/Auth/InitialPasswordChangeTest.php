<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $this->assertDatabaseHas('audit_events', ['actor_id' => $user->id, 'event' => 'auth.password.initial_changed']);
    }

    public function test_new_password_must_differ_and_a_compromised_password_is_rejected(): void
    {
        $user = User::factory()->create(['password' => Hash::make('Same-password-42!'), 'must_change_password' => true]);

        $this->actingAs($user)->patch('/change-initial-password', [
            'current_password' => 'Same-password-42!', 'password' => 'Same-password-42!', 'password_confirmation' => 'Same-password-42!',
        ])->assertSessionHasErrors('password');
        $this->assertTrue($user->fresh()->must_change_password);

        $this->actingAs($user)->patch('/change-initial-password', [
            'current_password' => 'Same-password-42!', 'password' => 'Password1234!', 'password_confirmation' => 'Password1234!',
        ])->assertSessionHasErrors('password');
        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_password_change_rotates_session_and_revokes_other_database_sessions(): void
    {
        config(['session.driver' => 'database']);
        $user = User::factory()->create(['password' => Hash::make('Old-password-42!'), 'must_change_password' => true]);
        DB::table('sessions')->insert([
            'id' => 'another-session', 'user_id' => $user->id, 'ip_address' => '192.0.2.10',
            'user_agent' => 'test', 'payload' => '', 'last_activity' => time(),
        ]);
        $oldSessionId = session()->getId();

        $this->actingAs($user)->patch('/change-initial-password', [
            'current_password' => 'Old-password-42!', 'password' => 'New-strong-password-84!', 'password_confirmation' => 'New-strong-password-84!',
        ])->assertRedirect('/dashboard');

        $this->assertNotSame($oldSessionId, session()->getId());
        $this->assertDatabaseMissing('sessions', ['id' => 'another-session']);
    }
}
