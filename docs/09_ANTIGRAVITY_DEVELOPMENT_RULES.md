# KSPDOWA ANTIGRAVITY DEVELOPMENT RULES
## v1.0

Before creating or modifying code:

1. Read `01_MASTER_PROJECT_SPECIFICATION.md`.
2. Read the relevant module specification.
3. Inspect the current code before editing it.
4. Inspect database migrations and dependencies before changing schema.
5. Do not invent business rules.
6. Do not silently change architecture.
7. Preserve backward compatibility unless a change is explicitly approved.
8. Use secure PHP coding practices.
9. Use prepared SQL/PDO.
10. Validate and sanitize all user input.
11. Escape output.
12. Enforce authorization server-side.
13. Protect member-only files.
14. Preserve immutable grievance history.
15. Never duplicate a master grievance during forwarding.
16. Never trust client-side payment success.
17. Log important administrative actions.
18. Keep database changes in versioned migrations.
19. Keep frontend responsive.
20. Test each module before moving to the next.

## Grievance rule
The locked model is:

**One Master Grievance + Hierarchical Access + Controlled Forwarding + Immutable Timeline + Authority Tracking**

## Coding style
Prefer:
- clear PHP classes/functions
- reusable components
- small focused files
- meaningful variable names
- centralized configuration
- centralized authorization
- centralized error handling

Avoid:
- duplicated business logic
- hard-coded IDs
- inline database credentials
- raw SQL concatenation
- public sensitive file paths
- giant monolithic PHP files

## Change protocol
When a requested change conflicts with a locked requirement:
1. identify the conflict
2. explain the impact
3. propose the smallest safe change
4. wait for approval if architecture/business logic is affected
