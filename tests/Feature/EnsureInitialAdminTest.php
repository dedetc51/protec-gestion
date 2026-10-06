<?php

namespace Tests\Feature;

use App\Models\User;
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
}
