# Triggering WordPress Cron (`DISABLE_WP_CRON=true`)

When `DISABLE_WP_CRON=true` is set in `.env`, WordPress does not spawn loopback `/wp/wp-cron.php` requests on visitor page loads. However, WordPress Core and plugins still enqueue scheduled maintenance tasks into the autoloaded `cron` option in `wp_options`:

- `delete_expired_transients` (Daily cleanup of expired transients in `wp_options`)
- `wp_scheduled_auto_draft_delete` (Daily cleanup of 7-day-old `auto-draft` rows in `wp_posts`)
- `wp_scheduled_delete` (Daily cleanup of 30-day-old trashed posts and comments)
- `publish_future_post` (Scheduled post publication)
- Plugin background syncs (e.g., `google-site-kit` and `wp-stateless`)

To prevent `wp_options` and `wp_posts` bloat, an external scheduler must periodically send an HTTP `POST` (or `GET`) request to the writable **Editor** service:

```text
https://<editor-host>/wp/wp-cron.php?doing_wp_cron
```

Below are two recommended ways to trigger this endpoint when your Editor service requires Google OIDC authentication (such as Cloud Run IAM or IAP).

---

## Option 1: Zero-Cost Google Apps Script + `OAuth2` Service Account (Recommended)

Using a **Google Apps Script Time-Driven Trigger** with Google's official [`apps-script-oauth2`](https://github.com/googleworkspace/apps-script-oauth2) library is completely free (`$0.00/month`) and works independently of any user session by authenticating as a dedicated GCP Service Account.

### 1. Prerequisite IAM Setup
1. Create a dedicated Service Account (e.g. `wp-cron-caller@<PROJECT_ID>.iam.gserviceaccount.com`).
2. Grant the Service Account permission to invoke the Editor service:
   - **Cloud Run IAM**: `roles/run.invoker` (`Cloud Run Invoker`)
   - **If behind IAP**: `roles/iap.httpsResourceAccessor` (`IAP-secured Web App User`)
   - **Service Account Token Creator** (if using IAM Credentials API): `roles/iam.serviceAccountTokenCreator`

### 2. Add the Official Google `OAuth2` Library in Apps Script
1. Create a new project at [script.google.com](https://script.google.com).
2. In the left sidebar, click **Libraries (+)** and add the official [`apps-script-oauth2`](https://github.com/googleworkspace/apps-script-oauth2) Script ID:
   ```text
   1B7FSrk5Zi6L1rSxxTDgDEUsPzlukDsi4KGuTMorsTQHhGBzBkMun4iDF
   ```
3. Select the latest version and save with identifier **`OAuth2`**.

### 3. Configure Script Properties
Navigate to **Project Settings (⚙️) > Script Properties** and add:
- `CRON_URL`: `https://<editor-host>/wp/wp-cron.php?doing_wp_cron`
- `TARGET_AUDIENCE`: Your Editor service URL (`https://<editor-host>`) or OAuth2 Client ID (if behind IAP)
- `SA_CLIENT_EMAIL`: `wp-cron-caller@<PROJECT_ID>.iam.gserviceaccount.com`
- `SA_PRIVATE_KEY`: The `private_key` string from the Service Account JSON (`-----BEGIN PRIVATE KEY-----\n...`)

### 4. Apps Script Implementation (`Code.gs`)

`apps-script-oauth2` obtains an OAuth2 access token via JWT assertion, which we use with the Google IAM Credentials API (`generateIdToken`) to exchange for a signed OIDC `id_token` bound to `TARGET_AUDIENCE`:

```javascript
/**
 * Triggers the WordPress wp-cron.php endpoint on the Editor service using a Service Account OIDC token.
 * Schedule this function via Triggers -> Time-driven -> Minutes timer (Every 15 or 30 minutes).
 */
function triggerWordPressCron() {
  const props = PropertiesService.getScriptProperties();
  const cronUrl = props.getProperty('CRON_URL');
  const targetAudience = props.getProperty('TARGET_AUDIENCE');
  const clientEmail = props.getProperty('SA_CLIENT_EMAIL');
  const privateKey = (props.getProperty('SA_PRIVATE_KEY') || '').replace(/\\n/g, '\n');

  if (!cronUrl || !targetAudience || !clientEmail || !privateKey) {
    throw new Error('Missing required Script Properties: CRON_URL, TARGET_AUDIENCE, SA_CLIENT_EMAIL, or SA_PRIVATE_KEY.');
  }

  const oidcToken = getServiceAccountOidcToken_(clientEmail, privateKey, targetAudience);

  const response = UrlFetchApp.fetch(cronUrl, {
    method: 'post',
    headers: {
      Authorization: 'Bearer ' + oidcToken,
    },
    muteHttpExceptions: true,
    followRedirects: false,
  });

  const status = response.getResponseCode();
  if (status < 200 || status >= 300) {
    throw new Error('WP-Cron trigger failed with HTTP ' + status + ': ' + response.getContentText());
  }

  console.log('WP-Cron triggered successfully (HTTP ' + status + ')');
}

/**
 * Uses the official Google OAuth2 library to authenticate as the Service Account
 * and mint a cached OIDC ID token for the target audience via IAM Credentials API.
 */
function getServiceAccountOidcToken_(clientEmail, privateKey, targetAudience) {
  const cache = CacheService.getScriptCache();
  const cacheKey = 'oidc_' + Utilities.base64EncodeWebSafe(targetAudience).slice(0, 32);
  const cachedToken = cache.get(cacheKey);
  if (cachedToken) {
    return cachedToken;
  }

  // 1. Initialize googleworkspace/apps-script-oauth2 Service Account flow
  const oauthService = OAuth2.createService('WP_Cron_SA')
    .setTokenUrl('https://oauth2.googleapis.com/token')
    .setPrivateKey(privateKey)
    .setIssuer(clientEmail)
    .setScope('https://www.googleapis.com/auth/cloud-platform')
    .setPropertyStore(PropertiesService.getScriptProperties())
    .setCache(cache);

  if (!oauthService.hasAccess()) {
    throw new Error('OAuth2 Service Account error: ' + oauthService.getLastError());
  }

  // 2. Mint an OIDC ID token for TARGET_AUDIENCE via IAM Credentials API
  const iamUrl =
    'https://iamcredentials.googleapis.com/v1/projects/-/serviceAccounts/' +
    encodeURIComponent(clientEmail) +
    ':generateIdToken';

  const res = UrlFetchApp.fetch(iamUrl, {
    method: 'post',
    contentType: 'application/json',
    headers: {
      Authorization: 'Bearer ' + oauthService.getAccessToken(),
    },
    payload: JSON.stringify({
      audience: targetAudience,
      includeEmail: true,
    }),
    muteHttpExceptions: true,
  });

  if (res.getResponseCode() !== 200) {
    throw new Error('Failed to generate OIDC ID token: ' + res.getContentText());
  }

  const idToken = JSON.parse(res.getContentText()).token;
  // Cache OIDC token for 50 minutes (Google OIDC tokens are valid for 60 minutes)
  cache.put(cacheKey, idToken, 3000);
  return idToken;
}
```

### 5. Enable Time-Driven Trigger
In the Apps Script Editor, open **Triggers (⏰) > Add Trigger**:
- **Function to run**: `triggerWordPressCron`
- **Event source**: `Time-driven`
- **Type of time based trigger**: `Minutes timer` (`Every 15 minutes` or `Every 30 minutes`)

---

## Option 2: Google Cloud Scheduler (Managed GCP Cron)

If you prefer managed GCP infrastructure over Apps Script:

```bash
gcloud scheduler jobs create http wp-cron-editor \
  --location="<REGION>" \
  --schedule="*/15 * * * *" \
  --uri="https://<editor-host>/wp/wp-cron.php?doing_wp_cron" \
  --http-method="POST" \
  --oidc-service-account-email="wp-cron-caller@<PROJECT_ID>.iam.gserviceaccount.com" \
  --oidc-token-audience="<TARGET_AUDIENCE>"
```
