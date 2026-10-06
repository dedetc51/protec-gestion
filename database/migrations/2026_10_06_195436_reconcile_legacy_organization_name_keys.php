<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('LOCK TABLE departments, branches IN SHARE ROW EXCLUSIVE MODE');
            }

            foreach (['departments', 'branches'] as $tableName) {
                $this->assertNoCollisions($tableName);
            }

            foreach (['departments', 'branches'] as $tableName) {
                // Clear the derived cache first so swapped stale keys cannot conflict.
                DB::table($tableName)->update(['name_key' => null]);
                DB::table($tableName)->orderBy('id')->chunkById(100, function (Collection $rows) use ($tableName): void {
                    foreach ($rows as $row) {
                        DB::table($tableName)->where('id', $row->id)->update(['name_key' => $this->key($row->name)]);
                    }
                });
            }

            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared(<<<'SQL'
CREATE FUNCTION invalidate_legacy_organization_name_key() RETURNS trigger AS $$
BEGIN
    IF NEW.name IS DISTINCT FROM OLD.name AND NEW.name_key IS NOT DISTINCT FROM OLD.name_key THEN
        NEW.name_key := NULL;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL);
            }

            foreach (['departments', 'branches'] as $tableName) {
                if (DB::getDriverName() === 'pgsql') {
                    DB::unprepared("CREATE TRIGGER {$tableName}_invalidate_legacy_name_key BEFORE UPDATE OF name ON {$tableName} FOR EACH ROW EXECUTE FUNCTION invalidate_legacy_organization_name_key()");
                } else {
                    DB::unprepared("CREATE TRIGGER {$tableName}_invalidate_legacy_name_key AFTER UPDATE OF name ON {$tableName} WHEN NEW.name <> OLD.name AND NEW.name_key IS NOT NULL AND NEW.name_key IS OLD.name_key BEGIN UPDATE {$tableName} SET name_key = NULL WHERE id = NEW.id; END");
                }
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            foreach (['branches', 'departments'] as $tableName) {
                DB::statement(DB::getDriverName() === 'pgsql'
                    ? "DROP TRIGGER {$tableName}_invalidate_legacy_name_key ON {$tableName}"
                    : "DROP TRIGGER {$tableName}_invalidate_legacy_name_key");
            }

            if (DB::getDriverName() === 'pgsql') {
                DB::statement('DROP FUNCTION invalidate_legacy_organization_name_key()');
            }
        });
    }

    private function assertNoCollisions(string $tableName): void
    {
        $seen = [];
        DB::table($tableName)->orderBy('id')->chunkById(100, function (Collection $rows) use ($tableName, &$seen): void {
            foreach ($rows as $row) {
                $key = ($row->department_id ?? 0).'|'.$this->key($row->name);
                if (isset($seen[$key])) {
                    throw new RuntimeException("Unicode name collision in $tableName between records ".$seen[$key].' and '.$row->id.'. Resolve their names before retrying.');
                }
                $seen[$key] = $row->id;
            }
        });
    }

    private function key(string $name): string
    {
        return mb_strtolower(Normalizer::normalize(preg_replace('/\s+/u', ' ', trim($name)), Normalizer::FORM_C), 'UTF-8');
    }
};
