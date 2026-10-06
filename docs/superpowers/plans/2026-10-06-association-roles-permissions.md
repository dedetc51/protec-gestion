# Association Roles and Permissions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox syntax for tracking.

**Goal:** Add multi-antenna membership, scoped cumulative association roles, and an editable global/departmental permission matrix.

**Architecture:** Persist organization, membership, assignment, grant, and override data in normalized PostgreSQL tables. Resolve every authorization through one scoped Laravel service used by policies, controllers, and Blade; preserve users.role during the compatibility window.

**Tech Stack:** PHP 8.5, Laravel 13, Eloquent, PostgreSQL 18, Blade, Vite/Tailwind CSS, PHPUnit 12, Docker Compose.

**Spec:** docs/superpowers/specs/2026-10-06-association-roles-permissions-design.md

## Global Constraints

- Users may belong to several branches and hold several active roles per scope.
- Technical administration remains global and cannot be delegated.
- Department presidents manage only their department.
- Branch grants never leak into another branch.
- Department overrides have inherited, granted, or denied state.
- Assignment and permission mutations are transactional and audited.
- Keep users.role for rollback compatibility in this release.
- French copy, keyboard focus, non-color states, and a role-focused mobile view are required.

## Review Focus

- Forged branch IDs from another department return 403; Task 4 pins this.
- Conflicting role grants resolve per role, so one denied role does not erase another granted role; Task 3 pins this.
- Future and expired memberships or assignments never authorize; Tasks 1 and 3 pin this.
- Presidents cannot grant non-delegable technical permissions with a handcrafted request; Task 5 pins this.
- The final technical administrator cannot be removed; Task 4 pins this.

---

### Task 1: Repository bootstrap and organization schema

**Files:**
- Modify: AGENTS.md, composer.json, composer.lock, app/Models/User.php
- Create: database/migrations/2026_10_06_000001_create_organization_rbac_tables.php
- Create: app/Models/Department.php, Branch.php, Role.php, Permission.php, Membership.php, RoleAssignment.php
- Test: tests/Feature/OrganizationSchemaTest.php

**Interfaces:**
- Produces Department::branches(), Branch::department(), User::memberships(), User::roleAssignments().
- Produces Role::allowsScope(string): bool and active query scopes for memberships and assignments.

- [ ] **Step 1: Complete required Laravel Boost setup**

    php -v
    composer -V
    composer require laravel/boost --dev
    php artisan boost:install
    cat AGENTS.md

Expected: commands succeed and generated repository instructions are read before code changes.

- [ ] **Step 2: Write failing schema tests**

Create two departments, three branches, a user with two memberships, and department/branch assignments. Assert relations, uniqueness, role-scope compatibility, and that tomorrow's membership is inactive.

    $this->assertCount(2, $user->memberships()->active()->get());
    $this->assertTrue($departmentRole->allowsScope('department'));
    $this->assertFalse($departmentRole->allowsScope('branch'));
    $this->assertFalse($futureMembership->isActiveAt(now()));

- [ ] **Step 3: Confirm RED**

Run: php artisan test tests/Feature/OrganizationSchemaTest.php

Expected: FAIL because models and tables do not exist.

- [ ] **Step 4: Implement schema and models**

Create departments, branches, roles, permissions, memberships, role_assignments, role_permissions, and department_role_permissions. Use foreign keys, unique indexes, nullable starts_at/ends_at, scope indexes, and state check constraints. Role scope support uses allows_global, allows_department, and allows_branch booleans. Role assignments use scope_type/scope_id and reject unsupported scope types.

- [ ] **Step 5: Implement active scopes and relationships**

Implement scopeActive(Builder $query, ?CarbonInterface $at = null): Builder. Add typed relations to User without removing isAdmin().

- [ ] **Step 6: Verify GREEN and commit**

    php artisan migrate:fresh
    php artisan test tests/Feature/OrganizationSchemaTest.php
    git add AGENTS.md composer.json composer.lock database/migrations app/Models tests/Feature/OrganizationSchemaTest.php
    git commit -m "feat: add scoped organization role model"

Expected: PASS.

### Task 2: Role and permission catalog

**Files:**
- Create: app/Support/AssociationRoleCatalog.php, app/Support/PermissionCatalog.php
- Create: database/seeders/AssociationAuthorizationSeeder.php
- Create: database/migrations/2026_10_06_000002_seed_association_authorization.php
- Modify: database/seeders/DatabaseSeeder.php
- Test: tests/Feature/AssociationAuthorizationSeederTest.php

**Interfaces:**
- Produces stable role slugs and permission keys.
- Produces PermissionCatalog::groups(): array and PermissionCatalog::isDelegable(string): bool.

- [ ] **Step 1: Write failing catalog tests**

Assert technical-admin plus all specified association roles, including department and branch operational roles and every deputy. Assert permission groups for members, organization, equipment, vehicles, logistics, operations, training, documents, communications, settings, audit, and technical administration. Assert technical permissions are not delegable.

- [ ] **Step 2: Confirm RED**

Run: php artisan test tests/Feature/AssociationAuthorizationSeederTest.php

Expected: FAIL because catalogs do not exist.

- [ ] **Step 3: Implement immutable catalogs**

Use stable slugs: technical-admin, department-president, operations-manager, operations-deputy, branch-manager, branch-deputy, vehicle-manager, vehicle-deputy, equipment-manager, equipment-deputy, logistics-manager, logistics-deputy, volunteer. The operational slugs permit department and branch scopes; UI labels add the scope. Use stable permission keys such as members.view, members.assign_roles, branches.manage, equipment.update, operations.validate, permissions.manage_department, permissions.manage_global, and technical.manage.

- [ ] **Step 4: Seed conservative defaults idempotently**

Use updateOrCreate and syncWithoutDetaching. Volunteer gets base view rights. Deputies omit deletion, final validation, and permission management. Department presidents receive delegable association management permissions only.

- [ ] **Step 5: Add compatible data migration**

Seed catalogs, create an initial department and branch from DEFAULT_DEPARTMENT_NAME and DEFAULT_BRANCH_NAME with French fallbacks, and assign current users.role=admin users the global technical-admin role. Do not remove or modify users.role.

- [ ] **Step 6: Verify idempotency and commit**

    php artisan migrate:fresh --seed
    php artisan db:seed --class=AssociationAuthorizationSeeder
    php artisan test tests/Feature/AssociationAuthorizationSeederTest.php tests/Feature/EnsureInitialAdminTest.php
    git add app/Support database/seeders database/migrations tests/Feature/AssociationAuthorizationSeederTest.php
    git commit -m "feat: seed association roles and permissions"

Expected: no duplicates and all tests PASS.

### Task 3: Scoped authorization engine

**Files:**
- Create: app/Authorization/AuthorizationContext.php
- Create: app/Services/ScopedPermissionResolver.php
- Create: app/Policies/DepartmentPolicy.php, app/Policies/BranchPolicy.php
- Modify: app/Providers/AppServiceProvider.php, app/Models/User.php
- Test: tests/Feature/ScopedPermissionResolverTest.php

**Interfaces:**
- Produces AuthorizationContext::global(), ::department(Department), ::branch(Branch).
- Produces ScopedPermissionResolver::allows(User, string, AuthorizationContext): bool.
- Produces User::canIn(string, AuthorizationContext): bool.

- [ ] **Step 1: Write table-driven failing tests**

Cover technical admin, department coverage, branch isolation, cumulative roles, inherited/granted/denied overrides, technical non-delegation, future/expired assignments, deactivated users, and two roles where one override denies and another grants.

- [ ] **Step 2: Confirm RED**

Run: php artisan test tests/Feature/ScopedPermissionResolverTest.php

Expected: FAIL because resolver is absent.

- [ ] **Step 3: Implement deterministic resolution**

Reject deactivated users. Grant global technical permissions only to technical-admin. Load active assignments applicable to the requested context. Evaluate each role independently: use department override when present, otherwise global grant; combine successful grants across roles. A denied override cancels only that role's grant.

- [ ] **Step 4: Register gates and policies**

Register scoped-permission in AppServiceProvider and add User::canIn(). Policies authorize only the actor's department or branch and never technical permissions through associative roles.

- [ ] **Step 5: Verify and commit**

    php artisan test tests/Feature/ScopedPermissionResolverTest.php tests/Feature/NavigationTest.php tests/Feature/AuditTest.php
    git add app/Authorization app/Services/ScopedPermissionResolver.php app/Policies app/Providers/AppServiceProvider.php app/Models/User.php tests/Feature/ScopedPermissionResolverTest.php
    git commit -m "feat: resolve permissions by organization scope"

Expected: PASS.

### Task 4: Organization and member assignments

**Files:**
- Create: app/Http/Controllers/Admin/DepartmentController.php, BranchController.php, MemberAssignmentController.php
- Create: app/Http/Requests/Admin/StoreDepartmentRequest.php, StoreBranchRequest.php, UpdateMemberAssignmentsRequest.php
- Create: app/Services/MemberAssignmentService.php
- Create: resources/views/admin/organization/index.blade.php
- Create: resources/views/admin/assignments/index.blade.php, edit.blade.php
- Modify: routes/web.php
- Test: tests/Feature/Admin/OrganizationManagementTest.php, MemberAssignmentManagementTest.php

**Interfaces:**
- Produces MemberAssignmentService::replace(User $subject, array $memberships, array $assignments, User $actor): void.
- Produces admin.organization.* and admin.assignments.* named routes.

- [ ] **Step 1: Write failing organization tests**

Test technical-admin access, president access to own department, other-department 403, normalized unique names, forged cross-department branch 403, deactivation instead of deletion, and audit events.

- [ ] **Step 2: Write failing assignment tests**

Test several branch memberships, several roles per branch, different roles across branches, department operational roles, invalid scope-role pairs, forged foreign branch IDs, future/expired dates, audit records, and prevention of removing the final technical administrator.

- [ ] **Step 3: Confirm RED**

Run: php artisan test tests/Feature/Admin/OrganizationManagementTest.php tests/Feature/Admin/MemberAssignmentManagementTest.php

Expected: FAIL with missing routes.

- [ ] **Step 4: Implement transactional services and controllers**

Authorize every submitted scope server-side. Normalize names. Deactivation sets deactivated_at. MemberAssignmentService ends removed records, creates new records, preserves unchanged history, enforces one active technical administrator, and records safe before/after identifiers.

- [ ] **Step 5: Build French administration pages**

Organization page groups branches by department. Assignment index searches name/email and displays antenna/responsibility chips. Edit page separates department roles from branch memberships and uses fieldsets, legends, allowed-role filtering, and effective dates.

- [ ] **Step 6: Verify and commit**

    php artisan test tests/Feature/Admin/OrganizationManagementTest.php tests/Feature/Admin/MemberAssignmentManagementTest.php tests/Feature/AuditTest.php
    php artisan route:list --path=admin
    git add app/Http/Controllers/Admin app/Http/Requests/Admin app/Services/MemberAssignmentService.php resources/views/admin/organization resources/views/admin/assignments routes/web.php tests/Feature/Admin
    git commit -m "feat: manage organization member assignments"

Expected: PASS.

### Task 5: Permission matrix

**Files:**
- Create: app/Http/Controllers/Admin/PermissionMatrixController.php
- Create: app/Http/Requests/Admin/UpdateGlobalPermissionsRequest.php, UpdateDepartmentPermissionsRequest.php
- Create: app/Services/PermissionMatrixService.php
- Create: resources/views/admin/permissions/index.blade.php
- Modify: resources/js/app.js, routes/web.php
- Test: tests/Feature/Admin/PermissionMatrixTest.php

**Interfaces:**
- Produces PermissionMatrixService::replaceGlobal(array, User): void.
- Produces PermissionMatrixService::replaceDepartment(Department, array, User): void.

- [ ] **Step 1: Write failing matrix tests**

Test admin global updates, president global 403, own-department override, other-department 403, all three states, technical permission rejection, malformed pairs, all-or-nothing rollback, resolver cache invalidation, and audit creation.

- [ ] **Step 2: Confirm RED**

Run: php artisan test tests/Feature/Admin/PermissionMatrixTest.php

Expected: FAIL with missing matrix routes.

- [ ] **Step 3: Implement transactional matrix services**

Lock affected rows. Global cells are booleans. Department cells accept inherit, grant, or deny; inherit deletes the override. Validate role, permission, actor scope, and delegability before writes. Invalidate resolver cache after commit.

- [ ] **Step 4: Build desktop and mobile views**

Desktop uses a semantic table with sticky header and permission column, grouped rows, role columns, filters, search, visible legend, and textual tri-state labels. Mobile selects one role and displays its permission list without removing hidden form controls. JavaScript progressively enhances filtering and role selection.

- [ ] **Step 5: Verify and commit**

    php artisan test tests/Feature/Admin/PermissionMatrixTest.php
    npm run build
    git add app/Http/Controllers/Admin/PermissionMatrixController.php app/Http/Requests/Admin app/Services/PermissionMatrixService.php resources/views/admin/permissions resources/js/app.js routes/web.php tests/Feature/Admin/PermissionMatrixTest.php
    git commit -m "feat: add scoped permission matrix"

Expected: PASS and Vite exits 0.

### Task 6: Navigation, design, and accessibility

**Files:**
- Modify: resources/views/layouts/app.blade.php, resources/views/admin/index.blade.php
- Modify: resources/css/app.css, resources/js/app.js
- Modify: tests/Feature/NavigationTest.php
- Create: tests/Feature/Admin/AdministrationAccessibilityTest.php

**Interfaces:**
- Consumes scoped permissions and named routes from Tasks 3-5.
- Produces permission-aware navigation and shared administration styles.

- [ ] **Step 1: Write failing visibility and markup tests**

Assert admin sees every administration link, president sees organization/assignments/department permissions but no global matrix, and volunteer sees none. Assert heading, labels, table caption, fieldsets, legends, textual states, and live success feedback.

- [ ] **Step 2: Confirm RED**

Run: php artisan test tests/Feature/NavigationTest.php tests/Feature/Admin/AdministrationAccessibilityTest.php

Expected: FAIL against current admin-only navigation.

- [ ] **Step 3: Implement the Protec-Gestion command-board design**

Keep #17202a, #b21f2d, #176b45 and the existing sidebar. Make the matrix the sole dense command-board element with restrained borders, compact rows, sentence-case labels, and no decorative per-cell cards.

- [ ] **Step 4: Implement accessible responsive behavior**

Add sticky regions, overflow, visible keyboard focus, 44px mobile targets, reduced-motion support, error-summary links, and icon-plus-text states so color is never the sole indicator.

- [ ] **Step 5: Verify and commit**

    php artisan test tests/Feature/NavigationTest.php tests/Feature/Admin/AdministrationAccessibilityTest.php
    npm run build
    git add resources/views resources/css/app.css resources/js/app.js tests/Feature/NavigationTest.php tests/Feature/Admin/AdministrationAccessibilityTest.php
    git commit -m "feat: integrate association administration interface"

Expected: PASS.

### Task 7: Regression, review, and deploy

**Files:**
- Modify: .env.example, docs/operations.md
- Test: full application and operations suites

**Interfaces:**
- Consumes the completed feature.
- Produces documented operation, rollback, and verified production release.

- [ ] **Step 1: Document defaults and operations**

Add DEFAULT_DEPARTMENT_NAME and DEFAULT_BRANCH_NAME. Document assignments, global/department matrices, backup-before-migrate, users.role compatibility, and rollback.

- [ ] **Step 2: Run complete local verification**

    composer test
    npm run build
    for test in ops/tests/*.sh; do bash "$test"; done
    git diff --check

Expected: every command exits 0.

- [ ] **Step 3: Rehearse migration on disposable PostgreSQL**

Start a disposable Compose project, migrate from current schema, seed twice, assert one technical administrator and stable catalog counts, then remove only the disposable project. Record counts in deployment evidence.

- [ ] **Step 4: Request whole-branch review**

Review authorization boundaries, migrations, audit metadata, accessibility, and rollback. Every accepted finding receives a failing test, minimal fix, focused commit, and fresh full suite.

- [ ] **Step 5: Push and require green CI**

    git push origin codex/protec-gestion-foundation
    gh run watch --repo dedetc51/protec-gestion --exit-status

Expected: final HEAD CI concludes success.

- [ ] **Step 6: Deploy exact verified commit**

Copy ops/deploy/deploy.sh to pve1, compare SHA-256, and deploy the full 40-character revision. Require verified PostgreSQL dump before migration and retain previous release.

- [ ] **Step 7: Verify production**

Verify DEPLOYED revision, five healthy containers, /up 200, login 200, catalog counts, admin conversion, admin matrix access, volunteer 403, database backup timer, Proxmox snapshot job, and IP session cookies without Domain until restricted HTTPS proxy activation.

- [ ] **Step 8: Commit documentation**

    git add .env.example docs/operations.md
    git commit -m "docs: document association authorization operations"
    git push origin codex/protec-gestion-foundation

