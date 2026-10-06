<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrganizationNameMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_backfills_unicode_keys_and_rollback_preserves_names_and_deactivation(): void
    {
        $compatibility = require database_path('migrations/2026_10_06_195436_reconcile_legacy_organization_name_keys.php');
        $compatibility->down();
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
        $compatibility->up();
    }

    #[DataProvider('organizationTables')]
    public function test_upgrade_reports_legacy_unicode_collisions_before_schema_changes(string $tableName): void
    {
        $compatibility = require database_path('migrations/2026_10_06_195436_reconcile_legacy_organization_name_keys.php');
        $compatibility->down();
        $migration = require database_path('migrations/2026_10_06_144556_add_unicode_organization_name_keys.php');
        $migration->down();
        $scope = $tableName === 'branches' ? ['department_id' => DB::table('departments')->insertGetId(['name' => 'Parent'])] : [];
        // Legacy lower(name) indexes distinguish NFC/NFD even on Unicode-aware PostgreSQL.
        $names = ['École', "E\u{0301}cole"];
        foreach ($names as $name) {
            DB::table($tableName)->insert([...$scope, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
        try {
            $migration->up();
            $this->fail('Legacy collisions must be reported without merging organization records.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString($tableName, $exception->getMessage());
        }
        $this->assertFalse(Schema::hasColumn('departments', 'name_key'));
        $this->assertFalse(Schema::hasColumn('branches', 'name_key'));
        $this->assertSame(2, DB::table($tableName)->whereIn('name', $names)->count());
        DB::table($tableName)->where('name', $names[1])->update(['name' => 'Autre école']);
        $migration->up();
        $compatibility->up();
    }

    public static function organizationTables(): array
    {
        return ['departments' => ['departments'], 'branches' => ['branches']];
    }

    public function test_reupgrade_reconciles_stale_keys_and_rollback_preserves_organization_data(): void
    {
        $migration = require database_path('migrations/2026_10_06_195436_reconcile_legacy_organization_name_keys.php');
        $migration->down();
        $departmentId = DB::table('departments')->insertGetId(['name' => 'Alpha', 'name_key' => 'alpha', 'deactivated_at' => '2026-10-01 12:00:00', 'created_at' => now(), 'updated_at' => now()]);
        $branchId = DB::table('branches')->insertGetId(['department_id' => $departmentId, 'name' => 'Alpha', 'name_key' => 'alpha', 'deactivated_at' => '2026-10-01 12:00:00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('departments')->where('id', $departmentId)->update(['name' => 'Beta']);
        DB::table('branches')->where('id', $branchId)->update(['name' => 'Beta']);
        $this->assertDatabaseHas('departments', ['id' => $departmentId, 'name' => 'Beta', 'name_key' => 'alpha']);
        $this->assertDatabaseHas('branches', ['id' => $branchId, 'name' => 'Beta', 'name_key' => 'alpha']);
        $swappedDepartmentId = DB::table('departments')->insertGetId(['name' => 'Alpha', 'name_key' => 'beta']);
        $swappedBranchId = DB::table('branches')->insertGetId(['department_id' => $departmentId, 'name' => 'Alpha', 'name_key' => 'beta']);
        $department = (array) DB::table('departments')->find($departmentId);
        $branch = (array) DB::table('branches')->find($branchId);

        $migration->up();

        $department['name_key'] = 'beta';
        $branch['name_key'] = 'beta';
        $this->assertSame($department, (array) DB::table('departments')->find($departmentId));
        $this->assertSame($branch, (array) DB::table('branches')->find($branchId));
        $this->assertDatabaseHas('departments', ['id' => $swappedDepartmentId, 'name' => 'Alpha', 'name_key' => 'alpha']);
        $this->assertDatabaseHas('branches', ['id' => $swappedBranchId, 'name' => 'Alpha', 'name_key' => 'alpha']);
        $migration->down();
        $this->assertSame($department, (array) DB::table('departments')->find($departmentId));
        $this->assertSame($branch, (array) DB::table('branches')->find($branchId));
        $migration->up();
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_reconciliation_preflights_both_tables_before_changing_any_keys(): void
    {
        $migration = require database_path('migrations/2026_10_06_195436_reconcile_legacy_organization_name_keys.php');
        $migration->down();
        $departmentId = DB::table('departments')->insertGetId(['name' => 'Beta', 'name_key' => 'alpha']);
        $branchId = DB::table('branches')->insertGetId(['department_id' => $departmentId, 'name' => 'École', 'name_key' => 'école']);
        $collisionId = DB::table('branches')->insertGetId(['department_id' => $departmentId, 'name' => "E\u{0301}cole", 'name_key' => null]);

        try {
            $migration->up();
            $this->fail('Reconciliation must report collisions without changing cached keys.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('branches', $exception->getMessage());
        }

        $this->assertDatabaseHas('departments', ['id' => $departmentId, 'name_key' => 'alpha']);
        $this->assertDatabaseHas('branches', ['id' => $branchId, 'name_key' => 'école']);
        $this->assertDatabaseHas('branches', ['id' => $collisionId, 'name_key' => null]);
        DB::table('branches')->where('id', $collisionId)->update(['name' => 'Autre école']);
        $migration->up();
        $this->assertDatabaseHas('departments', ['id' => $departmentId, 'name_key' => 'beta']);
        $this->assertDatabaseHas('branches', ['id' => $collisionId, 'name_key' => 'autre école']);
    }
}
