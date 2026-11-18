<?php

/**
 * Main Plugin Class - Filters site_icon_meta_tags and get_site_icon_url for static root icons.
 *
 * @package ModernSiteIcons
 */

namespace ModernSiteIcons;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class Plugin
{
    /**
     * Sentinel icon file checked at the web root to determine if static icon assets are staged.
     */
    private const SENTINEL_ICON = '/icon.svg';

    /**
     * Memoized result of static root icon detection for the current instance.
     */
    private ?bool $has_static_icons = null;

    public function __construct()
    {
        add_filter('site_icon_meta_tags', [$this, 'filter_site_icon_meta_tags'], 10, 1);
        add_filter('get_site_icon_url', [$this, 'filter_get_site_icon_url'], 10, 2);
    }

    /**
     * Replace core site_icon meta tags with modern multi-format favicon and Web App Manifest tags
     * when static icon assets are present at the web root.
     *
     * @param  mixed  $meta_tags
     * @return mixed
     */
    public function filter_site_icon_meta_tags($meta_tags)
    {
        if (!$this->has_static_icons()) {
            return $meta_tags;
        }

        $original_tags = is_array($meta_tags) ? $meta_tags : [];

        $tags = [
            '<link rel="icon" type="image/png" href="' . esc_url(home_url('/favicon-96x96.png')) . '" sizes="96x96" />',
            '<link rel="icon" type="image/svg+xml" href="' . esc_url(home_url('/icon.svg')) . '" />',
            '<link rel="shortcut icon" href="' . esc_url(home_url('/favicon.ico')) . '" />',
            '<link rel="apple-touch-icon" sizes="180x180" href="' . esc_url(home_url('/apple-touch-icon.png')) . '" />',
            '<link rel="manifest" href="' . esc_url(home_url('/site.webmanifest')) . '" />',
        ];

        /**
         * Filters the modern site icon meta tags before output.
         *
         * @param  string[]  $tags           Modern multi-format favicon and manifest tags.
         * @param  string[]  $original_tags  Original WordPress core site_icon meta tags.
         */
        $filtered = apply_filters('modern_site_icons_meta_tags', $tags, $original_tags);

        return is_array($filtered) ? $filtered : $tags;
    }

    /**
     * Provide a fallback site icon URL when no database site_icon attachment is configured
     * so has_site_icon() returns true and wp_site_icon() executes site_icon_meta_tags.
     *
     * @param  mixed  $url
     * @param  mixed  $size
     * @return mixed
     */
    public function filter_get_site_icon_url($url, $size = 512)
    {
        if ((is_string($url) && $url !== '') || !$this->has_static_icons()) {
            return $url;
        }

        $numeric_size = is_numeric($size) ? (int) $size : 512;

        if ($numeric_size === 180) {
            return home_url('/apple-touch-icon.png');
        }

        if ($numeric_size > 0 && $numeric_size <= 96) {
            return home_url('/favicon-96x96.png');
        }

        return home_url('/icon.svg');
    }

    /**
     * Check (and memoize) whether static icon assets exist at the web root.
     */
    protected function has_static_icons(): bool
    {
        if ($this->has_static_icons === null) {
            $web_root = $this->get_web_root();
            $this->has_static_icons = $web_root !== '' && $this->asset_exists($web_root . self::SENTINEL_ICON);
        }

        return $this->has_static_icons;
    }

    /**
     * Resolve the web root directory containing static root assets.
     */
    protected function get_web_root(): string
    {
        if (defined('WP_CONTENT_DIR')) {
            return dirname((string) WP_CONTENT_DIR);
        }

        if (defined('ABSPATH')) {
            return rtrim((string) ABSPATH, '/\\');
        }

        return '';
    }

    /**
     * Check whether a static asset exists on disk.
     */
    protected function asset_exists(string $path): bool
    {
        return file_exists($path);
    }
}
