<?php

/**
 * Main Plugin Class - Resolves and prints <meta name="description"> in wp_head.
 *
 * @package Legispect_SEO_Meta_Description
 */

declare(strict_types=1);

namespace Legispect_SEO_Meta_Description;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class Plugin
{
    public const MAX_LENGTH = 160;
    public const CONTENT_SLICE_BYTES = 4096;
    public const CACHE_GROUP = 'legispect_seo_meta_description';
    public const OPTION_USE_OBJECT_CACHE = 'legispect_seo_meta_description_use_object_cache';

    public function __construct()
    {
        add_action('wp_head', [$this, 'render_meta_description'], 1);

        if (function_exists('is_admin') && is_admin()) {
            add_action('admin_menu', [$this, 'register_admin_settings_page']);
            add_action('admin_init', [$this, 'register_plugin_settings']);
            add_filter('plugin_action_links_' . plugin_basename(__DIR__ . '/../legispect-seo-meta-description.php'), [$this, 'add_action_links']);
            add_action('after_plugin_row_' . plugin_basename(__DIR__ . '/../legispect-seo-meta-description.php'), [$this, 'render_plugin_row_status'], 10, 2);
        }
    }

    /**
     * Register the admin settings page without displaying it in the sidebar menu.
     */
    public function register_admin_settings_page(): void
    {
        add_submenu_page(
            'options.php',
            'Legispect SEO Meta Description Configuration & Status',
            'Legispect SEO Meta Description',
            'manage_options',
            'legispect-seo-meta-description',
            [$this, 'render_admin_settings_page'],
        );
    }

    /**
     * Add Settings link on Plugins list page.
     */
    public function add_action_links(array $links): array
    {
        $settings_link = '<a href="' . esc_url(admin_url('admin.php?page=legispect-seo-meta-description')) . '">' . __('Settings') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Display status badge on wp-admin/plugins.php table row.
     */
    public function render_plugin_row_status($plugin_file, $plugin_data): void
    {
        $badge_style = 'background:#10b981; color:#fff;';
        $status_text = '● Instance: ON';
        $cache_enabled = $this->should_use_object_cache();

        echo '<tr class="plugin-update-tr"><td colspan="3" class="plugin-update colspanchange"><div class="update-message notice inline notice-alt" style="margin:5px 0 5px 0; border-left-color: #10b981;">';
        echo '<p><span style="' . esc_attr($badge_style) . ' padding:3px 8px; border-radius:12px; font-weight:600; font-size:11px; margin-right:8px;">' . esc_html($status_text) . '</span>';
        echo '<strong>Legispect SEO Meta Description Status:</strong> Active and emitting <code>&lt;meta name="description"&gt;</code> tags (Object Cache Toggle: <code>' . ($cache_enabled ? 'ON' : 'OFF') . '</code>).';
        echo '</p></div></td></tr>';
    }

    /**
     * Register plugin settings for Object Cache toggle.
     */
    public function register_plugin_settings(): void
    {
        register_setting('legispect_seo_meta_description_settings_group', self::OPTION_USE_OBJECT_CACHE, [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitize_checkbox_setting'],
            'default'           => '0',
        ]);

        add_settings_section(
            'legispect_seo_meta_description_cache_section',
            'Performance & Caching Settings',
            [$this, 'render_cache_section_info'],
            'legispect-seo-meta-description',
        );

        add_settings_field(
            self::OPTION_USE_OBJECT_CACHE,
            'Enable Object Cache',
            [$this, 'render_use_object_cache_field'],
            'legispect-seo-meta-description',
            'legispect_seo_meta_description_cache_section',
        );
    }

    /**
     * Sanitize checkbox option to explicit '1' or '0' string.
     */
    public function sanitize_checkbox_setting($value): string
    {
        return (!empty($value) && $value !== '0' && $value !== 'false') ? '1' : '0';
    }

    /**
     * Render section info.
     */
    public function render_cache_section_info(): void
    {
        echo '<p>Configure optional object caching for resolved meta descriptions when the server provides a persistent WordPress Object Cache (e.g. APCu, Redis, or Memcached).</p>';
    }

    /**
     * Render checkbox field for enabling WordPress object cache storage.
     */
    public function render_use_object_cache_field(): void
    {
        $value = function_exists('get_option') ? (string) get_option(self::OPTION_USE_OBJECT_CACHE, '0') : '0';
        $ext_cache = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
        echo '<input type="checkbox" name="' . esc_attr(self::OPTION_USE_OBJECT_CACHE) . '" value="1" ' . checked('1', $value, false) . ' />';
        echo '<p class="description"><strong>Use WordPress Object Cache (<code>wp_cache_get</code> / <code>wp_cache_set</code>):</strong> When enabled, resolved singular post descriptions are cached in the WordPress object cache keyed by post ID and modification timestamp. ';
        echo 'External Object Cache Drop-in Detected: <code>' . ($ext_cache ? 'Yes' : 'No (Standard In-Memory)') . '</code>.</p>';
    }

    /**
     * Render the settings page HTML.
     */
    public function render_admin_settings_page(): void
    {
        ?>
        <div class="wrap">
            <h1>Legispect SEO Meta Description Configuration &amp; Status</h1>

            <div style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:8px; margin-top:20px; margin-bottom:25px; max-width:800px;">
                <h2 style="margin-top:0;">Instance Operational Indicator</h2>
                <div style="display:flex; align-items:center; gap:15px;">
                    <span style="background:#10b981; color:#fff; padding:8px 16px; border-radius:20px; font-size:18px; font-weight:bold;">
                        ● Instance Status: ON
                    </span>
                    <span style="color:#10b981; font-weight:600;">Emitting clean <code>&lt;meta name="description"&gt;</code> tags in <code>wp_head</code>.</span>
                </div>
            </div>

            <form method="post" action="options.php">
                <?php
                    settings_fields('legispect_seo_meta_description_settings_group');
        do_settings_sections('legispect-seo-meta-description');
        submit_button();
        ?>
            </form>
        </div>
        <?php
    }

    /**
     * Determine whether object caching is enabled via setting or filter.
     */
    public function should_use_object_cache(): bool
    {
        $enabled = false;
        if (function_exists('get_option')) {
            $raw = get_option(self::OPTION_USE_OBJECT_CACHE, '0');
            $enabled = filter_var($raw, FILTER_VALIDATE_BOOLEAN);
        }

        return (bool) apply_filters('legispect_seo_meta_description_use_object_cache', $enabled);
    }

    /**
     * Resolve and print <meta name="description" content="..."> in wp_head.
     */
    public function render_meta_description(): void
    {
        $description = $this->resolve_description();
        $description = (string) apply_filters('legispect_seo_meta_description_content', $description);

        if ($description === '') {
            return;
        }

        echo '<meta name="description" content="' . esc_attr($description) . '" />' . "\n";
    }

    /**
     * Determine description from singular post excerpt/content, taxonomy description, or site tagline.
     */
    public function resolve_description(): string
    {
        if (function_exists('is_404') && is_404()) {
            return '';
        }

        $text = '';
        $cache_key = '';
        $use_cache = false;

        if ((function_exists('is_front_page') && is_front_page()) || (function_exists('is_home') && is_home())) {
            $text = (string) get_bloginfo('description');
        }

        if ($text === '' && function_exists('is_singular') && is_singular()) {
            $post = get_queried_object();
            if (is_object($post)) {
                $use_cache = $this->should_use_object_cache()
                    && function_exists('wp_cache_get')
                    && function_exists('wp_cache_set')
                    && isset($post->ID);

                if ($use_cache) {
                    $modified = isset($post->post_modified_gmt) && is_string($post->post_modified_gmt) ? $post->post_modified_gmt : '';
                    $cache_key = 'post_desc_' . (string) $post->ID . '_' . md5($modified);
                    $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
                    if (is_string($cached)) {
                        return $cached;
                    }
                }

                if (!empty($post->post_excerpt) && is_string($post->post_excerpt)) {
                    $text = $post->post_excerpt;
                } elseif (!empty($post->post_content) && is_string($post->post_content)) {
                    $text = $this->slice_leading_content($post->post_content);
                    if (function_exists('excerpt_remove_blocks')) {
                        $text = excerpt_remove_blocks($text);
                    }
                    if (function_exists('strip_shortcodes')) {
                        $text = strip_shortcodes($text);
                    }
                }
            }
        }

        if (
            $text === ''
            && (
                (function_exists('is_category') && is_category())
                || (function_exists('is_tag') && is_tag())
                || (function_exists('is_tax') && is_tax())
            )
            && function_exists('term_description')
        ) {
            $text = (string) term_description();
        }

        if ($text === '') {
            $text = (string) get_bloginfo('description');
        }

        $text = wp_strip_all_tags($text, true);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if (mb_strlen($text, 'UTF-8') > self::MAX_LENGTH) {
            $text = rtrim(mb_substr($text, 0, self::MAX_LENGTH - 1, 'UTF-8')) . '…';
        }

        if ($use_cache && $cache_key !== '') {
            wp_cache_set($cache_key, $text, self::CACHE_GROUP, HOUR_IN_SECONDS);
        }

        return $text;
    }

    /**
     * Bound long post_content to the leading ~4 KB aligned to a closing block or paragraph boundary
     * so excerpt_remove_blocks() (parse_blocks) does not parse an entire long-form article.
     */
    protected function slice_leading_content(string $content): string
    {
        if (strlen($content) <= self::CONTENT_SLICE_BYTES) {
            return $content;
        }

        // Find the next closing block comment or closing paragraph after CONTENT_SLICE_BYTES
        if (preg_match('/(?:<!--\s*\/wp:[a-z0-9_-]+\s*-->|<\/p>|\n\n)/i', $content, $matches, PREG_OFFSET_CAPTURE, self::CONTENT_SLICE_BYTES) === 1) {
            $end_pos = $matches[0][1] + strlen($matches[0][0]);
            return substr($content, 0, $end_pos);
        }

        return mb_substr($content, 0, self::CONTENT_SLICE_BYTES, 'UTF-8');
    }
}
