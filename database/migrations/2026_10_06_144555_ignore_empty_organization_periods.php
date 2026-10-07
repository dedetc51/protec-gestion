<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->installOverlapChecks(true);
    }

    public function down(): void
    {
        $this->installOverlapChecks(false);
    }

    private function installOverlapChecks(bool $ignoreEmpty): void
    {
        $sql = match (DB::connection()->getDriverName()) {
            'sqlite' => $this->sqliteChecks(),
            'pgsql' => $this->postgresChecks(),
            default => throw new RuntimeException('Organization integrity checks support SQLite and PostgreSQL.'),
        };
        $guard = $ignoreEmpty
            ? '(NEW.starts_at IS NULL OR NEW.ends_at IS NULL OR NEW.ends_at > NEW.starts_at) AND (existing.starts_at IS NULL OR existing.ends_at IS NULL OR existing.ends_at > existing.starts_at)'
            : '1 = 1';
        DB::unprepared(str_replace('/* effective_window_guard */', $guard, $sql));
    }

    private function sqliteChecks(): string
    {
        return <<<'SQL'
            DROP TRIGGER IF EXISTS memberships_reject_overlap_insert;
            DROP TRIGGER IF EXISTS memberships_reject_overlap_update;
            DROP TRIGGER IF EXISTS role_assignments_reject_invalid_insert;
            DROP TRIGGER IF EXISTS role_assignments_reject_invalid_update;
            CREATE TRIGGER memberships_reject_overlap_insert
            BEFORE INSERT ON memberships
            WHEN EXISTS (
                SELECT 1 FROM memberships AS existing
                WHERE existing.user_id = NEW.user_id
                  AND existing.branch_id = NEW.branch_id
                  AND /* effective_window_guard */
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
                  AND /* effective_window_guard */
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
                      AND /* effective_window_guard */
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
                      AND /* effective_window_guard */
                      AND (existing.starts_at IS NULL OR NEW.ends_at IS NULL OR existing.starts_at < NEW.ends_at)
                      AND (NEW.starts_at IS NULL OR existing.ends_at IS NULL OR NEW.starts_at < existing.ends_at)
                )
            BEGIN
                SELECT RAISE(ABORT, 'role assignment is incompatible or overlaps an existing assignment');
            END;
            SQL;
    }

    private function postgresChecks(): string
    {
        return <<<'SQL'
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
                      AND /* effective_window_guard */
                      AND (existing.starts_at IS NULL OR NEW.ends_at IS NULL OR existing.starts_at < NEW.ends_at)
                      AND (NEW.starts_at IS NULL OR existing.ends_at IS NULL OR NEW.starts_at < existing.ends_at)
                ) THEN
                    RAISE EXCEPTION 'membership effective windows overlap' USING ERRCODE = '23505';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

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
                      AND /* effective_window_guard */
                      AND (existing.starts_at IS NULL OR NEW.ends_at IS NULL OR existing.starts_at < NEW.ends_at)
                      AND (NEW.starts_at IS NULL OR existing.ends_at IS NULL OR NEW.starts_at < existing.ends_at)
                ) THEN
                    RAISE EXCEPTION 'role assignment effective windows overlap' USING ERRCODE = '23505';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            SQL;
    }
};
