<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertIntegrityTriggerSupport();

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
            $table->timestamps();
            $table->index(['user_id', 'branch_id', 'starts_at', 'ends_at']);
        });

        Schema::create('role_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->enum('scope_type', ['global', 'department', 'branch']);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->dateTime('starts_at')->nullable()->index();
            $table->dateTime('ends_at')->nullable()->index();
            $table->timestamps();
            $table->index(['scope_type', 'scope_id']);
            $table->index(['user_id', 'role_id', 'scope_type', 'scope_id', 'starts_at', 'ends_at']);
        });

        Schema::create('role_permissions', function (Blueprint $table): void {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->boolean('granted')->default(true);
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('department_role_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->enum('state', ['grant', 'deny']);
            $table->unique(['department_id', 'role_id', 'permission_id']);
        });

        $this->createIntegrityTriggers();
    }

    public function down(): void
    {
        $this->dropIntegrityTriggers();

        Schema::dropIfExists('department_role_permissions');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('role_assignments');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('departments');
    }

    private function createIntegrityTriggers(): void
    {
        match (DB::connection()->getDriverName()) {
            'sqlite' => $this->createSqliteIntegrityTriggers(),
            'pgsql' => $this->createPostgresIntegrityTriggers(),
            default => throw new RuntimeException('Organization RBAC integrity triggers support SQLite and PostgreSQL.'),
        };
    }

    private function assertIntegrityTriggerSupport(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['sqlite', 'pgsql'], true)) {
            throw new RuntimeException('Organization RBAC integrity triggers support SQLite and PostgreSQL.');
        }
    }

    private function dropIntegrityTriggers(): void
    {
        match (DB::connection()->getDriverName()) {
            'sqlite' => DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS memberships_reject_overlap_insert;
                DROP TRIGGER IF EXISTS memberships_reject_overlap_update;
                DROP TRIGGER IF EXISTS role_assignments_reject_invalid_insert;
                DROP TRIGGER IF EXISTS role_assignments_reject_invalid_update;
                DROP TRIGGER IF EXISTS roles_reject_unsupported_scope_update;
                SQL),
            'pgsql' => DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS memberships_reject_overlap ON memberships;
                DROP TRIGGER IF EXISTS role_assignments_reject_invalid ON role_assignments;
                DROP TRIGGER IF EXISTS roles_reject_unsupported_scope ON roles;
                DROP FUNCTION IF EXISTS protect_membership_overlap();
                DROP FUNCTION IF EXISTS protect_role_assignment();
                DROP FUNCTION IF EXISTS protect_role_scope_flags();
                SQL),
            default => null,
        };
    }

    private function createSqliteIntegrityTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER memberships_reject_overlap_insert
            BEFORE INSERT ON memberships
            WHEN EXISTS (
                SELECT 1 FROM memberships AS existing
                WHERE existing.user_id = NEW.user_id
                  AND existing.branch_id = NEW.branch_id
                  AND (existing.starts_at IS NULL OR NEW.ends_at IS NULL OR existing.starts_at < NEW.ends_at)
                  AND (NEW.starts_at IS NULL OR existing.ends_at IS NULL OR NEW.starts_at < existing.ends_at)
            )
            BEGIN
                SELECT RAISE(ABORT, 'membership effective windows overlap');
            END;

            CREATE TRIGGER memberships_reject_overlap_update
            BEFORE UPDATE ON memberships
            WHEN EXISTS (
                SELECT 1 FROM memberships AS existing
                WHERE existing.id <> NEW.id
                  AND existing.user_id = NEW.user_id
                  AND existing.branch_id = NEW.branch_id
                  AND (existing.starts_at IS NULL OR NEW.ends_at IS NULL OR existing.starts_at < NEW.ends_at)
                  AND (NEW.starts_at IS NULL OR existing.ends_at IS NULL OR NEW.starts_at < existing.ends_at)
            )
            BEGIN
                SELECT RAISE(ABORT, 'membership effective windows overlap');
            END;

            CREATE TRIGGER role_assignments_reject_invalid_insert
            BEFORE INSERT ON role_assignments
            WHEN NOT (
                    (NEW.scope_type = 'global' AND NEW.scope_id IS NULL)
                    OR (NEW.scope_type IN ('department', 'branch') AND NEW.scope_id IS NOT NULL)
                )
                OR NOT EXISTS (
                    SELECT 1 FROM roles AS role
                    WHERE role.id = NEW.role_id
                      AND ((NEW.scope_type = 'global' AND role.allows_global = 1)
                        OR (NEW.scope_type = 'department' AND role.allows_department = 1 AND EXISTS (SELECT 1 FROM departments WHERE id = NEW.scope_id))
                        OR (NEW.scope_type = 'branch' AND role.allows_branch = 1 AND EXISTS (SELECT 1 FROM branches WHERE id = NEW.scope_id)))
                )
                OR EXISTS (
                    SELECT 1 FROM role_assignments AS existing
                    WHERE existing.user_id = NEW.user_id
                      AND existing.role_id = NEW.role_id
                      AND existing.scope_type = NEW.scope_type
                      AND existing.scope_id IS NEW.scope_id
                      AND (existing.starts_at IS NULL OR NEW.ends_at IS NULL OR existing.starts_at < NEW.ends_at)
                      AND (NEW.starts_at IS NULL OR existing.ends_at IS NULL OR NEW.starts_at < existing.ends_at)
                )
            BEGIN
                SELECT RAISE(ABORT, 'role assignment is incompatible or overlaps an existing assignment');
            END;

            CREATE TRIGGER role_assignments_reject_invalid_update
            BEFORE UPDATE ON role_assignments
            WHEN NOT (
                    (NEW.scope_type = 'global' AND NEW.scope_id IS NULL)
                    OR (NEW.scope_type IN ('department', 'branch') AND NEW.scope_id IS NOT NULL)
                )
                OR NOT EXISTS (
                    SELECT 1 FROM roles AS role
                    WHERE role.id = NEW.role_id
                      AND ((NEW.scope_type = 'global' AND role.allows_global = 1)
                        OR (NEW.scope_type = 'department' AND role.allows_department = 1 AND EXISTS (SELECT 1 FROM departments WHERE id = NEW.scope_id))
                        OR (NEW.scope_type = 'branch' AND role.allows_branch = 1 AND EXISTS (SELECT 1 FROM branches WHERE id = NEW.scope_id)))
                )
                OR EXISTS (
                    SELECT 1 FROM role_assignments AS existing
                    WHERE existing.id <> NEW.id
                      AND existing.user_id = NEW.user_id
                      AND existing.role_id = NEW.role_id
                      AND existing.scope_type = NEW.scope_type
                      AND existing.scope_id IS NEW.scope_id
                      AND (existing.starts_at IS NULL OR NEW.ends_at IS NULL OR existing.starts_at < NEW.ends_at)
                      AND (NEW.starts_at IS NULL OR existing.ends_at IS NULL OR NEW.starts_at < existing.ends_at)
                )
            BEGIN
                SELECT RAISE(ABORT, 'role assignment is incompatible or overlaps an existing assignment');
            END;

            CREATE TRIGGER roles_reject_unsupported_scope_update
            BEFORE UPDATE ON roles
            WHEN (NEW.allows_global = 0 AND EXISTS (SELECT 1 FROM role_assignments WHERE role_id = OLD.id AND scope_type = 'global'))
              OR (NEW.allows_department = 0 AND EXISTS (SELECT 1 FROM role_assignments WHERE role_id = OLD.id AND scope_type = 'department'))
              OR (NEW.allows_branch = 0 AND EXISTS (SELECT 1 FROM role_assignments WHERE role_id = OLD.id AND scope_type = 'branch'))
            BEGIN
                SELECT RAISE(ABORT, 'role scope is used by an existing assignment');
            END;
            SQL);
    }

    private function createPostgresIntegrityTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION protect_membership_overlap() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    PERFORM id FROM users WHERE id IN (OLD.user_id, NEW.user_id) ORDER BY id FOR UPDATE;
                ELSE
                    PERFORM id FROM users WHERE id = NEW.user_id FOR UPDATE;
                END IF;

                IF EXISTS (
                    SELECT 1 FROM memberships AS existing
                    WHERE existing.id <> NEW.id
                      AND existing.user_id = NEW.user_id
                      AND existing.branch_id = NEW.branch_id
                      AND (existing.starts_at IS NULL OR NEW.ends_at IS NULL OR existing.starts_at < NEW.ends_at)
                      AND (NEW.starts_at IS NULL OR existing.ends_at IS NULL OR NEW.starts_at < existing.ends_at)
                ) THEN
                    RAISE EXCEPTION 'membership effective windows overlap' USING ERRCODE = '23505';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER memberships_reject_overlap
            BEFORE INSERT OR UPDATE ON memberships
            FOR EACH ROW EXECUTE FUNCTION protect_membership_overlap();

            CREATE OR REPLACE FUNCTION protect_role_assignment() RETURNS trigger AS $$
            DECLARE
                scope_allowed boolean;
                target_exists boolean;
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    PERFORM id FROM roles WHERE id IN (OLD.role_id, NEW.role_id) ORDER BY id FOR UPDATE;
                    PERFORM id FROM users WHERE id IN (OLD.user_id, NEW.user_id) ORDER BY id FOR UPDATE;
                ELSE
                    PERFORM id FROM roles WHERE id = NEW.role_id FOR UPDATE;
                    PERFORM id FROM users WHERE id = NEW.user_id FOR UPDATE;
                END IF;

                IF NOT ((NEW.scope_type = 'global' AND NEW.scope_id IS NULL)
                    OR (NEW.scope_type IN ('department', 'branch') AND NEW.scope_id IS NOT NULL)) THEN
                    RAISE EXCEPTION 'role assignment target does not match its scope' USING ERRCODE = '23514';
                END IF;

                SELECT CASE NEW.scope_type
                    WHEN 'global' THEN allows_global
                    WHEN 'department' THEN allows_department
                    WHEN 'branch' THEN allows_branch
                    ELSE false
                END INTO scope_allowed
                FROM roles WHERE id = NEW.role_id;

                IF NOT COALESCE(scope_allowed, false) THEN
                    RAISE EXCEPTION 'role does not allow the selected scope' USING ERRCODE = '23514';
                END IF;

                IF NEW.scope_type = 'department' THEN
                    SELECT EXISTS (SELECT 1 FROM departments WHERE id = NEW.scope_id) INTO target_exists;
                ELSIF NEW.scope_type = 'branch' THEN
                    SELECT EXISTS (SELECT 1 FROM branches WHERE id = NEW.scope_id) INTO target_exists;
                ELSE
                    target_exists := true;
                END IF;

                IF NOT target_exists THEN
                    RAISE EXCEPTION 'role assignment target does not exist' USING ERRCODE = '23503';
                END IF;

                IF EXISTS (
                    SELECT 1 FROM role_assignments AS existing
                    WHERE existing.id <> NEW.id
                      AND existing.user_id = NEW.user_id
                      AND existing.role_id = NEW.role_id
                      AND existing.scope_type = NEW.scope_type
                      AND existing.scope_id IS NOT DISTINCT FROM NEW.scope_id
                      AND (existing.starts_at IS NULL OR NEW.ends_at IS NULL OR existing.starts_at < NEW.ends_at)
                      AND (NEW.starts_at IS NULL OR existing.ends_at IS NULL OR NEW.starts_at < existing.ends_at)
                ) THEN
                    RAISE EXCEPTION 'role assignment effective windows overlap' USING ERRCODE = '23505';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER role_assignments_reject_invalid
            BEFORE INSERT OR UPDATE ON role_assignments
            FOR EACH ROW EXECUTE FUNCTION protect_role_assignment();

            CREATE OR REPLACE FUNCTION protect_role_scope_flags() RETURNS trigger AS $$
            BEGIN
                IF (NOT NEW.allows_global AND EXISTS (SELECT 1 FROM role_assignments WHERE role_id = OLD.id AND scope_type = 'global'))
                    OR (NOT NEW.allows_department AND EXISTS (SELECT 1 FROM role_assignments WHERE role_id = OLD.id AND scope_type = 'department'))
                    OR (NOT NEW.allows_branch AND EXISTS (SELECT 1 FROM role_assignments WHERE role_id = OLD.id AND scope_type = 'branch')) THEN
                    RAISE EXCEPTION 'role scope is used by an existing assignment' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER roles_reject_unsupported_scope
            BEFORE UPDATE ON roles
            FOR EACH ROW EXECUTE FUNCTION protect_role_scope_flags();
            SQL);
    }
};
