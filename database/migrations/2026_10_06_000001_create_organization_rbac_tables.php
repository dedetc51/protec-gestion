<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->unique(['department_id', 'name']);
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('allows_global')->default(false);
            $table->boolean('allows_department')->default(false);
            $table->boolean('allows_branch')->default(false);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('key')->unique();
            $table->timestamps();
        });

        Schema::create('memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at')->nullable()->index();
            $table->dateTime('ends_at')->nullable()->index();
            $table->unsignedTinyInteger('active_marker')->nullable()->storedAs('case when ends_at is null then 1 else null end');
            $table->timestamps();
            $table->index(['user_id', 'branch_id']);
            $table->unique(['user_id', 'branch_id', 'active_marker']);
        });

        Schema::create('role_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->enum('scope_type', ['global', 'department', 'branch']);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->dateTime('starts_at')->nullable()->index();
            $table->dateTime('ends_at')->nullable()->index();
            $table->unsignedBigInteger('active_scope_key')->nullable()->storedAs('case when ends_at is null then coalesce(scope_id, 0) else null end');
            $table->timestamps();
            $table->index(['scope_type', 'scope_id']);
            $table->unique(['user_id', 'role_id', 'scope_type', 'active_scope_key']);
        });

        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->boolean('granted')->default(true);
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('department_role_permissions', function (Blueprint $table): void {
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->enum('state', ['grant', 'deny']);
            $table->primary(['department_id', 'role_id', 'permission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_role_permissions');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('role_assignments');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('departments');
    }
};
