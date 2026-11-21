=== GCP IAP Auth & API Shield (Stateless) ===
Contributors: markxp
Tags: security, iap, gcp, authentication, firewall
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.4
Stable tag: 1.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

IAP authentication and REST API firewall controlled by system environment variables, protecting the backend while keeping the frontend open.

== Description ==

GCP IAP Auth & API Shield (Stateless) provides identity-aware authentication and a secure REST API firewall for WordPress sites running behind Google Cloud Identity-Aware Proxy (IAP).

=== Key Features ===
* **IAP JWT Verification**: Validates Google IAP ES256 assertion tokens securely against Google's public keys.
* **Seamless Auto-Login**: Automatically matches verified Google account emails to WordPress user profiles, generating an active session with fast-path session reuse.
* **REST API Firewall**: Blocks unauthorized API access and anonymous write (`POST`) requests for external users while maintaining public read-only (`GET`) access for whitelisted content routes including `/wp/v2/comments` (extensible via `iap_auth_shield_api_whitelist`).
* **Editor Origin Normalization**: Strips internal IAP-protected origins from local asset paths when saving post content.
* **Fully Stateless**: Configuration is controlled completely through environment variables (`IAP_AUTH_ENABLED`, `IAP_AUDIENCE`), requiring no database options storage.

== Installation ==

1. Upload the `iap-auth-shield` folder to the `/wp-content/plugins/` directory.
2. Ensure `firebase/php-jwt` (`^7.0`) is installed via Composer at the project root or inside the plugin directory.
3. Configure the environment variables on your hosting platform:
   * `IAP_AUTH_ENABLED` (set to `true`)
   * `IAP_AUDIENCE` (the IAP Audience string, e.g., `/projects/PROJECT_NUMBER/global/backendServices/SERVICE_ID`)
4. Activate the plugin through the 'Plugins' menu in WordPress, and optionally inspect instance operational status by clicking **Settings** under **GCP IAP Auth & API Shield (Stateless)** on the **Plugins** screen.

== Changelog ==

= 1.1.0 =
* Added support for external comments POST/GET bypass.
* Optimized public REST API endpoints whitelist.

= 1.0.0 =
* Initial release with IAP JWT verification and stateless authentication.
