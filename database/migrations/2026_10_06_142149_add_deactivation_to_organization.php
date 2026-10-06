<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['departments', 'branches'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dateTime('deactivated_at')->nullable();
            });
        }
        DB::statement('CREATE UNIQUE INDEX departments_normalized_name_unique ON departments (lower(name))');
        DB::statement('CREATE UNIQUE INDEX branches_normalized_name_unique ON branches (department_id, lower(name))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX branches_normalized_name_unique');
        DB::statement('DROP INDEX departments_normalized_name_unique');
        foreach (['branches', 'departments'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('deactivated_at');
            });
        }
    }
};
