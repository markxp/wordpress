<?php
/**
 * Main Plugin Class - Handles REST API hardening, feed suppression, author protection, and version hiding.
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
     * Set up all WordPress filters and actions.
     */
    public function __construct()
    {
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

        // 4. Prevent User Enumeration (Author Queries)
        add_filter('request', [$this, 'block_author_query_vars']);
        add_action('template_redirect', [$this, 'block_author_archive_pages']);

        // 5. WordPress Version Hiding
        remove_action('wp_head', 'wp_generator');
        add_filter('the_generator', '__return_empty_string');
        add_filter('style_loader_src', [$this, 'remove_wp_ver_from_assets'], 9999);
        add_filter('script_loader_src', [$this, 'remove_wp_ver_from_assets'], 9999);
    }

    /**
     * Intercept and block direct query parameter bypasses (rest_route, feed, author, tb, embed) for anonymous users.
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
                array('response' => 400)
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
                array('response' => 403)
            );
        }

        // 5. Block direct embed parameter (Bypasses embed filters)
        if (isset($_GET['embed']) || isset($_POST['embed'])) {
            $this->trigger_404_error();
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
                array('status' => 401)
            );
        }

        // List of whitelisted REST route prefixes allowed for anonymous visitors.
        $allowed_prefixes = [
            '/wp/v2/posts',
            '/wp/v2/pages',
            '/wp/v2/categories',
            '/wp/v2/tags',
            '/wp/v2/types',
            '/wp/v2/taxonomies',
            '/wp/v2/media',
            '/wp/v2/comments',
        ];

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
            array('status' => 401)
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
     * Strip the "author" query parameter from request variables.
     */
    public function block_author_query_vars($query_vars)
    {
        if (isset($query_vars['author']) && !is_user_logged_in()) {
            unset($query_vars['author']);
        }
        return $query_vars;
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
     * Strip '?ver=X.Y.Z' query parameter from script and style asset links.
     */
    public function remove_wp_ver_from_assets($src)
    {
        if (is_string($src) && str_contains($src, 'ver=' . get_bloginfo('version'))) {
            $src = remove_query_arg('ver', $src);
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
                array('response' => 404)
            );
        }
        exit;
    }
}
