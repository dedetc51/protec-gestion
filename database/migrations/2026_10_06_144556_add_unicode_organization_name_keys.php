<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (['departments', 'branches'] as $tableName) {
                $this->assertNoCollisions($tableName);
            }
            foreach (['departments', 'branches'] as $tableName) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->string('name_key', 512)->nullable();
                });
                DB::table($tableName)->orderBy('id')->chunkById(100, function (Collection $rows) use ($tableName): void {
                    foreach ($rows as $row) {
                        DB::table($tableName)->where('id', $row->id)->update(['name_key' => $this->key($row->name)]);
                    }
                });
            }
            Schema::table('departments', function (Blueprint $table): void {
                $table->unique('name_key', 'departments_unicode_name_unique');
            });
            Schema::table('branches', function (Blueprint $table): void {
                $table->unique(['department_id', 'name_key'], 'branches_unicode_name_unique');
            });
            DB::statement('DROP INDEX branches_normalized_name_unique');
            DB::statement('DROP INDEX departments_normalized_name_unique');
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement('CREATE UNIQUE INDEX departments_normalized_name_unique ON departments (lower(name))');
            DB::statement('CREATE UNIQUE INDEX branches_normalized_name_unique ON branches (department_id, lower(name))');
            Schema::table('branches', function (Blueprint $table): void {
                $table->dropUnique('branches_unicode_name_unique');
                $table->dropColumn('name_key');
            });
            Schema::table('departments', function (Blueprint $table): void {
                $table->dropUnique('departments_unicode_name_unique');
                $table->dropColumn('name_key');
            });
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
