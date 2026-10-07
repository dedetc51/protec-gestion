<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrganizationMigrationLifecycleTest extends TestCase
{
    public function test_repeated_fresh_migrations_restore_legacy_rename_protection_and_remain_reversible(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();

        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();

        $departmentId = DB::table('departments')->insertGetId(['name' => 'École', 'name_key' => 'école', 'deactivated_at' => '2026-10-01 12:00:00']);
        $branchId = DB::table('branches')->insertGetId(['department_id' => $departmentId, 'name' => 'Équipe', 'name_key' => 'équipe']);
        DB::table('departments')->where('id', $departmentId)->update(['name' => 'Nouvelle école']);
        DB::table('branches')->where('id', $branchId)->update(['name' => 'Nouvelle équipe']);
        $this->assertDatabaseHas('departments', ['id' => $departmentId, 'name' => 'Nouvelle école', 'name_key' => null, 'deactivated_at' => '2026-10-01 12:00:00']);
        $this->assertDatabaseHas('branches', ['id' => $branchId, 'name' => 'Nouvelle équipe', 'name_key' => null]);

        $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertSuccessful();
        $this->assertDatabaseHas('departments', ['id' => $departmentId, 'name' => 'Nouvelle école', 'name_key' => null, 'deactivated_at' => '2026-10-01 12:00:00']);
        $this->assertDatabaseHas('branches', ['id' => $branchId, 'name' => 'Nouvelle équipe', 'name_key' => null]);

        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertDatabaseHas('departments', ['id' => $departmentId, 'name_key' => 'nouvelle école']);
        $this->assertDatabaseHas('branches', ['id' => $branchId, 'name_key' => 'nouvelle équipe']);
        DB::table('departments')->where('id', $departmentId)->update(['name' => 'Autre école']);
        DB::table('branches')->where('id', $branchId)->update(['name' => 'Autre équipe']);
        $this->assertDatabaseHas('departments', ['id' => $departmentId, 'name_key' => null]);
        $this->assertDatabaseHas('branches', ['id' => $branchId, 'name_key' => null]);

        DB::table('departments')->where('id', $departmentId)->delete();
    }
}
