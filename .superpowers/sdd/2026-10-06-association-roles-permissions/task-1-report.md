# Task 1 report

Implemented the organization RBAC schema, models, factories, typed relations, active time-window scopes, and assignment validation. Department overrides store only `grant` or `deny`; inheritance is represented by no override row.

- RED: `php artisan test tests/Feature/OrganizationSchemaTest.php` failed as expected before implementation because the organization models did not exist (47 failures).
- GREEN: `php artisan test tests/Feature/OrganizationSchemaTest.php` passed (47 tests, 116 assertions).
- Migration: `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan migrate:fresh --no-interaction` passed. The unmodified command was canceled because the local `.env` sets `APP_ENV=production`; the in-memory override kept the destructive reset isolated.
- Formatting: `vendor/bin/pint --dirty --format agent` passed and fixed formatting in eight PHP files.
- Bootstrap: `composer show --direct` reports `laravel/boost` 2.10.1; generated `AGENTS.md` was read and committed.
- Commit: `feat: add scoped organization role model` (this report is included in that commit).

## Review corrections

- Replaced end-date generated uniqueness markers with database triggers that reject overlapping half-open effective windows on insert and update. PostgreSQL triggers lock the stable user/role rows before checking overlaps so concurrent writes serialize; SQLite triggers enforce the same invariant under its serialized writes. No current-time expression is used. Role-assignment triggers also enforce supported scope flags, global/null versus department-or-branch/non-null target shape, and target existence. Role flag changes are rejected while assignments use a disabled scope.
- Replaced the writable many-to-many override with `DepartmentRolePermission`, a typed model keyed by a surrogate row id plus a unique `(department_id, role_id, permission_id)` constraint. Regression coverage updates and deletes one role's cell while preserving another role's cell for the same permission.
- Branch factory names now use unique generated labels.
- TDD: the new direct-write, interval-overlap, scope-change, and override identity cases failed against the previous schema/model contract; focused suite now passes (63 tests, 136 assertions).
- Migration: `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan migrate:fresh --no-interaction` passed.
- Formatting: `vendor/bin/pint --dirty --format agent` passed.
- PostgreSQL execution could not be verified locally: `APP_ENV=testing DB_CONNECTION=pgsql php artisan migrate:status --no-interaction` reported connection refused at `127.0.0.1:5432`. The migration and tests include PostgreSQL-specific trigger SQL and use the same behavioral assertions when run with PostgreSQL configured.
- Fix commit: `fix: enforce organization RBAC database invariants`.
