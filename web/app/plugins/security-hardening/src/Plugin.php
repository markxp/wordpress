<?php

/**
 * Main Plugin Class - Handles REST API hardening, feed suppression, author protection, version hiding, and Instance Status reporting.
 *
 * @package Legispect_Security
 */

namespace Legispect\Security;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class Plugin
{
    /**
     * Cached WordPress core version string for the current request.
     */
    private ?string $cached_wp_ver = null;

    /**
     * Cached regex matching exact WordPress core version query parameter.
     */
    private ?string $cached_ver_regex = null;

    /**
     * Cached 8-char deterministic HMAC version hash for the current request.
     */
    private ?string $cached_ver_hash = null;

    /**
     * Set up all WordPress filters and actions.
     */
    public function __construct()
    {
        // Register admin hooks so admins can see status page and row status badges
        if (is_admin()) {
            add_action('admin_menu', [$this, 'register_admin_menu']);
            add_filter('plugin_action_links_' . plugin_basename(__DIR__ . '/../security-hardening.php'), [$this, 'add_action_links']);
            add_action('after_plugin_row_' . plugin_basename(__DIR__ . '/../security-hardening.php'), [$this, 'render_plugin_row_status'], 10, 2);
        }

        // 1. Intercept query parameters early in the init phase
        add_action('init', [$this, 'block_query_parameter_bypasses'], 1);

        // 2. REST API Gatekeeper
        add_filter('rest_authentication_errors', [$this, 'restrict_rest_api'], 50);

        // 3. Disable RSS/Atom/RDF Feeds
        add_action('do_feed', [$this, 'disable_feed_handler'], 1);
        add_action('do_feed_rdf', [$this, 'disable_feed_handler'], 1);
        add_action('do_feed_rss', [$this, 'disable_feed_handler'], 1);
        add_action('do_feed_rss2', [$this, 'disable_feed_handler'], 1);
        add_action('do_feed_atom', [$this, 'disable_feed_handler'], 1);
        add_action('do_feed_rss2_comments', [$this, 'disable_feed_handler'], 1);
        add_action('do_feed_atom_comments', [$this, 'disable_feed_handler'], 1);

        remove_action('wp_head', 'feed_links', 2);
        remove_action('wp_head', 'feed_links_extra', 3);

        // 4. Prevent User Enumeration (Author Queries) & Pretty-Permalink Trackback Bypasses
        add_filter('request', [$this, 'block_author_query_vars']);
        add_action('template_redirect', [$this, 'block_author_archive_pages']);
        add_filter('pings_open', '__return_false', 9999);
        add_action('pre_trackback_post', [$this, 'block_trackback_execution'], 1);

        // 5. WordPress Version Hiding
        remove_action('wp_head', 'wp_generator');
        add_filter('the_generator', '__return_empty_string');
        add_filter('style_loader_src', [$this, 'remove_wp_ver_from_assets'], 9999);
        add_filter('script_loader_src', [$this, 'remove_wp_ver_from_assets'], 9999);
    }

    /**
     * Register admin page without displaying it in the sidebar menu.
     */
    public function register_admin_menu()
    {
        add_submenu_page(
            'options.php',
            'Security Hardening Status',
            'Security Hardening',
            'manage_options',
            'security-hardening',
            [$this, 'render_admin_status_page'],
        );
    }

    /**
     * Add Settings link on Plugins list page.
     */
    public function add_action_links($links)
    {
        $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=security-hardening')) . '">' . __('Settings') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Display status badge on wp-admin/plugins.php table row.
     */
    public function render_plugin_row_status($plugin_file, $plugin_data)
    {
        $badge_style = 'background:#10b981; color:#fff;';
        $status_text = '● Instance: ON';

        echo '<tr class="plugin-update-tr"><td colspan="3" class="plugin-update colspanchange"><div class="update-message notice inline notice-alt" style="margin:5px 0 5px 0; border-left-color: #10b981;">';
        echo '<p><span style="' . esc_attr($badge_style) . ' padding:3px 8px; border-radius:12px; font-weight:600; font-size:11px; margin-right:8px;">' . esc_html($status_text) . '</span>';
        echo '<strong>Security Hardening Status:</strong> System security hardening &amp; gatekeeper protections are active.';
        echo '</p></div></td></tr>';
    }

    /**
     * Render the admin status page.
     */
    public function render_admin_status_page()
    {
        ?>
        <div class="wrap">
            <h1>WordPress Security Hardening Status</h1>

            <div style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:8px; margin-top:20px; max-width:800px;">
                <h2 style="margin-top:0;">Instance Operational Indicator</h2>
                <div style="display:flex; align-items:center; gap:15px; margin-bottom:20px;">
                    <span style="background:#10b981; color:#fff; padding:8px 16px; border-radius:20px; font-size:18px; font-weight:bold;">
                        ● Instance Status: ON
                    </span>
                    <span style="color:#10b981; font-weight:600;">System Security Hardening &amp; Gatekeeper Protections are Active.</span>
                </div>

                <h3 style="margin-top:25px;">Active Protection Modules</h3>
                <ul style="list-style-type:disc; padding-left:20px; line-height:1.6;">
                    <li><strong>REST API Gatekeeper:</strong> Enforces authentication for sensitive REST routes (including <code>/wp/v2/comments</code> and <code>/wp/v2/users</code>) and blocks anonymous POST/PUT/DELETE operations.</li>
                    <li><strong>Query Parameter Bypass Blocker:</strong> Blocks direct <code>rest_route</code>, <code>feed</code>, <code>author</code>, and <code>tb</code> communication parameter attacks.</li>
                    <li><strong>Trackback &amp; Pingback Defense:</strong> Disables trackbacks/pingbacks globally (<code>pings_open</code>, <code>pre_trackback_post</code>) and blocks both <code>?tb=</code> and pretty-permalink <code>/trackback</code> routes.</li>
                    <li><strong>Feed Suppression:</strong> Disables RSS/Atom/RDF feeds for anonymous visitors to prevent automated content scrapers.</li>
                    <li><strong>User Enumeration Defense:</strong> Suppresses author archive pages and strips <code>author</code> and <code>author_name</code> query variables for non-logged-in users.</li>
                    <li><strong>Version Hiding:</strong> Replaces generator meta tags and hashes WordPress core <code>ver=</code> query strings on static script/style URLs.</li>
                </ul>
            </div>
        </div>
        <?php
    }

    /**
     * Intercept and block direct query parameter bypasses (rest_route, feed, author, tb) for anonymous users.
     */
    public function block_query_parameter_bypasses()
    {
        if (is_user_logged_in()) {
            return; // Allow logged-in users full access.
        }

        // 1. Block direct rest_route parameter (Bypasses Nginx path filters)
        if (isset($_GET['rest_route']) || isset($_POST['rest_route'])) {
            wp_die(
                __('Direct rest_route query parameter access is disabled for security.'),
                __('Bad Request'),
                ['response' => 400],
            );
        }

        // 2. Block direct feed parameter (Bypasses Nginx feed path filters)
        if (isset($_GET['feed']) || isset($_POST['feed'])) {
            $this->trigger_404_error();
        }

        // 3. Block direct author parameter (Discloses usernames)
        if (isset($_GET['author']) || isset($_POST['author'])) {
            $this->trigger_404_error();
        }

        // 4. Block direct tb parameter (Trackbacks)
        if (isset($_GET['tb']) || isset($_POST['tb'])) {
            wp_die(
                __('Trackbacks are disabled on this site.'),
                __('Forbidden'),
                ['response' => 403],
            );
        }
    }

    /**
     * REST API Gatekeeper. Require authentication for sensitive routes,
     * and block all anonymous resource creation/modification requests (POST/PUT/PATCH/DELETE).
     */
    public function restrict_rest_api($result)
    {
        if (true === $result || is_wp_error($result)) {
            return $result;
        }

        if (is_user_logged_in()) {
            return $result;
        }

        // Allow CORS OPTIONS requests
        if (isset($_SERVER['REQUEST_METHOD']) && 'OPTIONS' === $_SERVER['REQUEST_METHOD']) {
            return $result;
        }

        // Only allow GET and HEAD requests for anonymous users to whitelisted endpoints.
        // This blocks anonymous POST a comment, POST a media, or any database write requests.
        $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return new \WP_Error(
                'rest_cannot_access',
                __('Only authenticated users can create or modify resources.'),
                ['status' => 401],
            );
        }

        // List of whitelisted REST route prefixes allowed for anonymous visitors.
        $allowed_prefixes = apply_filters('security_hardening_allowed_rest_prefixes', [
            '/wp/v2/posts',
            '/wp/v2/pages',
            '/wp/v2/categories',
            '/wp/v2/tags',
            '/wp/v2/types',
            '/wp/v2/taxonomies',
            '/wp/v2/media',
        ]);

        // Get the requested REST route (cater to pretty URIs)
        $current_route = '';
        if (isset($GLOBALS['wp']->query_vars['rest_route'])) {
            $current_route = $GLOBALS['wp']->query_vars['rest_route'];
        } elseif (isset($_SERVER['REQUEST_URI'])) {
            $path = wp_parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            $prefix = '/' . rest_get_url_prefix();
            if (str_starts_with($path, $prefix)) {
                $current_route = substr($path, strlen($prefix));
            }
        }

        $current_route = '/' . ltrim(urldecode($current_route), '/');

        // Check whitelist
        foreach ($allowed_prefixes as $allowed_prefix) {
            if (str_starts_with($current_route, $allowed_prefix)) {
                return $result; // Allow public GET access.
            }
        }

        // Block everything else (like /wp/v2/users or /wp/v2/users/1)
        return new \WP_Error(
            'rest_cannot_access',
            __('Only authenticated users can access this resource.'),
            ['status' => 401],
        );
    }

    /**
     * Custom function to return a 404 response for RSS/Atom feeds.
     */
    public function disable_feed_handler()
    {
        $this->trigger_404_error();
    }

    /**
     * Strip "author", "author_name", and "tb" (trackback) query variables parsed by WP_Rewrite for anonymous users.
     */
    public function block_author_query_vars($query_vars)
    {
        if (!is_user_logged_in()) {
            if (isset($query_vars['tb'])) {
                wp_die(
                    __('Trackbacks are disabled on this site.'),
                    __('Forbidden'),
                    ['response' => 403],
                );
            }
            unset($query_vars['author'], $query_vars['author_name']);
        }
        return $query_vars;
    }

    /**
     * Hard-stop any direct wp-trackback.php execution.
     */
    public function block_trackback_execution(): void
    {
        wp_die(
            __('Trackbacks are disabled on this site.'),
            __('Forbidden'),
            ['response' => 403],
        );
    }

    /**
     * Disable access to author archive pages for anonymous users.
     */
    public function block_author_archive_pages()
    {
        if (is_author() && !is_user_logged_in()) {
            $this->trigger_404_error();
        }
    }

    /**
     * Replace '?ver=X.Y.Z' matching the WordPress core version with a deterministic 8-char HMAC hash
     * so the core version is hidden while preserving immutable browser cache-busting across upgrades.
     */
    public function remove_wp_ver_from_assets($src)
    {
        if (!is_string($src) || $src === '') {
            return $src;
        }

        if ($this->cached_wp_ver === null) {
            $this->cached_wp_ver = (string) get_bloginfo('version');
            if ($this->cached_wp_ver !== '') {
                $this->cached_ver_regex = '/[?&]ver=' . preg_quote($this->cached_wp_ver, '/') . '(?:$|&|#)/';
            }
        }

        if ($this->cached_ver_regex !== null && preg_match($this->cached_ver_regex, $src) === 1) {
            if ($this->cached_ver_hash === null) {
                $salt = function_exists('wp_salt') ? (string) wp_salt('nonce') : (defined('NONCE_SALT') ? (string) NONCE_SALT : '');
                $this->cached_ver_hash = substr(hash_hmac('sha256', $this->cached_wp_ver, $salt), 0, 8);
            }
            $src = remove_query_arg('ver', $src);
            $src = add_query_arg('ver', $this->cached_ver_hash, $src);
        }

        return $src;
    }

    /**
     * Utility method to output a clean 404 response.
     */
    private function trigger_404_error()
    {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();

        if (file_exists(get_query_template('404'))) {
            include(get_query_template('404'));
        } else {
            wp_die(
                __('Resource not found.'),
                __('Not Found'),
                ['response' => 404],
            );
        }
        exit;
    }
}
