---
name: wordpress-theme-plugin-engineering
description: >-
  Guides the development, testing, and maintenance of custom WordPress plugins
  and themes in Bedrock. Enforces community conventions, reusability, modern
  block themes (FSE), PSR-4 standards, Brain Monkey / PHPUnit testing, and Pint formatting.
---

# WordPress Plugin & Theme Engineering Guidelines

This skill defines the standards, code conventions, and architectural best practices for authoring custom WordPress plugins and themes in this Bedrock codebase, prioritizing community conventions, reusability, and clean decoupling.

---

## 1. Custom Plugin Engineering Standards

1. **Layered Monorepo Layout & Community Conventions**:
   - Author homemade plugins under `custom-plugins/<plugin-slug>/` as standalone Composer packages (`"type": "wordpress-plugin"`), wired into the root Bedrock `composer.json` via a `path` repository (omit `"symlink": true` so Composer auto-symlinks locally by default while honoring `COMPOSER_MIRROR_PATH_REPOS=1` to mirror files in container builds) so Composer installs them into `web/app/plugins/<plugin-slug>/`.
   - Follow the same layered `path` repository pattern under `custom-themes/<theme-slug>/` (`"type": "wordpress-theme"`, installed into `web/app/themes/<theme-slug>/`) when authoring custom themes.
   - Code must remain portable and reusable across standard WordPress environments without hardcoding proprietary assumptions.
   - Use standard WordPress plugin headers, hooks (`plugins_loaded`, `init`, etc.), and the Settings API.
2. **PSR-4 Autoloading, Layered `composer.json`, & Fallback**:
   - Organize plugin PHP code under `src/` using PSR-4 namespaces mapped in each plugin's own `composer.json` (`autoload.psr-4`).
   - Declare plugin-specific runtime dependencies in the plugin's own `composer.json` `require` (not in the root `composer.json`) so dependencies stay clearly owned and extraction-ready.
   - Declare `require-dev` (`phpunit/phpunit`, `brain/monkey`, `mockery/mockery`, `laravel/pint`) in each plugin's `composer.json` for standalone readiness, and document in the plugin's `README.md` that Composer ignores `require-dev` in `path`/`vcs` dependencies (so standalone testing requires `composer install` inside `custom-plugins/<plugin-slug>/`).
   - Keep a self-contained `spl_autoload_register` fallback in the plugin's main `.php` entrypoint (checking `vendor-prefixed/autoload.php` before `vendor/autoload.php`) so the plugin remains portable to non-Composer WordPress installations.
3. **Dependency Isolation & Standalone Packaging (`composer build:dist` + Strauss)**:
   - Keep `src/` using standard unprefixed Composer namespaces so Bedrock's flat dependency resolution and Dependabot work natively with zero committed vendor copies.
   - When a plugin has third-party `require` dependencies, configure `extra.strauss` (`target_directory: "vendor-prefixed"`, `namespace_prefix`, `classmap_prefix`, `constant_prefix`, `packages`, `include_modified_date: false`) and provide an opt-in `composer build:dist` script that runs **Strauss** inside a temporary build directory to produce a conflict-free `<plugin-slug>.zip` (preserving third-party `LICENSE` files) without modifying `src/`.
   - Root-level builds and tests (`composer install`, `composer test`, `composer lint`, `composer docker-build`) must always use flat Composer mode and never trigger `build:dist`.
4. **Required Documentation & Licensing**:
   - Every custom plugin must include:
     - `README.md`: Developer-focused architecture, hooks, standalone `composer install` testing note, and `build:dist` usage.
     - `readme.txt`: Standard WordPress.org-formatted plugin header and changelog.
     - `LICENSE`: MIT license file.
5. **Extensible Filters**:
   - Never hardcode irreversible routing, API restrictions, or blocks.
   - Expose extensible, plugin-namespaced filter hooks (e.g. `plugin_slug_allowed_rest_prefixes`) so external components can register custom rules cleanly without cross-plugin coupling.

---

## 2. Custom Theme Engineering Standards (Modern Block Themes / FSE)

1. **Layered Monorepo Layout**:
   - Author homemade themes under `custom-themes/<theme-slug>/` as standalone Composer packages (`"type": "wordpress-theme"`), wired into the root Bedrock `composer.json` via a `path` repository (omit `"symlink": true` so Composer auto-symlinks locally by default while honoring `COMPOSER_MIRROR_PATH_REPOS=1` to mirror files in container builds) so Composer installs them into `web/app/themes/<theme-slug>/`.
2. **Full Site Editing (FSE) First**:
   - Target modern Block Themes prioritizing `theme.json` configuration over custom PHP template files.
   - Core structure:
     - `style.css`: Theme header metadata.
     - `theme.json`: Global design tokens, typography, color palettes, and layout constraints.
     - `templates/`: HTML block templates (e.g. `index.html`, `single.html`).
     - `parts/`: Reusable template parts (e.g. `header.html`, `footer.html`).
     - `patterns/`: Block patterns registered with PHP block pattern headers.
3. **Block Patterns & Token Reusability**:
   - Reference design tokens defined in `theme.json` (e.g., `var(--wp--preset--color--primary)`) instead of arbitrary hardcoded CSS styles.
   - Keep custom PHP inside `functions.php` minimal and focused on theme setup (`after_setup_theme`, pattern categories).

---

## 3. Infrastructure vs Feature Architecture (Environment Variable Gating)

The **Twin-Service (Reader/Editor split)** architecture is an infrastructure deployment pattern specific to this project, where two separate compute instances share a single database.

### When to Use Environment-Variable Gating (`is_env_enabled()`)
- **Asymmetric Infrastructure Adapters ONLY**:
  - Implement environment variable gating (`is_env_enabled()`) **only** when a plugin must exhibit asymmetric runtime behavior between paired instances sharing a database.
  - *Example*: `iap-auth-shield` must run on the administrative Editor service to enforce IAP headers, but must remain dormant on the public Reader service even if the plugin is activated in the shared database.
  - For these adapters:
    - The plugin requires both the database activation and the corresponding environment variable (e.g. `IAP_AUTH_ENABLED=true`) to execute.
    - Admin screens should display an environment status indicator.

### When NOT to Use Environment-Variable Gating
- **Standard Feature & Business Plugins & Themes**:
  - Feature plugins, content enhancers, block extensions, themes, or symmetric utilities do **not** need environment variable gating.
  - Standard components must rely on native WordPress activation lifecycles (`active_plugins` option or theme selection). Do not force unnecessary environment variables onto general plugins or themes.

---

## 4. Testing & Quality Assurance

- **Unit Testing**:
  - Co-locate tests in `custom-plugins/<plugin>/tests/` using **PHPUnit** and **Brain Monkey** for isolated WordPress hook/function mocking.
  - Run containerized tests to guarantee PHP 8.5+ runtime compatibility:
    ```bash
    composer test:container
    ```
- **Code Style & Formatting**:
  - Enforce PSR-12 and Laravel Pint formatting across all PHP files:
    ```bash
    # Check formatting
    composer lint:container

    # Auto-fix formatting
    composer lint:fix:container
    ```
