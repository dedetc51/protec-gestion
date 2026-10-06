<?php

namespace Tests\Feature;

use App\Authorization\AuthorizationContext;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EnsureInitialAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_one_admin_and_is_idempotent_without_printing_password(): void
    {
        $password = 'Temporary-password-42!';
        $_ENV['INITIAL_ADMIN_PASSWORD'] = $password;
        $_SERVER['INITIAL_ADMIN_PASSWORD'] = $password;
        $this->artisan('protec:ensure-initial-admin', ['--name' => 'Admin', '--email' => ' ADMIN@example.test '])
            ->assertExitCode(0)->doesntExpectOutputToContain($password);
        $this->artisan('protec:ensure-initial-admin', ['--name' => 'Admin', '--email' => 'admin@example.test'])
            ->assertExitCode(0);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['email' => 'admin@example.test', 'role' => 'admin', 'must_change_password' => true]);
        $this->assertTrue(User::first()->canIn('technical.manage', AuthorizationContext::global()));
        $this->assertDatabaseCount('role_assignments', 1);
        $this->assertDatabaseHas('audit_events', ['event' => 'members.role.assigned', 'actor_id' => null]);
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_existing_admin_gets_a_new_assignment_without_reviving_ended_history(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));
        $user = User::factory()->create(['email' => 'admin@example.test', 'role' => 'admin']);
        $ended = RoleAssignment::factory()->create(['user_id' => $user->id, 'role_id' => Role::where('slug', 'technical-admin')->firstOrFail()->id, 'scope_type' => 'global', 'scope_id' => null, 'ends_at' => now()->subDay()]);
        $this->setInitialPassword('Temporary-password-42!');

        $this->artisan('protec:ensure-initial-admin', ['--name' => 'Admin', '--email' => $user->email])->assertExitCode(0);
        $this->artisan('protec:ensure-initial-admin', ['--name' => 'Admin', '--email' => $user->email])->assertExitCode(0);

        $this->assertTrue($user->canIn('technical.manage', AuthorizationContext::global()));
        $this->assertTrue($ended->fresh()->ends_at->eq(now()->subDay()));
        $this->assertDatabaseCount('role_assignments', 2);
    }

    public function test_idempotent_run_with_another_password_preserves_the_hash(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.test',
            'role' => 'admin',
            'password' => Hash::make('Original-password-42!'),
        ]);
        $originalHash = $user->password;
        $this->setInitialPassword('Different-password-84!');

        $this->artisan('protec:ensure-initial-admin', ['--name' => 'Admin', '--email' => 'ADMIN@example.test'])
            ->assertExitCode(0);

        $this->assertSame($originalHash, $user->fresh()->password);
    }

    public function test_command_rejects_missing_inputs(): void
    {
        $this->setInitialPassword(null);
        unset($_ENV['INITIAL_ADMIN_NAME'], $_SERVER['INITIAL_ADMIN_NAME'], $_ENV['INITIAL_ADMIN_EMAIL'], $_SERVER['INITIAL_ADMIN_EMAIL']);

        $this->artisan('protec:ensure-initial-admin')->assertExitCode(1);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_command_rejects_invalid_email(): void
    {
        $this->setInitialPassword('Temporary-password-42!');

        $this->artisan('protec:ensure-initial-admin', ['--name' => 'Admin', '--email' => 'not-an-email'])
            ->assertExitCode(1);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_command_rejects_weak_password(): void
    {
        $this->setInitialPassword('weak');

        $this->artisan('protec:ensure-initial-admin', ['--name' => 'Admin', '--email' => 'admin@example.test'])
            ->assertExitCode(1);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_command_rejects_second_admin(): void
    {
        User::factory()->create(['email' => 'existing@example.test', 'role' => 'admin']);
        $this->setInitialPassword('Temporary-password-42!');
        $this->artisan('protec:ensure-initial-admin', ['--name' => 'Other', '--email' => 'other@example.test'])->assertExitCode(1);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_command_refuses_case_insensitive_collision_with_a_member(): void
    {
        User::factory()->create(['email' => 'member@example.test', 'role' => 'member']);
        $this->setInitialPassword('Temporary-password-42!');

        $this->artisan('protec:ensure-initial-admin', ['--name' => 'Admin', '--email' => ' MEMBER@EXAMPLE.TEST '])->assertExitCode(1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['email' => 'member@example.test', 'role' => 'member']);
    }

    private function setInitialPassword(?string $password): void
    {
        if ($password === null) {
            unset($_ENV['INITIAL_ADMIN_PASSWORD'], $_SERVER['INITIAL_ADMIN_PASSWORD']);

            return;
        }

        $_ENV['INITIAL_ADMIN_PASSWORD'] = $password;
        $_SERVER['INITIAL_ADMIN_PASSWORD'] = $password;
    }

    public function test_bootstrap_fills_the_current_gap_without_overlapping_a_future_admin_assignment(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 12:00:00'));
        $user = User::factory()->create(['email' => 'admin@example.test', 'role' => 'admin']);
        $future = RoleAssignment::factory()->create(['user_id' => $user->id, 'role_id' => Role::where('slug', 'technical-admin')->firstOrFail()->id, 'scope_type' => 'global', 'scope_id' => null, 'starts_at' => '2026-10-07 12:00:00']);
        $this->setInitialPassword('Temporary-password-42!');
        $this->artisan('protec:ensure-initial-admin', ['--name' => 'Admin', '--email' => $user->email])->assertExitCode(0);
        $this->assertTrue($user->canIn('technical.manage', AuthorizationContext::global()));
        $this->assertDatabaseHas('role_assignments', ['user_id' => $user->id, 'starts_at' => '2026-10-06 12:00:00', 'ends_at' => '2026-10-07 12:00:00']);
        $this->assertSame('2026-10-07 12:00:00', $future->fresh()->starts_at->toDateTimeString());
        $this->assertDatabaseCount('role_assignments', 2);
    }
}
