# Task 3 report — scoped authorization engine

Implemented the scoped authorization context, shared permission resolver, Laravel gate, `User::canIn()`, and department and branch policies.

The resolver rejects deactivated users and inactive assignments, keeps branch assignments local, lets department assignments cover only their own department and branches, and combines grants across roles. Each role's department override replaces only that role's global grant. Technical permissions are denied to association roles; an active global `technical-admin` assignment authorizes them in each context.

Added feature coverage for technical administration, department and branch isolation, cumulative roles, inherited/granted/denied overrides, role-specific denial, technical non-delegation, future and expired assignments, deactivated users, the scoped gate, and both policies.

Verification:

- RED confirmed: the new feature tests failed because `ScopedPermissionResolver` and `AuthorizationContext` were missing.
- Focused suite: 15 tests, 42 assertions passed.
- Full suite: 115 tests, 531 assertions passed.
- Pint: passed; `git diff --check`: passed.

## Review fixes

Required a persisted identity for non-global contexts and an active membership in the exact branch for branch-origin assignments. Assignment and membership windows now use the same captured resolution time. Department-origin grants remain independent of branch membership. Added regressions for active, future and expired memberships; unsaved department and branch contexts; and a base grant canceled by that role's department denial.

Review-fix verification:

- RED confirmed: the resolver suite failed on the expected future/expired membership and unsaved-context cases.
- Focused resolver suite: 12 tests, 30 assertions passed.
- Full suite: 117 tests, 537 assertions passed.
- Pint passed.

## Technical non-delegation regression follow-up

Added an active membership for the association role's exact branch to the technical-permission rejection test. Temporarily removed the resolver's non-delegability guard; the targeted test then failed because the branch role could grant `technical.manage`. Restored the guard and confirmed the test passes.

- Focused resolver suite: 12 tests, 30 assertions passed.
- Full suite: 117 tests, 537 assertions passed.
- Pint and `git diff --check`: passed.
