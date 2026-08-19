<?php
/**
 * Main Plugin Class - Extends base class to add HTTP 103 Early Hints admin configuration UI and status reporting.
 *
 * @package HTTP_103_Early_Hints
 */

namespace HTTP_103_Early_Hints;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class Plugin extends Base
{
    public function __construct()
    {
        // Run core base constructor.
        parent::__construct();

        // Register Admin Settings Hooks
        if (is_admin()) {
            add_action('admin_menu', [$this, 'register_admin_settings_page']);
            add_action('admin_init', [$this, 'register_plugin_settings']);
            add_filter('plugin_action_links_' . plugin_basename(__DIR__ . '/../http-103-early-hints.php'), [$this, 'add_action_links']);
            add_action('after_plugin_row_' . plugin_basename(__DIR__ . '/../http-103-early-hints.php'), [$this, 'render_plugin_row_status'], 10, 2);
        }
    }

    /**
     * Register the admin settings page under Settings.
     */
    public function register_admin_settings_page()
    {
        add_options_page(
            'HTTP 103 Early Hints Configuration & Status',
            'HTTP 103 Early Hints',
            'manage_options',
            'hlhg-settings',
            [$this, 'render_admin_settings_page'],
        );
    }

    /**
     * Add Settings link on Plugins list page.
     */
    public function add_action_links($links)
    {
        $status_link = '<a href="' . esc_url(admin_url('options-general.php?page=hlhg-settings')) . '">Settings & Status</a>';
        array_unshift($links, $status_link);
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
        echo '<strong>HTTP 103 Early Hints Status:</strong> Link header generator is active and emitting early hints preload headers for CDN performance.';
        echo '</p></div></td></tr>';
    }

    /**
     * Register settings, sections, and fields.
     */
    public function register_plugin_settings()
    {
        register_setting('hlhg_settings_group', 'hlhg_load_separate_assets', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '1',
        ]);

        register_setting('hlhg_settings_group', 'hlhg_inline_size_limit', [
            'type'              => 'integer',
            'sanitize_callback' => 'intval',
            'default'           => 0,
        ]);

        // Section 1: General Performance Settings
        add_settings_section(
            'hlhg_general_section',
            'General Asset & Preload Settings',
            [$this, 'render_general_section_info'],
            'hlhg-settings',
        );

        add_settings_field(
            'hlhg_inline_size_limit',
            'Inline CSS Size Limit (Bytes)',
            [$this, 'render_inline_size_limit_field'],
            'hlhg-settings',
            'hlhg_general_section',
        );

        // Section 2: Block Theme Specific Settings
        add_settings_section(
            'hlhg_block_theme_section',
            'Block Theme Optimizations',
            [$this, 'render_block_theme_section_info'],
            'hlhg-settings',
        );

        add_settings_field(
            'hlhg_load_separate_assets',
            'On-Demand Block CSS',
            [$this, 'render_load_separate_assets_field'],
            'hlhg-settings',
            'hlhg_block_theme_section',
        );
    }

    /**
     * Render the general section info.
     */
    public function render_general_section_info()
    {
        echo '<p>Configure settings that optimize general stylesheet delivery, browser caching, and preloads for all themes and plugins supporting CDN HTTP 103 Early Hints.</p>';
    }

    /**
     * Render the block theme section info.
     */
    public function render_block_theme_section_info()
    {
        echo '<p>Configure performance optimizations specific to modern block-based themes (Full Site Editing).</p>';
    }

    /**
     * Render the check field for loading separate block assets.
     */
    public function render_load_separate_assets_field()
    {
        $value = get_option('hlhg_load_separate_assets', '1');
        echo '<input type="checkbox" name="hlhg_load_separate_assets" value="1" ' . checked('1', $value, false) . ' />';
        echo '<p class="description"><strong>On-Demand Style Loading:</strong> When enabled, WordPress splits core block styles and only loads CSS files for blocks actually present on the current page. This prevents loading unused block CSS globally (Highly Recommended for Block Themes).</p>';
    }

    /**
     * Render the size limit input field.
     */
    public function render_inline_size_limit_field()
    {
        $value = get_option('hlhg_inline_size_limit', '0');
        echo '<input type="number" min="0" name="hlhg_inline_size_limit" value="' . esc_attr($value) . '" class="small-text" />';
        echo ' Bytes';
        echo '<p class="description"><strong>CSS Inlining Threshold:</strong> The maximum size of any enqueued stylesheet allowed to be injected directly inside the HTML head. Setting this to <code>0</code> disables inlining entirely, forcing all stylesheets to load as external files. This allows CDNs and browsers to cache the stylesheets and preload them early using HTTP 103 Early Hints.</p>';
    }

    /**
     * Render the settings page HTML.
     */
    public function render_admin_settings_page()
    {
        ?>
        <div class="wrap">
            <h1>HTTP 103 Early Hints & Link Header Generator</h1>

            <div style="background:#fff; border:1px solid #ccd0d4; padding:20px; border-radius:8px; margin-top:20px; margin-bottom:25px; max-width:800px;">
                <h2 style="margin-top:0;">Instance Operational Indicator</h2>
                <div style="display:flex; align-items:center; gap:15px;">
                    <span style="background:#10b981; color:#fff; padding:8px 16px; border-radius:20px; font-size:18px; font-weight:bold;">
                        ● Instance Status: ON
                    </span>
                    <span style="color:#10b981; font-weight:600;">Generating HTTP Link headers for CDN HTTP 103 Early Hints.</span>
                </div>
            </div>

            <form method="post" action="options.php">
                <?php
                    settings_fields('hlhg_settings_group');
        do_settings_sections('hlhg-settings');
        submit_button();
        ?>
            </form>
        </div>
        <?php
    }
}
