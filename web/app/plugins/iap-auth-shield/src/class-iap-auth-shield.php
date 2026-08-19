<?php

/**
 * Main Plugin Class - Handles Google Cloud IAP identity mapping, REST API firewall, and Instance Status reporting.
 *
 * @package IAP_Auth_Shield
 */

namespace IAP_Auth_Shield;

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
    ];

    public function __construct()
    {
        $this->audience = getenv('IAP_AUDIENCE') ?: (defined('IAP_AUDIENCE') ? IAP_AUDIENCE : '');

        // Register Admin UI Hooks
        if (is_admin()) {
            add_action('admin_menu', [$this, 'register_admin_menu']);
            add_filter('plugin_action_links_' . plugin_basename(__DIR__ . '/../iap-auth-shield.php'), [$this, 'add_action_links']);
            add_action('after_plugin_row_' . plugin_basename(__DIR__ . '/../iap-auth-shield.php'), [$this, 'render_plugin_row_status'], 10, 2);
        }

        // Functional Hooks (Only registered when both IAP_AUTH_ENABLED=true and IAP_AUDIENCE is set)
        if ($this->is_instance_on()) {
            // Hook 1: Handle identity verification and auto-login (init stage)
            add_action('init', [$this, 'handle_iap_authentication']);

            // Hook 2: REST API Firewall (Intercept before API dispatch)
            add_filter('rest_pre_dispatch', [$this, 'api_firewall'], 10, 3);
        }
    }

    /**
     * Check if Environment Variable toggle is enabled.
     */
    public function is_env_enabled(): bool
    {
        $enabled = getenv('IAP_AUTH_ENABLED') ?: (defined('IAP_AUTH_ENABLED') ? IAP_AUTH_ENABLED : false);
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
     * Register Settings Menu item.
     */
    public function register_admin_menu()
    {
        add_options_page(
            'IAP Auth Shield Status',
            'IAP Auth Shield',
            'manage_options',
            'iap-auth-shield',
            [$this, 'render_admin_status_page'],
        );
    }

    /**
     * Add Settings/Status link on Plugins list page.
     */
    public function add_action_links($links)
    {
        $status_link = '<a href="' . esc_url(admin_url('options-general.php?page=iap-auth-shield')) . '">Status & Settings</a>';
        array_unshift($links, $status_link);
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
     * Core Logic 1: Handle backend access and identity mapping
     */
    public function handle_iap_authentication()
    {
        // Only enforce verification on "backend admin pages" or "login page". Frontend is open to visitors.
        if (!is_admin() && !in_array($GLOBALS['pagenow'] ?? '', ['wp-login.php'], true)) {
            return;
        }

        if (!$this->audience) {
            wp_die('IAP Auth Configuration Error: IAP_AUDIENCE is missing. Backend access is disabled for security.', 'Configuration Error', ['response' => 500]);
            return;
        }

        $jwt = $_SERVER['HTTP_X_GOOG_IAP_JWT_ASSERTION'] ?? null;

        // If trying to access wp-admin but without IAP JWT assertion header, block access.
        if (!$jwt) {
            wp_die('Access Denied: Missing IAP credentials. Please access through the correct domain.', 'Unauthorized', ['response' => 401]);
            return;
        }

        try {
            $payload = $this->verify_jwt($jwt);
        } catch (\Exception $e) {
            wp_die('IAP Verification Failed: ' . $e->getMessage(), 'Forbidden', ['response' => 403]);
            return;
        }

        if (!$payload || empty($payload['email'])) {
            wp_die('IAP Verification Failed: Payload empty or missing email.', 'Forbidden', ['response' => 403]);
            return;
        }

        $email = $payload['email'];
        $user = get_user_by('email', $email);

        if ($user) {
            // Generate session cookie for auto-login
            if (!is_user_logged_in() || wp_get_current_user()->user_email !== $email) {
                wp_set_current_user($user->ID);
                wp_set_auth_cookie($user->ID);

                // Auto redirect to backend from wp-login.php
                if (($GLOBALS['pagenow'] ?? '') === 'wp-login.php') {
                    wp_safe_redirect(admin_url());
                    exit;
                }
            }
        } else {
            // Google authenticated, but user account not found in WordPress database
            wp_die("Your Google account ({$email}) verified successfully, but there is no corresponding user in the system. Please contact the administrator.", 'Unauthorized', ['response' => 403]);
            return;
        }
    }

    /**
     * Core Logic 2: REST API Firewall (Supports external comments)
     */
    public function api_firewall($result, $server, $request)
    {
        $route = $request->get_route();
        $method = $request->get_method();

        // 1. If it's an employee (already logged in), allow all API operations
        if (is_user_logged_in()) {
            return $result;
        }

        // 2. Dedicated channel for comment functionality (Allow external visitors to POST/GET comments)
        if (strpos($route, '/wp/v2/comments') === 0) {
            if ($method === 'POST' || $method === 'GET') {
                return $result;
            }
        }

        // 3. Check regular whitelist
        $is_whitelisted = false;
        foreach ($this->api_whitelist as $allowed_route) {
            if (strpos($route, $allowed_route) === 0) {
                $is_whitelisted = true;
                break;
            }
        }

        // 4. If within the regular whitelist and it's a GET request, allow the theme to fetch content smoothly
        if ($is_whitelisted && $method === 'GET') {
            return $result;
        }

        // 5. Block all other operations
        return new WP_Error(
            'rest_forbidden',
            'No permission to access this API endpoint.',
            ['status' => 401],
        );
    }

    protected function get_google_iap_keys()
    {
        $transient_key = 'gcp_iap_public_keys';
        $keys = get_transient($transient_key);

        if (false === $keys) {
            $response = wp_remote_get('https://www.gstatic.com/iap/verify/public_key');
            if (is_wp_error($response)) {
                throw new \Exception('Unable to fetch Google public keys - ' . $response->get_error_message());
            }
            $body = wp_remote_retrieve_body($response);
            $keys = json_decode($body, true);
            if ($keys) {
                set_transient($transient_key, $keys, HOUR_IN_SECONDS);
            }
        }
        return $keys;
    }

    protected function verify_jwt($jwt)
    {
        if (!class_exists('Firebase\JWT\JWT')) {
            throw new \Exception('Firebase\JWT\JWT class not found. Ensure Composer dependencies are installed.');
        }

        $public_keys = $this->get_google_iap_keys();
        if (!$public_keys) {
            throw new \Exception('Public keys could not be loaded.');
        }

        $key_objects = [];
        foreach ($public_keys as $kid => $pem) {
            $key_objects[$kid] = new Key($pem, 'ES256');
        }

        try {
            $decoded = JWT::decode($jwt, $key_objects);
        } catch (\Exception $e) {
            throw new \Exception('JWT verification failed -> ' . $e->getMessage());
        }

        if ($decoded->iss !== 'https://cloud.google.com/iap') {
            throw new \Exception('Invalid issuer -> ' . $decoded->iss);
        }

        if ($decoded->aud !== $this->audience) {
            throw new \Exception('Invalid audience. Expected: ' . $this->audience . ' but got: ' . $decoded->aud);
        }

        return (array) $decoded;
    }
}
