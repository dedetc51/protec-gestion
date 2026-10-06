# Task 3 report — scoped authorization engine

Implemented the scoped authorization context, shared permission resolver, Laravel gate, `User::canIn()`, and department and branch policies.

The resolver rejects deactivated users and inactive assignments, keeps branch assignments local, lets department assignments cover only their own department and branches, and combines grants across roles. Each role's department override replaces only that role's global grant. Technical permissions are denied to association roles; an active global `technical-admin` assignment authorizes them in each context.

Added feature coverage for technical administration, department and branch isolation, cumulative roles, inherited/granted/denied overrides, role-specific denial, technical non-delegation, future and expired assignments, deactivated users, the scoped gate, and both policies.

Verification:

- RED confirmed: the new feature tests failed because `ScopedPermissionResolver` and `AuthorizationContext` were missing.
- Focused suite: 15 tests, 42 assertions passed.
- Full suite: 115 tests, 531 assertions passed.
- Pint: passed; `git diff --check`: passed.
