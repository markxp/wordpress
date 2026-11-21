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
  * The pre-production CI pipeline (`CI` / `CI Gate` in `.github/workflows/ci.yml`) must pass on `release-candidate` before promotion.
  * Promote `release-candidate` into `main` strictly via **fast-forward** (`--ff-only` / GitHub Rebase and merge) so `main` never diverges from tested `release-candidate` commits.
* **Automated Pull Requests**: Scheduled/bot workflows (e.g., `.github/workflows/update-site-kit-dev-settings.yml`) must target `base: release-candidate`.

---

## 2. Repository Scope Boundaries (`/deployment` vs `/.github` vs Core Source)

* **Core Application Scope (`config/`, `web/`, `custom-plugins/`, `custom-themes/`, `composer.json`)**:
  * This is the sole production deliverable of the repository (Bedrock configuration, WordPress core/third-party package wiring, custom plugins, and themes). Automated tests and strict engineering gates apply here.
* **`/deployment` — Reference Example & Integration Guideline Only**:
  * `/deployment` is **NOT** tied to any real production deployment infrastructure or architecture.
  * Treat it strictly as a user-facing reference example demonstrating how to run this repository with OpenTelemetry observability, media offloading (`wp-stateless`), web-server endpoint hardening, external `WP_CRON` triggering, file permissions, and health-probe patterns.
  * Do **not** couple CI pipelines to building or E2E-testing `/deployment` containers on automated pushes or pull requests, and never treat `/deployment` manifests as live production infrastructure state.
* **`/.github` — Lightweight Code-Verification Harness Only**:
  * `/.github/workflows` exists solely to examine and verify our repository's core PHP/Bedrock/plugin source code quickly and cheaply on GitHub Actions runners (`CI` / `CI Gate` in `.github/workflows/ci.yml`).
  * Any auxiliary scripts, service containers, or Dockerfiles referenced by workflows are disposable test harnesses—not application deliverables—and do not need cross-distro matrix verification or heavy infrastructure testing when non-source files (`README.md`, `deployment/**`, `.agents/**`) change.

---

## 3. Pre-Production Database & Test Fixtures

* **On-the-Fly Fixture Generation Only**: Never commit `.sqlite`, `.sqlite3`, `.db`, or generated `.sql` database artifacts to version control.
* **Mock WordPress Schema & Seed Data**: Maintain preset schema, `wp_user_roles`, and `active_plugins` definitions in `tests/fixtures/create-mock-db.py`. Pre-production Bedrock SAPI tests (`composer test:sapi:host` / `bin/test-host-sapi.sh` in CI, and optional manual container smoke tests via `composer test:sapi:stack` / `bin/test-root-sapi.sh`) generate the MySQL 8.4 fixture dynamically into a temporary directory and load it into an ephemeral MySQL 8.4 instance without modifying WordPress core or adding database drop-in plugins.
