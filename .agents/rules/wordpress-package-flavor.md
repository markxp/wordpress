---
trigger: model_decision
description: Specifies rules and preferences for our Bedrock-based WordPress project structure, package choices, security, and observability.
---

## Core Principles

- **Application Reusability**: We focus heavily on application reusability. This repository must contain all WordPress-related custom packages, plugins, and scripts necessary for the site layout and logic.
- **Runtime Target**: The system runtime targets the last two major PHP versions, supporting both Alpine and Debian-based execution environments.
- **Credential Security**: All sensitive configurations—including cloud credentials, WordPress keys/salts, and database connection secrets—must **never** be committed to version control. Use environment variables.
- **Deployment Isolation**: Actual production deployment configurations must be kept completely separate from this repository. Any deployment configuration files stored here should be strictly for local development or testing environments.

## WordPress Core Structure

- **Bedrock Integration**: We use [Bedrock](https://roots.io/bedrock/) to manage WordPress. This enforces a modern, clean directory structure, separates the web root, and allows version locking of WordPress core via Composer.

## Package & Plugin Selection

- **Simplicity First**: Keep the dependency footprint as minimal as possible.
- **Dependency Management**: All external PHP libraries, themes, and plugins must be installed and managed programmatically using Composer.
- **Third-Party Packages**: When selecting third-party plugins or packages (aside from our custom-written plugins), prioritize public, free-to-use options with strong active maintenance from established organizations or companies.

## Observability

- **OpenTelemetry (OTEL)**: The application must be highly observable. Implement tracing, metrics, and logging through OpenTelemetry integrations designed for PHP and WordPress.
