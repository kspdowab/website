# Agent Guidelines & Execution Rules

## Autonomous Task Execution — Always Proceed Automatically
- **Zero-Confirmation Policy for Routine Operations**:
  - NEVER prompt the user or pause for confirmation for commands related to:
    - **PHP**: linting, running CLI scripts, testing (`php -l`, `php ...`).
    - **Git**: staging, committing, diffing, status checks (`git add`, `git commit`, `git status`, `git diff`, etc.).
    - **Directory & File Inspections**: `dir`, `ls`, `Test-Path`, file checks, reading logs.
    - **Code Modifications**: adding new files, editing existing code, deleting temporary scratch files, adding/removing items.
- **Artifact Review**:
  - Always set `RequestFeedback: false` when creating or modifying artifacts so the IDE does not prompt the user with modal "Proceed" / "Submit" dialogs.
- **Never use `ask_question` for routine decisions**:
  - Deduce the correct solution, follow the project specifications, implement, test, and complete the task end-to-end.
- **Only pause if**:
  - There is a complete lack of required external credentials or a destructive irreversible action that cannot be undone.

## Strict Deployment Protocol: Localhost > Develop (Preview) > User Approval > Production
- **Mandatory 3-Stage Pipeline**:
  1. **Stage 1 (Localhost)**: Implement code, lint with `php -l`, test locally.
  2. **Stage 2 (Develop / Preview)**: Commit and push ONLY to `origin develop` (deploys to `preview.kspdowa.in`).
  3. **Stage 3 (User Testing & Review)**: Stop and provide the preview URL for user testing.
  4. **Stage 4 (Production)**: **NEVER deploy directly to `main` (production `kspdowa.in`) without testing on develop/preview first and obtaining user confirmation.** Merging into `main` and pushing to production occurs only after preview verification.

