<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_and_guests_are_sent_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_login_normalizes_email_rotates_session_and_logout_invalidates_it(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.test', 'password' => Hash::make('Secret-password-42')]);
        $old = session()->getId();

        $this->post('/login', ['email' => ' ADMIN@EXAMPLE.TEST ', 'password' => 'Secret-password-42'])
            ->assertRedirect('/change-initial-password');
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($old, session()->getId());

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_invalid_login_is_generic_and_deactivated_users_are_rejected(): void
    {
        User::factory()->create(['email' => 'known@example.test', 'password' => Hash::make('Correct-password-42'), 'deactivated_at' => now()]);

        foreach (['known@example.test', 'missing@example.test'] as $email) {
            $this->post('/login', ['email' => $email, 'password' => 'wrong'])
                ->assertSessionHasErrors(['email' => 'Identifiants incorrects.']);
        }
        $this->assertGuest();
    }

    public function test_public_account_routes_do_not_exist(): void
    {
        $this->get('/register')->assertNotFound();
        $this->get('/forgot-password')->assertNotFound();
        $this->get('/reset-password/test')->assertNotFound();
    }

    public function test_failed_login_limit_is_scoped_to_normalized_email_and_ip(): void
    {
        User::factory()->create(['email' => 'first@example.test']);
        foreach (range(1, 5) as $_) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])->post('/login', ['email' => ' FIRST@EXAMPLE.TEST ', 'password' => 'wrong']);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])->post('/login', ['email' => 'first@example.test', 'password' => 'wrong'])->assertStatus(429);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])->post('/login', ['email' => 'other@example.test', 'password' => 'wrong'])->assertStatus(302);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2'])->post('/login', ['email' => 'first@example.test', 'password' => 'wrong'])->assertStatus(302);
    }

    public function test_email_is_normalized_when_written(): void
    {
        $user = User::factory()->create(['email' => ' Mixed@Example.TEST ']);
        $this->assertSame('mixed@example.test', $user->email);
    }
}
