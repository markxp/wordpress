<?php
/**
 * Plugin Name: GCP IAP Auth & API Shield (Stateless)
 * Description: IAP authentication and REST API firewall controlled by system environment variables, protecting the backend while keeping the frontend open.
 * Version: 1.1.0
 * Author: Your Name
 */

if (!defined('ABSPATH')) exit;

// 1. Include Composer Autoload
$autoload_path = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload_path)) {
    require_once $autoload_path;
}

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

// 2. Read toggle environment variable (Protective mechanism, will not start if not set)
$iap_enabled = getenv('IAP_AUTH_ENABLED') ?: (defined('IAP_AUTH_ENABLED') ? IAP_AUTH_ENABLED : false);
if (filter_var($iap_enabled, FILTER_VALIDATE_BOOLEAN) === false) {
    return;
}

class IAP_Auth_Shield {
    private $audience;

    // Define API whitelist for external visitors read-only (GET) access (for themes like Twenty Twenty)
    private $api_whitelist = [
        '/wp/v2/posts',
        '/wp/v2/pages',
        '/wp/v2/media',
        '/wp/v2/categories',
        '/wp/v2/tags',
        '/wp/v2/taxonomies',
        '/wp/v2/types'
    ];

    public function __construct() {
        $this->audience = getenv('IAP_AUDIENCE');
        
        if (!$this->audience) {
            error_log('IAP Auth Error: IAP_AUDIENCE environment variable not found.');
            return; // Skip further actions if Audience is missing to avoid security misjudgment
        }

        // Hook 1: Handle identity verification and auto-login (init stage)
        add_action('init', [$this, 'handle_iap_authentication']);

        // Hook 2: REST API Firewall (Intercept before API dispatch)
        add_filter('rest_pre_dispatch', [$this, 'api_firewall'], 10, 3);
    }

    /**
     * Core Logic 1: Handle backend access and identity mapping
     */
    public function handle_iap_authentication() {
        // [Fix] Only enforce verification on "backend admin pages" or "login page". Frontend is open to visitors.
        if (!is_admin() && !in_array($GLOBALS['pagenow'], ['wp-login.php'])) {
            return;
        }

        $jwt = $_SERVER['HTTP_X_GOOG_IAP_JWT_ASSERTION'] ?? null;

        // 如果連線到 wp-admin 但沒有 JWT (可能是繞過 LB 直接打 IP)，直接阻擋
        if (!$jwt) {
            wp_die('Access Denied: Missing IAP credentials. Please access through the correct domain.', 'Unauthorized', ['response' => 401]);
        }

        $payload = $this->verify_jwt($jwt);

        if (!$payload || empty($payload['email'])) {
            wp_die('IAP Verification Failed: Invalid or expired credentials.', 'Forbidden', ['response' => 403]);
        }

        $email = $payload['email'];
        $user = get_user_by('email', $email);

        if ($user) {
            // [Fix] If the employee is found, generate a WordPress session for them to auto-login
            if (!is_user_logged_in() || wp_get_current_user()->user_email !== $email) {
                wp_set_current_user($user->ID);
                wp_set_auth_cookie($user->ID);
                
                // If they are on wp-login.php, automatically redirect them to the backend home page
                if ($GLOBALS['pagenow'] === 'wp-login.php') {
                    wp_safe_redirect(admin_url());
                    exit;
                }
            }
        } else {
            // Employee passed Google certification, but no corresponding user account in WP yet
            wp_die("Your Google account ({$email}) verified successfully, but there is no corresponding user in the system. Please contact the administrator.", 'Unauthorized', ['response' => 403]);
        }
    }

/**
     * Core Logic 2: REST API Firewall (Supports external comments)
     */
    public function api_firewall($result, $server, $request) {
        $route = $request->get_route();
        $method = $request->get_method();

        // 1. If it's an employee (already logged in via handle_iap_authentication above), allow all API operations
        if (is_user_logged_in()) {
            return $result;
        }

        // --------------------------------------------------------
        // [Add] 2. Dedicated channel for comment functionality (Allow external visitors to POST)
        // --------------------------------------------------------
        // If the request is for the comments endpoint and the method is POST (submit comment) or GET (read comments)
        if (strpos($route, '/wp/v2/comments') === 0) {
            // Allow WordPress native comment mechanism to take over (including subsequent spam comment blocking verification)
            if ($method === 'POST' || $method === 'GET') {
                return $result;
            }
        }

        // 3. For external non-logged-in visitors, check the regular whitelist
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

        // 5. Block all others (e.g., trying to read /wp/v2/users or sending POST to other endpoints)
        return new WP_Error(
            'rest_forbidden',
            'No permission to access this API endpoint.',
            ['status' => 401]
        );
    }
    // --- Below is the original verification logic ---

    private function get_google_iap_keys() {
        $transient_key = 'gcp_iap_public_keys';
        $keys = get_transient($transient_key);

        if (false === $keys) {
            $response = wp_remote_get('https://www.gstatic.com/iap/verify/public_key');
            if (is_wp_error($response)) {
                error_log('IAP Auth Error: Unable to fetch Google public keys - ' . $response->get_error_message());
                return false;
            }
            $body = wp_remote_retrieve_body($response);
            $keys = json_decode($body, true);
            if ($keys) {
                set_transient($transient_key, $keys, HOUR_IN_SECONDS);
            }
        }
        return $keys;
    }

    private function verify_jwt($jwt) {
        if (!class_exists('Firebase\JWT\JWT')) {
            error_log('IAP Auth Error: Firebase\JWT\JWT class not found.');
            return false;
        }

        $public_keys = $this->get_google_iap_keys();
        if (!$public_keys) return false;

        $key_objects = [];
        foreach ($public_keys as $kid => $pem) {
            $key_objects[$kid] = new Key($pem, 'ES256');
        }

        try {
            $decoded = JWT::decode($jwt, $key_objects);

            if ($decoded->iss !== 'https://cloud.google.com/iap') {
                error_log('IAP Auth Error: Invalid issuer -> ' . $decoded->iss);
                return false;
            }

            if ($decoded->aud !== $this->audience) {
                error_log('IAP Auth Error: Invalid audience. Expected: ' . $this->audience);
                return false;
            }

            return (array) $decoded;

        } catch (\Exception $e) {
            error_log('IAP Auth Error: JWT verification failed -> ' . $e->getMessage());
            return false;
        }
    }
}

new IAP_Auth_Shield();