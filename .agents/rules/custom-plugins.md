---
trigger: model_decision
description: Invariant rules that must be followed when developing a custom WordPress plugin.
---

# Custom Plugin Rules

All custom WordPress plugins in this codebase must adhere to the engineering guidelines detailed in the `wordpress-theme-plugin-engineering` skill:

1. **PSR-4 Autoloading**: Follow PSR-4 naming and namespace autoloading standards under `src/`.
2. **Community Standards & Reusability**: Adhere to standard WordPress lifecycle hooks and PHP community conventions so plugins remain portable and reusable.
3. **Required Artifacts**: Every custom plugin must include a `README.md`, standard `readme.txt`, and `LICENSE` (MIT license).
4. **Environment Gating Policy**: Environment-variable gating (`is_env_enabled()`) is strictly restricted to asymmetric infrastructure adapters (e.g. `iap-auth-shield`) where paired Reader/Editor services share a single database and need different runtime behavior. Standard feature plugins must not use environment-variable gating.
