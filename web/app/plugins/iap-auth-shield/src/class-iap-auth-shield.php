<?php

/**
 * Main Plugin Class - Handles Google Cloud IAP identity mapping and REST API firewall.
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
        $this->audience = getenv('IAP_AUDIENCE');

        if (!$this->audience) {
            error_log('IAP Auth Configuration Error: IAP_AUDIENCE environment variable not found.');
            // Intentionally not throwing an exception here so the frontend remains unaffected.
            // We register the hooks and fail-closed inside the authentication handler instead.
        }

        // Hook 1: Handle identity verification and auto-login (init stage)
        add_action('init', [$this, 'handle_iap_authentication']);

        // Hook 2: REST API Firewall (Intercept before API dispatch)
        add_filter('rest_pre_dispatch', [$this, 'api_firewall'], 10, 3);
    }

    /**
     * Core Logic 1: Handle backend access and identity mapping
     */
    public function handle_iap_authentication()
    {
        // Only enforce verification on "backend admin pages" or "login page". Frontend is open to visitors.
        if (!is_admin() && !in_array($GLOBALS['pagenow'], ['wp-login.php'])) {
            return;
        }

        if (!$this->audience) {
            wp_die('IAP Auth Configuration Error: IAP_AUDIENCE is missing. Backend access is disabled for security.', 'Configuration Error', ['response' => 500]);
        }

        $jwt = $_SERVER['HTTP_X_GOOG_IAP_JWT_ASSERTION'] ?? null;

        // If trying to access wp-admin but without IAP JWT assertion header, block access.
        if (!$jwt) {
            wp_die('Access Denied: Missing IAP credentials. Please access through the correct domain.', 'Unauthorized', ['response' => 401]);
        }

        try {
            $payload = $this->verify_jwt($jwt);
        } catch (\Exception $e) {
            wp_die('IAP Verification Failed: ' . $e->getMessage(), 'Forbidden', ['response' => 403]);
        }

        if (!$payload || empty($payload['email'])) {
            wp_die('IAP Verification Failed: Payload empty or missing email.', 'Forbidden', ['response' => 403]);
        }

        $email = $payload['email'];
        $user = get_user_by('email', $email);

        if ($user) {
            // Generate session cookie for auto-login
            if (!is_user_logged_in() || wp_get_current_user()->user_email !== $email) {
                wp_set_current_user($user->ID);
                wp_set_auth_cookie($user->ID);

                // Auto redirect to backend from wp-login.php
                if ($GLOBALS['pagenow'] === 'wp-login.php') {
                    wp_safe_redirect(admin_url());
                    exit;
                }
            }
        } else {
            // Google authenticated, but user account not found in WordPress database
            wp_die("Your Google account ({$email}) verified successfully, but there is no corresponding user in the system. Please contact the administrator.", 'Unauthorized', ['response' => 403]);
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

    private function get_google_iap_keys()
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

    private function verify_jwt($jwt)
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
