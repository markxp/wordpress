=== GCP IAP Auth & API Shield (Stateless) ===
Contributors: markxp
Tags: security, iap, gcp, authentication, firewall
Requires at least: 5.0
Tested up to: 6.5
Stable tag: 1.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

IAP authentication and REST API firewall controlled by system environment variables, protecting the backend while keeping the frontend open.

== Description ==

GCP IAP Auth & API Shield (Stateless) provides identity-aware authentication and a secure REST API firewall for WordPress sites running behind Google Cloud Identity-Aware Proxy (IAP).

=== Key Features ===
* **IAP JWT Verification**: Validates Google IAP assertion tokens securely against Google's public keys.
* **Seamless Auto-Login**: Automatically matches verified Google account emails to WordPress user profiles, generating an active session.
* **REST API Firewall**: Blocks unauthorized API access to sensitive endpoints for external users while maintaining public read access for general post/page content and comments.
* **Fully Stateless**: Configuration is controlled completely through environment variables, requiring no database options storage.

== Installation ==

1. Upload the `iap-auth-shield` folder to the `/wp-content/plugins/` directory.
2. Run `composer install` inside the plugin directory if composer dependencies are not packaged.
3. Configure the environment variables on your hosting platform:
   * `IAP_AUTH_ENABLED` (set to `true`)
   * `IAP_AUDIENCE` (the IAP Audience string, e.g., `/projects/PROJECT_NUMBER/global/backendServices/SERVICE_ID`)
4. Activate the plugin through the 'Plugins' menu in WordPress.

== Changelog ==

= 1.1.0 =
* Added support for external comments POST/GET bypass.
* Optimized public REST API endpoints whitelist.

= 1.0.0 =
* Initial release with IAP JWT verification and stateless authentication.
