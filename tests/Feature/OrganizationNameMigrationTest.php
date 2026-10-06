<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrganizationNameMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_backfills_unicode_keys_and_rollback_preserves_names_and_deactivation(): void
    {
        $migration = require database_path('migrations/2026_10_06_144556_add_unicode_organization_name_keys.php');
        $migration->down();
        $id = DB::table('departments')->insertGetId(['name' => 'École', 'deactivated_at' => '2026-10-01 12:00:00', 'created_at' => now(), 'updated_at' => now()]);
        $branchId = DB::table('branches')->insertGetId(['department_id' => $id, 'name' => 'Équipe', 'created_at' => now(), 'updated_at' => now()]);

        $migration->up();

        $this->assertTrue(Schema::hasColumn('departments', 'name_key'));
        $this->assertDatabaseHas('departments', ['id' => $id, 'name' => 'École', 'name_key' => 'école', 'deactivated_at' => '2026-10-01 12:00:00']);
        $this->assertDatabaseHas('branches', ['id' => $branchId, 'name_key' => 'équipe']);
        $migration->down();
        $this->assertFalse(Schema::hasColumn('departments', 'name_key'));
        $this->assertDatabaseHas('departments', ['id' => $id, 'name' => 'École', 'deactivated_at' => '2026-10-01 12:00:00']);
        $this->assertDatabaseHas('branches', ['id' => $branchId, 'name' => 'Équipe']);
        $migration->up();
    }

    public function test_upgrade_reports_legacy_unicode_collisions_before_schema_changes(): void
    {
        $migration = require database_path('migrations/2026_10_06_144556_add_unicode_organization_name_keys.php');
        $migration->down();
        foreach (['École', 'école'] as $name) {
            DB::table('departments')->insert(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
        try {
            $migration->up();
            $this->fail('Legacy collisions must be reported without merging organization records.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('departments', $exception->getMessage());
        }
        $this->assertFalse(Schema::hasColumn('departments', 'name_key'));
        $this->assertSame(2, DB::table('departments')->whereIn('name', ['École', 'école'])->count());
        DB::table('departments')->where('name', 'école')->update(['name' => 'Autre école']);
        $migration->up();
    }
}
