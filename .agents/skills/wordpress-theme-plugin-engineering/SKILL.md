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

1. **Community Conventions for Reusability**:
   - Custom plugins (standard plugins in `web/app/plugins/` or drop-ins in `web/app/mu-plugins/`) must strictly adhere to PHP and WordPress community conventions.
   - Code must remain portable and reusable across standard WordPress environments without hardcoding proprietary assumptions.
   - Use standard WordPress plugin headers, hooks (`plugins_loaded`, `init`, etc.), and the Settings API.
2. **PSR-4 Autoloading & Namespaces**:
   - Organize plugin PHP code under `src/` using PSR-4 namespaces mapped in `composer.json` or plugin bootstrappers.
3. **Required Documentation & Licensing**:
   - Every custom plugin must include:
     - `README.md`: Developer-focused architecture, hooks, and usage guide.
     - `readme.txt`: Standard WordPress.org-formatted plugin header and changelog.
     - `LICENSE`: MIT license file.
4. **Extensible Filters**:
   - Never hardcode irreversible routing, API restrictions, or blocks.
   - Expose extensible, plugin-namespaced filter hooks (e.g. `plugin_slug_allowed_rest_prefixes`) so external components can register custom rules cleanly without cross-plugin coupling.

---

## 2. Custom Theme Engineering Standards (Modern Block Themes / FSE)

1. **Full Site Editing (FSE) First**:
   - Target modern Block Themes prioritizing `theme.json` configuration over custom PHP template files.
   - Core structure:
     - `style.css`: Theme header metadata.
     - `theme.json`: Global design tokens, typography, color palettes, and layout constraints.
     - `templates/`: HTML block templates (e.g. `index.html`, `single.html`).
     - `parts/`: Reusable template parts (e.g. `header.html`, `footer.html`).
     - `patterns/`: Block patterns registered with PHP block pattern headers.
2. **Block Patterns & Token Reusability**:
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
  - Co-locate tests in `web/app/plugins/<plugin>/tests/` using **PHPUnit** and **Brain Monkey** for isolated WordPress hook/function mocking.
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
