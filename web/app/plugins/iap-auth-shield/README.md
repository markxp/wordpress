# GCP IAP Auth & API Shield (Stateless)

A WordPress plugin to provide identity-aware authentication and a REST API firewall for WordPress sites running behind Google Cloud IAP (Identity-Aware Proxy).

## Features

- **IAP JWT Verification**: Automatically verifies Google IAP JWT assertions to map Google identities to WordPress users.
- **Auto-Login**: Gracefully logs in recognized employees and redirects them to the admin dashboard.
- **REST API Firewall**:
  - Protects sensitive API endpoints.
  - Whitelists common read-only endpoints (Posts, Pages, etc.) for external visitors.
  - Special bypass for comments to allow external POST requests.
- **Stateless Configuration**: Controlled entirely via environment variables.

## Configuration

The plugin uses the following environment variables:

- `IAP_AUTH_ENABLED`: Set to `true` to enable the plugin.
- `IAP_AUDIENCE`: The IAP Audience string (e.g., `/projects/PROJECT_NUMBER/global/backendServices/SERVICE_ID`).

## Installation

1. Copy the plugin folder to `wp-content/plugins/`.
2. Ensure you have the `firebase/php-jwt` library installed via Composer or available in the `vendor` folder.
3. Configure the required environment variables.
4. Activate the plugin in the WordPress admin panel.
