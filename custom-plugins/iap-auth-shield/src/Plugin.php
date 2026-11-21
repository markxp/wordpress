<?php

/**
 * Main Plugin Class - Handles Google Cloud IAP identity mapping, REST API firewall, and Instance Status reporting.
 *
 * @package IapAuthShield
 */

namespace IapAuthShield;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

class Plugin
{
    private $audience;

    // Define API whitelist for external visitors read-only (GET) access (for themes like Twenty Twenty)
    private $api_whitelist = [
        '/wp/v2/posts',
        '/wp/v2/pages',
        '/wp/v2/media',
        '/wp/v2/categories',
        '/wp/v2/tags',
        '/wp/v2/taxonomies',
        '/wp/v2/types',
        '/wp/v2/comments',
    ];

    public function __construct()
    {
        $this->audience = $this->resolve_env_value('IAP_AUDIENCE');

        // Register Admin UI Hooks
        if (is_admin()) {
            add_action('admin_menu', [$this, 'register_admin_menu']);
            add_filter('plugin_action_links_' . plugin_basename(__DIR__ . '/../iap-auth-shield.php'), [$this, 'add_action_links']);
            add_action('after_plugin_row_' . plugin_basename(__DIR__ . '/../iap-auth-shield.php'), [$this, 'render_plugin_row_status'], 10, 2);
        }

        // Functional Hooks (Only registered when both IAP_AUTH_ENABLED=true and IAP_AUDIENCE is set)
        if ($this->is_instance_on()) {
            // Hook 1: Handle identity verification and auto-login (init stage, priority 0 before priority-1 gatekeepers)
            add_action('init', [$this, 'handle_iap_authentication'], 0);

            // Hook 2: REST API Firewall (Intercept before API dispatch)
            add_filter('rest_pre_dispatch', [$this, 'api_firewall'], 10, 3);

            // Hook 3: Normalize internal Editor domain URLs (/app/..., /wp/...) to root-relative paths on save
            add_filter('wp_insert_post_data', [$this, 'normalize_editor_urls_in_post_data'], 10, 2);
        }
    }

    /**
     * Resolve configuration from WordPress constants (e.g. wp-config.php or environment configuration),
     * falling back to thread-safe $_ENV / $_SERVER or getenv() for standalone portability.
     */
    protected function resolve_env_value(string $name): string
    {
        if (defined($name)) {
            $val = constant($name);
            return is_bool($val) ? ($val ? 'true' : 'false') : (string) $val;
        }

        if (isset($_ENV[$name]) && $_ENV[$name] !== '') {
            return (string) $_ENV[$name];
        }

        if (isset($_SERVER[$name]) && $_SERVER[$name] !== '') {
            return (string) $_SERVER[$name];
        }

        $env = getenv($name);
        return $env !== false ? (string) $env : '';
    }

    /**
     * Check if Environment Variable toggle is enabled.
     */
    public function is_env_enabled(): bool
    {
        $enabled = $this->resolve_env_value('IAP_AUTH_ENABLED');
        return filter_var($enabled, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Check if Audience configuration is set.
     */
    public function is_audience_configured(): bool
    {
        return !empty($this->audience);
    }

    /**
     * Check effective instance status (ON only when Env Var = true AND Audience is set AND Database = Active).
     */
    public function is_instance_on(): bool
    {
        return $this->is_env_enabled() && $this->is_audience_configured();
    }

    /**
     * Register admin page without displaying it in the sidebar menu.
     */
    public function register_admin_menu()
    {
        add_submenu_page(
            'options.php',
            'IAP Auth Shield Status',
            'IAP Auth Shield',
            'manage_options',
            'iap-auth-shield',
            [$this, 'render_admin_status_page'],
        );
    }

    /**
     * Add Settings link on Plugins list page.
     */
    public function add_action_links($links)
    {
        $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=iap-auth-shield')) . '">' . __('Settings', 'iap-auth-shield') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Display status badge on wp-admin/plugins.php table row.
     */
    public function render_plugin_row_status($plugin_file, $plugin_data)
    {
        $is_on = $this->is_instance_on();
        $env_enabled = $this->is_env_enabled();
        $audience_configured = $this->is_audience_configured();

        $badge_style = $is_on
            ? 'background:#10b981; color:#fff;'
            : 'background:#f59e0b; color:#fff;';
        $status_text = $is_on ? '● Instance: ON' : '○ Instance: OFF';

        echo '<tr class="plugin-update-tr"><td colspan="3" class="plugin-update colspanchange"><div class="update-message notice inline notice-alt" style="margin:5px 0 5px 0; border-left-color: ' . ($is_on ? '#10b981' : '#f59e0b') . ';">';
        echo '<p><span style="' . esc_attr($badge_style) . ' padding:3px 8px; border-radius:12px; font-weight:600; font-size:11px; margin-right:8px;">' . esc_html($status_text) . '</span>';
        echo '<strong>Instance Requirement Status:</strong> Env Var (<code>IAP_AUTH_ENABLED</code>) = <code>' . ($env_enabled ? 'true' : 'false') . '</code> | Audience (<code>IAP_AUDIENCE</code>) = <code>' . ($audience_configured ? 'Set' : 'Missing') . '</code>. ';
        if (!$is_on) {
            echo '<span style="color:#d97706;">(Instance protection is OFF because required environment variables <code>IAP_AUTH_ENABLED=true</code> and non-empty <code>IAP_AUDIENCE</code> are not met).</span>';
        }
        echo '</p></div></td></tr>';
    }

    /**
     * Render the admin status page.
     */
    public function render_admin_status_page()
    {
        $is_on = $this->is_instance_on();
        $env_enabled = $this->is_env_enabled();
        $audience_configured = $this->is_audience_configured();
        ?>
        <div class="wrap">
            <h1>GCP IAP Auth & API Shield Status</h1>

            <div style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:8px; margin-top:20px; max-width:800px;">
                <h2 style="margin-top:0;">Instance Operational Indicator</h2>
                <div style="display:flex; align-items:center; gap:15px; margin-bottom:20px;">
                    <?php if ($is_on) : ?>
                        <span style="background:#10b981; color:#fff; padding:8px 16px; border-radius:20px; font-size:18px; font-weight:bold;">
                            ● Instance Status: ON
                        </span>
                        <span style="color:#10b981; font-weight:600;">Full IAP Identity Mapping & REST API Firewall Protection is Active.</span>
                    <?php else : ?>
                        <span style="background:#ef4444; color:#fff; padding:8px 16px; border-radius:20px; font-size:18px; font-weight:bold;">
                            ○ Instance Status: OFF
                        </span>
                        <span style="color:#ef4444; font-weight:600;">Protection Disabled (Required environment variables missing or OFF).</span>
                    <?php endif; ?>
                </div>

                <table class="widefat fixed striped" style="margin-top:15px;">
                    <thead>
                        <tr>
                            <th>Required Environment Switch</th>
                            <th>Current Value</th>
                            <th>Required for ON</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Plugin Enabled (<code>IAP_AUTH_ENABLED</code>)</strong></td>
                            <td><code><?php echo $env_enabled ? 'true' : 'false'; ?></code></td>
                            <td><code>true</code></td>
                        </tr>
                        <tr>
                            <td><strong>Target Audience (<code>IAP_AUDIENCE</code>)</strong></td>
                            <td><code><?php echo esc_html($this->audience ?: 'NOT CONFIGURED'); ?></code></td>
                            <td>Valid Google IAP Audience Client ID</td>
                        </tr>
                        <tr>
                            <td><strong>Database Plugin Switch</strong></td>
                            <td><code>Active</code></td>
                            <td>Plugin activated / loaded</td>
                        </tr>
                        <tr>
                            <td><strong>Combined Operational State</strong></td>
                            <td><strong><?php echo $is_on ? '<span style="color:#10b981;">ON (Protected)</span>' : '<span style="color:#ef4444;">OFF (Unprotected)</span>'; ?></strong></td>
                            <td>All env vars well-set & Plugin active</td>
                        </tr>
                    </tbody>
                </table>

                <div style="margin-top:20px; padding:12px; background:#f9fafb; border-left:4px solid #3b82f6;">
                    <h4 style="margin:0 0 5px 0;">Requirements to turn ON this instance:</h4>
                    <p style="margin:0;">Set environment variables <code>IAP_AUTH_ENABLED=true</code> and non-empty <code>IAP_AUDIENCE</code> in your environment file, and ensure the plugin is activated in WordPress.</p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Core Logic 1: Handle backend access and opportunistic IAP identity mapping
     */
    public function handle_iap_authentication()
    {
        $is_login_page = in_array($GLOBALS['pagenow'] ?? '', ['wp-login.php'], true);
        $is_protected_entry = is_admin() || $is_login_page;

        $raw_jwt = $_SERVER['HTTP_X_GOOG_IAP_JWT_ASSERTION'] ?? null;
        $jwt = is_string($raw_jwt) ? trim($raw_jwt) : '';

        // Frontend remains open to visitors when no IAP assertion header is present.
        if (!$is_protected_entry && $jwt === '') {
            return;
        }

        if (!$this->audience) {
            if ($is_protected_entry) {
                wp_die('IAP Auth Configuration Error: IAP_AUDIENCE is missing. Backend access is disabled for security.', 'Configuration Error', ['response' => 500]);
            }
            return;
        }

        // If trying to access wp-admin or wp-login.php without IAP JWT assertion header, block access.
        if ($jwt === '') {
            wp_die('Access Denied: Missing IAP credentials. Please access through the correct domain.', 'Unauthorized', ['response' => 401]);
            return;
        }

        // Fast-path: If the WordPress session is already authenticated, matches the IAP header email,
        // and (on protected admin/login routes) holds a valid admin auth cookie, skip OpenSSL & DB lookup.
        if (is_user_logged_in()) {
            $current_user = function_exists('wp_get_current_user') ? wp_get_current_user() : null;
            $current_user_id = is_object($current_user) && isset($current_user->ID) ? (int) $current_user->ID : 0;
            $current_email = is_object($current_user) && !empty($current_user->user_email) ? (string) $current_user->user_email : '';
            $header_email = $this->extract_iap_email_header();
            $has_valid_cookie = !$is_protected_entry
                || ($current_user_id > 0 && $this->has_valid_admin_auth_cookie($current_user_id));

            if (
                $current_email !== ''
                && $header_email !== ''
                && strcasecmp($current_email, $header_email) === 0
                && $has_valid_cookie
            ) {
                if ($is_login_page) {
                    $this->handle_wp_login_completion();
                }
                return;
            }
        }

        try {
            $payload = $this->verify_jwt($jwt);
        } catch (\Exception $e) {
            wp_die('IAP Verification Failed: ' . esc_html($e->getMessage()), 'Forbidden', ['response' => 403]);
            return;
        }

        if (!$payload || empty($payload['email'])) {
            wp_die('IAP Verification Failed: Payload empty or missing email.', 'Forbidden', ['response' => 403]);
            return;
        }

        $email = $payload['email'];
        $user = get_user_by('email', $email);

        if ($user) {
            $current_user = is_user_logged_in() && function_exists('wp_get_current_user') ? wp_get_current_user() : null;
            $current_email = is_object($current_user) && !empty($current_user->user_email) ? (string) $current_user->user_email : '';
            $needs_cookie = !is_user_logged_in()
                || strcasecmp($current_email, (string) $email) !== 0
                || ($is_protected_entry && !$this->has_valid_admin_auth_cookie((int) $user->ID));

            if ($needs_cookie) {
                $this->establish_wp_session((int) $user->ID);
            }

            if ($is_login_page) {
                $this->handle_wp_login_completion();
            }
            return;
        }

        if ($is_protected_entry) {
            // Google authenticated, but user account not found in WordPress database
            $escaped_email = esc_html((string) $email);
            wp_die("Your Google account ({$escaped_email}) verified successfully, but there is no corresponding user in the system. Please contact the administrator.", 'Unauthorized', ['response' => 403]);
        }
    }

    /**
     * Establish the WordPress user session and immediately synchronize newly minted cookies
     * into $_COOKIE so same-request auth_redirect() checks succeed without an extra redirect.
     */
    protected function establish_wp_session(int $user_id): void
    {
        $sync_auth_cookie = function ($auth_cookie, $expire = 0, $expiration = 0, $uid = 0, $scheme = ''): void {
            if (!is_string($auth_cookie)) {
                return;
            }
            if ($scheme === 'secure_auth' && defined('SECURE_AUTH_COOKIE')) {
                $_COOKIE[SECURE_AUTH_COOKIE] = $auth_cookie;
            } elseif ($scheme === 'auth' && defined('AUTH_COOKIE')) {
                $_COOKIE[AUTH_COOKIE] = $auth_cookie;
            }
        };
        $sync_logged_in_cookie = function ($logged_in_cookie): void {
            if (is_string($logged_in_cookie) && defined('LOGGED_IN_COOKIE')) {
                $_COOKIE[LOGGED_IN_COOKIE] = $logged_in_cookie;
            }
        };

        add_action('set_auth_cookie', $sync_auth_cookie, 10, 5);
        add_action('set_logged_in_cookie', $sync_logged_in_cookie, 10, 1);
        try {
            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id);
        } finally {
            if (function_exists('remove_action')) {
                remove_action('set_auth_cookie', $sync_auth_cookie, 10);
                remove_action('set_logged_in_cookie', $sync_logged_in_cookie, 10);
            }
        }
    }

    /**
     * Verify that the admin authentication cookie (SECURE_AUTH_COOKIE or AUTH_COOKIE)
     * is valid for the expected user ID.
     */
    protected function has_valid_admin_auth_cookie(int $expected_user_id): bool
    {
        if (!function_exists('wp_validate_auth_cookie')) {
            return true;
        }

        $scheme = (function_exists('is_ssl') && is_ssl()) ? 'secure_auth' : 'auth';

        return (int) wp_validate_auth_cookie('', $scheme) === $expected_user_id;
    }

    /**
     * Complete wp-login.php requests cleanly after IAP authentication has been established.
     */
    protected function handle_wp_login_completion(): void
    {
        if (!empty($_REQUEST['interim-login'])) {
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body class="login interim-login-success"></body></html>';
            $this->terminate_request();
            return;
        }

        $action = isset($_REQUEST['action']) && is_string($_REQUEST['action']) ? $_REQUEST['action'] : 'login';
        if ($action === '' || $action === 'login') {
            $target = admin_url();
            if (!empty($_REQUEST['redirect_to']) && is_string($_REQUEST['redirect_to'])) {
                $target = function_exists('wp_validate_redirect')
                    ? wp_validate_redirect($_REQUEST['redirect_to'], $target)
                    : $target;
            }
            wp_safe_redirect($target);
            $this->terminate_request();
        }
    }

    /**
     * Terminate the current PHP request (extracted for isolated unit testing).
     */
    protected function terminate_request(): void
    {
        exit;
    }

    /**
     * Core Logic 2: REST API Firewall (Read-only public whitelist)
     */
    public function api_firewall($result, $server, $request)
    {
        $route = $request->get_route();
        $method = $request->get_method();

        // 1. If it's an employee (already logged in), allow all API operations
        if (is_user_logged_in()) {
            return $result;
        }

        // 2. Check regular whitelist (extensible via filter)
        $whitelist = apply_filters('iap_auth_shield_api_whitelist', $this->api_whitelist);
        $is_whitelisted = false;
        foreach ($whitelist as $allowed_route) {
            if (strpos($route, $allowed_route) === 0) {
                $is_whitelisted = true;
                break;
            }
        }

        // 3. If within the regular whitelist and it's a GET request, allow the theme to fetch content smoothly
        if ($is_whitelisted && $method === 'GET') {
            return $result;
        }

        // 4. Block all other operations
        return new WP_Error(
            'rest_forbidden',
            'No permission to access this API endpoint.',
            ['status' => 401],
        );
    }

    /**
     * Core Logic 3: Strip internal Editor origin (home_url()) from local /app/ and /wp/ asset URLs
     * when saving posts, pages, templates, or patterns in the Editor service.
     *
     * @param array $data    An array of slashed, sanitized, and processed post data.
     * @param array $postarr An array of sanitized (and slashed) but otherwise unmodified post data.
     * @return array Filtered post data with root-relative local asset URLs.
     */
    public function normalize_editor_urls_in_post_data($data, $postarr = [])
    {
        if (!is_array($data) || empty($data['post_content']) || !is_string($data['post_content'])) {
            return $data;
        }

        $data['post_content'] = $this->strip_current_origin_from_local_paths($data['post_content']);

        return $data;
    }

    /**
     * Replace http(s)://<current-request-host>/(app|wp)/... with /(app|wp)/...
     * Preserves both standard slashes and JSON-escaped slashes in Gutenberg block attributes.
     */
    public function strip_current_origin_from_local_paths(string $content): string
    {
        $home_url = function_exists('home_url') ? rtrim((string) home_url(), '/') : '';
        if ($home_url === '') {
            return $content;
        }

        $host = wp_parse_url($home_url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return $content;
        }

        $quoted_host = preg_quote($host, '#');
        $pattern = '#https?:(?:\\\\)*/(?:\\\\)*/' . $quoted_host . '(?::\d+)?((?:\\\\)*/(?:app|wp)(?:\\\\)*/)#i';

        return (string) preg_replace($pattern, '$1', $content);
    }

    /**
     * Extract the authenticated email from HTTP_X_GOOG_AUTHENTICATED_USER_EMAIL if present
     * (e.g. "accounts.google.com:user@example.com" -> "user@example.com").
     */
    protected function extract_iap_email_header(): string
    {
        $raw = $_SERVER['HTTP_X_GOOG_AUTHENTICATED_USER_EMAIL'] ?? '';
        if (!is_string($raw) || $raw === '') {
            return '';
        }

        $pos = strpos($raw, ':');
        return $pos !== false ? substr($raw, $pos + 1) : $raw;
    }

    protected function get_google_iap_keys(bool $force_refresh = false)
    {
        $transient_key = 'gcp_iap_public_keys';
        $keys = $force_refresh ? false : get_transient($transient_key);

        if ($force_refresh || !is_array($keys) || $keys === []) {
            $response = wp_remote_get('https://www.gstatic.com/iap/verify/public_key', ['timeout' => 3]);
            if (is_wp_error($response)) {
                throw new \Exception('Unable to fetch Google public keys - ' . $response->get_error_message());
            }
            if (function_exists('wp_remote_retrieve_response_code')) {
                $code = (int) wp_remote_retrieve_response_code($response);
                if ($code !== 200) {
                    throw new \Exception('Unexpected HTTP status fetching Google public keys: ' . $code);
                }
            }
            $body = wp_remote_retrieve_body($response);
            $decoded = is_string($body) ? json_decode($body, true) : null;
            if (!$this->is_valid_iap_key_map($decoded)) {
                throw new \Exception('Invalid Google IAP public keys payload.');
            }
            $keys = $decoded;
            set_transient($transient_key, $keys, HOUR_IN_SECONDS);
        }

        return $keys;
    }

    /**
     * Ensure decoded IAP public keys form a non-empty associative map of non-empty string PEM values.
     */
    protected function is_valid_iap_key_map($keys): bool
    {
        if (!is_array($keys) || $keys === [] || array_is_list($keys) || isset($keys['error'])) {
            return false;
        }

        foreach ($keys as $kid => $pem) {
            if (trim((string) $kid) === '' || !is_string($pem) || trim($pem) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Extract the unverified key ID ("kid") from the JWT header segment.
     */
    protected function extract_jwt_kid(string $jwt): string
    {
        $parts = explode('.', $jwt, 3);
        if (count($parts) !== 3 || $parts[0] === '') {
            return '';
        }

        $remainder = strlen($parts[0]) % 4;
        $padded = $remainder > 0 ? $parts[0] . str_repeat('=', 4 - $remainder) : $parts[0];
        $raw = base64_decode(strtr($padded, '-_', '+/'), true);
        if (!is_string($raw) || $raw === '') {
            return '';
        }

        $header = json_decode($raw, true);

        return is_array($header) && isset($header['kid']) && (is_string($header['kid']) || is_int($header['kid']))
            ? (string) $header['kid']
            : '';
    }

    protected function verify_jwt($jwt)
    {
        if (!class_exists(JWT::class)) {
            throw new \Exception('Firebase\JWT\JWT class not found. Ensure Composer dependencies are installed.');
        }

        $public_keys = $this->get_google_iap_keys();
        $kid = is_string($jwt) ? $this->extract_jwt_kid($jwt) : '';
        if ($kid !== '' && (!is_array($public_keys) || !isset($public_keys[$kid]))) {
            $public_keys = $this->get_google_iap_keys(true);
        }

        if (!$public_keys || !is_array($public_keys)) {
            throw new \Exception('Public keys could not be loaded.');
        }

        $key_objects = [];
        foreach ($public_keys as $key_id => $pem) {
            $key_objects[$key_id] = new Key($pem, 'ES256');
        }

        try {
            $decoded = JWT::decode((string) $jwt, $key_objects);
        } catch (\Exception $e) {
            throw new \Exception('JWT verification failed -> ' . $e->getMessage());
        }

        $issuer = isset($decoded->iss) && is_string($decoded->iss) ? $decoded->iss : '';
        if ($issuer !== 'https://cloud.google.com/iap') {
            throw new \Exception('Invalid issuer -> ' . $issuer);
        }

        $audience = isset($decoded->aud) && is_string($decoded->aud) ? $decoded->aud : '';
        if ($audience !== $this->audience) {
            throw new \Exception('Invalid audience. Expected: ' . $this->audience . ' but got: ' . $audience);
        }

        return (array) $decoded;
    }
}
