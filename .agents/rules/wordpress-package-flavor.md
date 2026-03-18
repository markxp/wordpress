---
trigger: model_decision
description: This emphisis our wordpress build (with php, without system packages)
---

* Principle of rules

We emphasis on application reusability. This repository should include all wordpress-related pacakges and scripts.

The runtime of system should involve the latest 2 PHP version, and both alpine, debian flavor.
All sensitive data, such as cloud resource's credential, wordpress's credential, database connection credential should NOT be added into version control system.

The deployment configuration will be seperated from this repository. The only possible deployment configuration in this repository should be for testing purpose, and NOT involved in any real outward system.

* WordPress core

we are using "<https://roots.io/bedrock/>", which is a modernized, packaged, and nicer structured than original WordPress. This enables us to reproduce and control WordPress core version with PHP's package control system easily.

* WordPress Package Chooses

The more simple, the better. Except our own self-written pacakge, we should only choose public, free to use, and built by company or organization to keep the support is avaiable.

We should control all PHP packages through PHP package installer (composer).

* WordPress observability

We must keep our system observable. The approach of tracing, metrics, and logging is by importing OpenTelemetry support. This is important in PHP and WordPress.
