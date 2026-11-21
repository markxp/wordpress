# Workspace Agent Standards (`legispect/wordpress`)

Repository-wide branching, rebase/merge, and pre-production verification invariants. Domain-specific Bedrock and custom plugin conventions live in `.agents/rules/` and `.agents/skills/`.

---

## 1. Branch Promotion & Linear History Gate

All changes follow a strict two-stage linear promotion pipeline: **`feature/*` → `release-candidate` → `main`**.

* **No Direct Feature-to-`main` Changes**: Never push feature/fix/chore/worktree branches directly to `main` or open pull requests from working branches directly against `main`.
* **Stage 1 — Feature to `release-candidate` (Rebase + Squash/Rebase-Merge)**:
  * Branch off `release-candidate` (or rebase onto `origin/release-candidate` before opening/updating a PR: `git fetch origin && git rebase origin/release-candidate`).
  * Open pull requests targeting `release-candidate`. Land changes via **rebase-merge** or **squash-merge** to preserve linear commit history (avoid merge commits on feature branches).
* **Stage 2 — `release-candidate` to `main` (Fast-Forward Promotion)**:
  * All pre-production workflows (`Plugin Lint, Unit & SAPI Tests` in `.github/workflows/plugin-quality-and-tests.yml` and `SAPI Stack Integration (Root)` in `.github/workflows/sapi-stack.yml`) must pass on `release-candidate` before promotion.
  * Promote `release-candidate` into `main` strictly via **fast-forward** (`--ff-only` / GitHub Rebase and merge) so `main` never diverges from tested `release-candidate` commits.
* **Automated Pull Requests**: Scheduled/bot workflows (e.g., `.github/workflows/update-site-kit-dev-settings.yml`) must target `base: release-candidate`.

---

## 2. Pre-Production Database & Test Fixtures

* **On-the-Fly Fixture Generation Only**: Never commit `.sqlite`, `.sqlite3`, `.db`, or generated `.sql` database artifacts to version control.
* **Mock WordPress Schema & Seed Data**: Maintain preset schema, `wp_user_roles`, and `active_plugins` definitions in `tests/fixtures/create-mock-db.py`. Full-stack SAPI tests (`composer test:sapi:stack` / `bin/test-root-sapi.sh`) generate the MySQL 8.4 fixture dynamically into a temporary directory and load it into an ephemeral `tmpfs` MySQL 8.4 container without modifying WordPress core or adding database drop-in plugins.
