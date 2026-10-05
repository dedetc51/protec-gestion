<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnsureInitialAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_one_admin_and_is_idempotent_without_printing_password(): void
    {
        $password = 'Temporary-password-42!';
        $this->artisan('protec:ensure-initial-admin', ['--name' => 'Admin', '--email' => ' ADMIN@example.test ', '--password' => $password])
            ->assertExitCode(0)->doesntExpectOutputToContain($password);
        $this->artisan('protec:ensure-initial-admin', ['--name' => 'Admin', '--email' => 'admin@example.test', '--password' => $password])
            ->assertExitCode(0);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['email' => 'admin@example.test', 'role' => 'admin', 'must_change_password' => true]);
    }

    public function test_command_rejects_weak_or_second_admin(): void
    {
        User::factory()->create(['email' => 'existing@example.test', 'role' => 'admin']);
        $this->artisan('protec:ensure-initial-admin', ['--name' => 'Other', '--email' => 'other@example.test', '--password' => 'Temporary-password-42!'])->assertExitCode(1);
        $this->artisan('protec:ensure-initial-admin', ['--name' => 'Other', '--email' => 'other@example.test', '--password' => 'weak'])->assertExitCode(1);
    }
}
