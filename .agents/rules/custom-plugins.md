---
trigger: model_decision
description: rules that we must follows when developing a WordPress plugin.
---

1. Follow PSR-4.
2. contains a `README.md` and a `readme.txt`.
3. contains a `LICENSE` use MIT license.
4. implement the environment variable loader to enable the plugin. only when the variable is set to true and the plugin is enabled in the database value, the plugin is really work.