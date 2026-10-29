---
name: sitekit-application-integration
description: >-
  Guides application-side integration, configuration, and troubleshooting of
  Google Site Kit and the Site Kit Helper Plugin in Roots Bedrock WordPress. Covers
  Twin-Service (Reader/Editor) setup, custom OAuth credentials behind IAP, and domain verification lifecycle.
---

# Site Kit Application Integration (Bedrock WordPress)

This skill provides operational procedures for integrating official [Site Kit by Google](https://sitekit.withgoogle.com/) and the official [Site Kit helper plugin](https://sitekit.withgoogle.com/documentation/how-tos/staging/) (`google-site-kit-dev-settings`) into a Roots Bedrock WordPress codebase.

---

## 1. Twin-Service Architecture & Domain Decoupling

In a serverless WordPress deployment (e.g. Google Cloud Run paired services):
- **Reader Service (Public)**: Serves public frontend traffic at `https://example.com`. Stateless, heavily cached, stripped of administrative overhead.
- **Editor Service (Private)**: Runs behind Google Cloud Identity-Aware Proxy (IAP) at `https://editor.example.com` (or Cloud Run direct host), where administrators and post editors log in to `/wp-admin` to author content, publish posts, and view Site Kit metrics.

### Staging / Multi-Domain Alignment
As documented in Google's official [How to use Site Kit with a staging environment](https://sitekit.withgoogle.com/documentation/how-tos/staging/) guide:
- WordPress and Site Kit resolve the current host from `home_url()`.
- On the Editor service, `home_url()` points to the administrative domain (`https://editor.example.com`).
- To report on and synchronize data for the public canonical site (`https://example.com`), Google provides the **Site Kit helper plugin** to override the reference URL and Search Console property mapping.

---

## 2. Official Google Plugins & Capabilities

### A. Core Plugin: `google-site-kit`
- **Distribution**: Packagist via Composer (`wp-plugin/google-site-kit`).
- **Function**: Official integration with Google Search Console, Google Analytics 4 (GA4), and PageSpeed Insights.
- **Native Domain Verification Support**:
  - Site Kit supports all official Google Search Console verification methods (HTML `<meta>` tag, DNS TXT record, HTML file upload, Google Analytics, and Google Tag Manager).
  - *DNS Pre-Verification Behavior*: When site ownership is already verified in Google Search Console (e.g. via a DNS TXT record on `sc-domain:example.com`) **prior** to connecting Site Kit, the Search Console API returns `'verified' => true`. Site Kit flags the user as verified in `wp_usermeta` (`googlesitekit_site_verified_meta = 1`) and skips generating or injecting any `<meta name="google-site-verification">` tag into public HTML.

### B. Helper Plugin: `google-site-kit-dev-settings`
- **Distribution**: Official Google helper plugin downloaded from [`https://sitekit.withgoogle.com/service/download/google-site-kit-dev-settings.zip`](https://sitekit.withgoogle.com/service/download/google-site-kit-dev-settings.zip), as referenced in the [Site Kit Staging Documentation](https://sitekit.withgoogle.com/documentation/how-tos/staging/). In this Bedrock repo, it is committed to `web/app/plugins/google-site-kit-dev-settings/` (whitelisted in `.gitignore`).
- **Function**: Adds an advanced configuration screen under **Site Kit > Developer Settings** (`/wp-admin/admin.php?page=googlesitekit-dev-settings`).
- **Core Settings**:
  1. **Custom Site URL (`site_url`)**: Overrides `googlesitekit_site_url`. Tells Site Kit on the Editor service to query and report insights for the public Reader domain (`https://example.com`).
  2. **Custom Search Console Property ID (`search_console_property_id`)**: The helper plugin automatically derives the Search Console property URL from the Custom Site URL. This field only needs to be populated when explicitly overriding with a domain property format (e.g., `sc-domain:example.com`).
  3. **Custom OAuth2 Credentials (`oauth2_client_id`, `oauth2_client_secret`)**:
     - *Why needed:* Standard Site Kit setup uses Google's public authentication proxy. However, when an environment is **password-protected or behind a login wall** (such as our Editor service protected by Google Cloud IAP), Google's public authentication proxy cannot communicate directly with the site.
     - Supplying custom OAuth2 credentials (generated in Google Cloud Console) bypasses the public proxy and establishes direct OAuth 2.0 communication between WordPress and Google APIs.

---

## 3. OAuth 2.0 Architecture, Callback URLs & Secret Storage

### A. GCP Project OAuth Consent Screen (Application Level)
At the Google Cloud project level (**APIs & Services > OAuth consent screen**), configure the combined scope allowlist for the entire application:
- **Site Kit Reporting Scopes**:
  - `https://www.googleapis.com/auth/siteverification`
  - `https://www.googleapis.com/auth/webmasters` (Search Console)
  - `https://www.googleapis.com/auth/analytics.readonly` (GA4)
  - `https://www.googleapis.com/auth/tagmanager.readonly` (Tag Manager, if used)
- **Sign in with Google Scopes (if used)**:
  - `openid`
  - `https://www.googleapis.com/auth/userinfo.email`
  - `https://www.googleapis.com/auth/userinfo.profile`

### B. Site Kit Administrative Reporting Client (Editor Behind IAP)
This client connects WordPress to Google Search Console and GA4 reporting APIs.

1. **Google Cloud Console Configuration**:
   - Navigate to **APIs & Services > Credentials > Create Credentials > OAuth client ID**.
   - **Application type**: Web application.
   - **Name**: e.g., `Site Kit Editor Backend`.
   - **Authorized JavaScript origins**:
     - `https://editor.example.com`
   - **Authorized redirect URIs**:
     - `https://editor.example.com/?oauth2callback=1`
     - `https://editor.example.com/wp/index.php?oauth2callback=1`
2. **Secret Storage (UI Storage)**:
   - Input the Client ID and Secret in `/wp-admin` on the Editor instance under **Site Kit > Developer Settings**.
   - Values are persisted into the database `wp_options` table under `googlesitekitdev_settings`.

### C. "Sign in with Google" (SiwG for End-Users / Frontend Visitors)
Site Kit provides an optional **Sign in with Google** module for blog commenters, subscribers, or WooCommerce customers.

* **Does it require a separate OAuth Client ID?**:
  - **Yes, strongly recommended**:
    1. **Different Audience & Scopes**: The Site Kit admin client requests administrative API access (Search Console, Analytics 4, Tag Manager). Sign in with Google uses Google Identity Services (GIS - `accounts.google.com/gsi/client`) solely for user identity (`openid`, `profile`, `email`).
    2. **Different Origins**: Sign in with Google runs on the public **Reader domain** (`https://example.com`). The Google Cloud Console OAuth Client must have `https://example.com` listed under **Authorized JavaScript origins**.
* **Configuration**: Configured independently in `/wp-admin` under **Site Kit > Settings > Sign in with Google** (stored in `wp_options` under `googlesitekit_sign-in-with-google_settings`).

---

## 4. Re-Connection & Verification Tag Lifecycle

### Does a VM / Container Restart Automatically Update the Verification Tag?
* **No**. Site Kit stores the verification meta tag in the persistent database (`wp_usermeta` table under `googlesitekit_site_verification_meta`), **not** in container memory or filesystem.
* When a Cloud Run pod or VM instance restarts, PHP does not re-query Google Search Console on boot or on frontend requests (to avoid page load latency).
* Therefore, if a verification meta tag was previously saved in the database before DNS TXT records were created, restarting the VM **will not** remove the meta tag.

### How to Adopt DNS-Only Verification and Purge the Meta Tag
To clear the stored meta tag and switch to DNS-only verification:
1. Complete the DNS TXT verification in [Google Search Console](https://search.google.com/search-console).
2. In WordPress on the Editor service, disconnect the user account (**User Profile > Disconnect from Site Kit**, or **Site Kit > Settings > Admin Settings > Reset Site Kit**).
3. **Internal Behavior**:
   - Site Kit executes `Authentication::disconnect()`, which runs:
     ```sql
     DELETE FROM wp_usermeta WHERE user_id = %d AND meta_key LIKE 'googlesitekit_%'
     ```
   - This **permanently purges** `googlesitekit_site_verification_meta` from the database.
4. Click **"Sign in with Google"** to reconnect:
   - Site Kit queries Google Search Console API.
   - Search Console confirms `'verified' => true` via DNS.
   - Site Kit flags `googlesitekit_site_verified_meta = 1` and **skips token generation entirely**.
   - No new verification meta tag is ever written to `wp_usermeta`.
5. Public Reader HTML is permanently clean of `<meta name="google-site-verification">` tags.

---

## 5. Step-by-Step Setup Guide

### Step 1: Configure Encryption Keys (`config/application.php`)
Configure dedicated encryption constants (optional, in `.env` or Google Secret Manager). If defined, ensure they are **identical across all services sharing the database** (e.g. Reader and Editor) so tokens and user sessions can be decrypted consistently across pods:

```php
/**
 * Google Site Kit Encryption Keys (Must match across all pods sharing the DB)
 */
if (env('GOOGLESITEKIT_ENCRYPTION_KEY')) {
    Config::define('GOOGLESITEKIT_ENCRYPTION_KEY', env('GOOGLESITEKIT_ENCRYPTION_KEY'));
}
if (env('GOOGLESITEKIT_ENCRYPTION_SALT')) {
    Config::define('GOOGLESITEKIT_ENCRYPTION_SALT', env('GOOGLESITEKIT_ENCRYPTION_SALT'));
}
```

### Step 2: Activate Plugins on Editor Service
In `/wp-admin` on the Editor instance:
```bash
wp plugin activate google-site-kit google-site-kit-dev-settings
```

### Step 3: Configure Site Kit Developer Settings
1. In `/wp-admin`, navigate to **Site Kit > Developer Settings**.
2. **Custom Site URL**: Enter the canonical public Reader URL (`https://example.com`).
3. **Custom Search Console Property ID**: Set to `sc-domain:example.com` (if using a domain property) or leave blank to inherit the Custom Site URL.
4. **OAuth2 Credentials (behind IAP)**: Enter the GCP OAuth 2.0 Web Client ID and Client Secret configured for your Editor domain.
5. Save Changes.

### Step 4: Complete Site Kit Onboarding
1. Click **"Sign in with Google"** on the Site Kit dashboard.
2. Complete the OAuth authorization flow and domain verification using your preferred Search Console method (e.g. DNS TXT record or HTML tag).

---

## 6. REST API Security & Communication Model

Site Kit's REST API endpoints (`/wp-json/google-site-kit/v1/*`) are internal administrative routes consumed strictly by authenticated users within `wp-admin`:
- **Editor Service**: Authenticated administrators and editors bypass REST restrictions in `security-hardening` and `iap-auth-shield` automatically.
- **Reader Service**: Public visitors have no access to Site Kit REST endpoints.

---

## 7. Verification Checklist

1. **Linting & Code Style**:
   ```bash
   composer lint:container
   ```
2. **Unit Tests**:
   ```bash
   composer test:container
   ```
3. **Developer Settings Validation**:
   - Verify that `/wp-admin/admin.php?page=googlesitekit-dev-settings` displays the public canonical URL (`https://example.com`).
4. **Encryption Keys (If Configured)**:
   ```bash
   wp eval 'echo defined("GOOGLESITEKIT_ENCRYPTION_KEY") ? "OK" : "DEFAULT_FALLBACK";'
   ```
