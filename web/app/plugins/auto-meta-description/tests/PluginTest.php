<?php

declare(strict_types=1);

namespace AutoMetaDescription\Tests;

use AutoMetaDescription\Plugin;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Tests\TestCase;

class PluginTest extends TestCase
{
    public function test_constructor_hooks_wp_head(): void
    {
        Actions\expectAdded('wp_head')->once();

        $plugin = new Plugin();
        $this->assertInstanceOf(Plugin::class, $plugin);
    }

    public function test_render_meta_description_uses_site_tagline_on_front_page(): void
    {
        Functions\when('is_404')->justReturn(false);
        Functions\when('is_front_page')->justReturn(true);
        Functions\when('get_bloginfo')->justReturn('Modern cloud-native WordPress platform.');
        Functions\when('wp_strip_all_tags')->returnArg();

        $plugin = new Plugin();
        ob_start();
        $plugin->render_meta_description();
        $output = (string) ob_get_clean();

        $this->assertSame('<meta name="description" content="Modern cloud-native WordPress platform." />' . "\n", $output);
    }

    public function test_render_meta_description_uses_post_content_on_singular_page(): void
    {
        Functions\when('is_404')->justReturn(false);
        Functions\when('is_front_page')->justReturn(false);
        Functions\when('is_home')->justReturn(false);
        Functions\when('is_singular')->justReturn(true);
        Functions\when('get_option')->justReturn('0');
        Functions\when('get_queried_object')->justReturn((object) [
            'post_excerpt' => '',
            'post_content' => '<p>Welcome to our documentation portal. Explore architecture guides and tutorials.</p>',
        ]);
        Functions\when('excerpt_remove_blocks')->returnArg();
        Functions\when('strip_shortcodes')->returnArg();
        Functions\when('wp_strip_all_tags')->alias(static fn (string $str): string => strip_tags($str));

        $plugin = new Plugin();
        ob_start();
        $plugin->render_meta_description();
        $output = (string) ob_get_clean();

        $this->assertSame('<meta name="description" content="Welcome to our documentation portal. Explore architecture guides and tutorials." />' . "\n", $output);
    }

    public function test_render_meta_description_skips_on_404(): void
    {
        Functions\when('is_404')->justReturn(true);

        $plugin = new Plugin();
        ob_start();
        $plugin->render_meta_description();
        $output = (string) ob_get_clean();

        $this->assertSame('', $output);
    }

    public function test_render_meta_description_prioritizes_post_excerpt_over_content(): void
    {
        Functions\when('is_404')->justReturn(false);
        Functions\when('is_front_page')->justReturn(false);
        Functions\when('is_home')->justReturn(false);
        Functions\when('is_singular')->justReturn(true);
        Functions\when('get_option')->justReturn('0');
        Functions\when('get_queried_object')->justReturn((object) [
            'post_excerpt' => 'Explicit manual summary for this post.',
            'post_content' => '<p>Full content body that should not be used when excerpt exists.</p>',
        ]);
        Functions\when('wp_strip_all_tags')->returnArg();

        $plugin = new Plugin();
        ob_start();
        $plugin->render_meta_description();
        $output = (string) ob_get_clean();

        $this->assertSame('<meta name="description" content="Explicit manual summary for this post." />' . "\n", $output);
    }

    public function test_render_meta_description_uses_taxonomy_description_on_category_or_tag(): void
    {
        Functions\when('is_404')->justReturn(false);
        Functions\when('is_front_page')->justReturn(false);
        Functions\when('is_home')->justReturn(false);
        Functions\when('is_singular')->justReturn(false);
        Functions\when('is_category')->justReturn(true);
        Functions\when('term_description')->justReturn('Curated category covering cloud infrastructure best practices.');
        Functions\when('wp_strip_all_tags')->returnArg();

        $plugin = new Plugin();
        ob_start();
        $plugin->render_meta_description();
        $output = (string) ob_get_clean();

        $this->assertSame('<meta name="description" content="Curated category covering cloud infrastructure best practices." />' . "\n", $output);
    }

    public function test_render_meta_description_truncates_long_descriptions_with_multibyte_ellipsis(): void
    {
        Functions\when('is_404')->justReturn(false);
        Functions\when('is_front_page')->justReturn(true);
        // Create 170-character multibyte string
        $longText = str_repeat('專業雲端架構', 28) . '結尾測試字元長度超過限制';
        Functions\when('get_bloginfo')->justReturn($longText);
        Functions\when('wp_strip_all_tags')->returnArg();

        $plugin = new Plugin();
        ob_start();
        $plugin->render_meta_description();
        $output = (string) ob_get_clean();

        // Extract content attribute
        preg_match('/content="([^"]+)"/', $output, $matches);
        $this->assertNotEmpty($matches);
        $extracted = $matches[1];

        $this->assertLessThanOrEqual(160, mb_strlen($extracted, 'UTF-8'));
        $this->assertStringEndsWith('…', $extracted);
    }

    public function test_render_meta_description_respects_filter_override(): void
    {
        Functions\when('is_404')->justReturn(false);
        Functions\when('is_front_page')->justReturn(true);
        Functions\when('get_bloginfo')->justReturn('Original tagline');
        Functions\when('wp_strip_all_tags')->returnArg();

        // Brain Monkey filter expectation for auto_meta_description_content
        \Brain\Monkey\Filters\expectApplied('auto_meta_description_content')
            ->once()
            ->andReturn('Custom programmatic description injected via filter.');

        $plugin = new Plugin();
        ob_start();
        $plugin->render_meta_description();
        $output = (string) ob_get_clean();

        $this->assertSame('<meta name="description" content="Custom programmatic description injected via filter." />' . "\n", $output);
    }

    public function test_render_meta_description_normalizes_internal_whitespace_and_newlines(): void
    {
        Functions\when('is_404')->justReturn(false);
        Functions\when('is_front_page')->justReturn(true);
        Functions\when('get_bloginfo')->justReturn("Line 1\n\n\t  Line 2   with   spaces\r\nLine 3");
        Functions\when('wp_strip_all_tags')->alias(function ($text) {
            return strip_tags((string) $text);
        });

        $plugin = new Plugin();
        ob_start();
        $plugin->render_meta_description();
        $output = (string) ob_get_clean();

        $this->assertSame('<meta name="description" content="Line 1 Line 2 with spaces Line 3" />' . "\n", $output);
    }

    public function test_render_meta_description_does_not_append_ellipsis_when_exactly_160_characters(): void
    {
        Functions\when('is_404')->justReturn(false);
        Functions\when('is_front_page')->justReturn(true);
        $exact160 = str_repeat('a', 160);
        Functions\when('get_bloginfo')->justReturn($exact160);
        Functions\when('wp_strip_all_tags')->returnArg();

        $plugin = new Plugin();
        ob_start();
        $plugin->render_meta_description();
        $output = (string) ob_get_clean();

        $this->assertSame('<meta name="description" content="' . $exact160 . '" />' . "\n", $output);
    }

    public function test_render_meta_description_skips_rendering_when_filtered_to_empty_string(): void
    {
        Functions\when('is_404')->justReturn(false);
        Functions\when('is_front_page')->justReturn(true);
        Functions\when('get_bloginfo')->justReturn('Some tagline');
        Functions\when('wp_strip_all_tags')->returnArg();

        \Brain\Monkey\Filters\expectApplied('auto_meta_description_content')
            ->once()
            ->andReturn('');

        $plugin = new Plugin();
        ob_start();
        $plugin->render_meta_description();
        $output = (string) ob_get_clean();

        $this->assertSame('', $output);
    }

    public function test_resolve_description_slices_very_long_post_content_at_block_boundary_before_parsing(): void
    {
        Functions\when('is_404')->justReturn(false);
        Functions\when('is_front_page')->justReturn(false);
        Functions\when('is_home')->justReturn(false);
        Functions\when('is_singular')->justReturn(true);
        Functions\when('get_option')->justReturn('0');

        // Build content > 4096 bytes with Gutenberg blocks
        $firstBlock = '<!-- wp:paragraph --><p>Lead summary paragraph that should be extracted cleanly.</p><!-- /wp:paragraph -->';
        $paddingBlock = '<!-- wp:paragraph --><p>' . str_repeat('Padding text content. ', 200) . '</p><!-- /wp:paragraph -->';
        $tailBlock = '<!-- wp:paragraph --><p>' . str_repeat('Tail content that should be sliced off before block parsing. ', 200) . '</p><!-- /wp:paragraph -->';
        $longContent = $firstBlock . $paddingBlock . $tailBlock;

        Functions\when('get_queried_object')->justReturn((object) [
            'ID' => 101,
            'post_excerpt' => '',
            'post_content' => $longContent,
        ]);

        $capturedInputLength = 0;
        Functions\when('excerpt_remove_blocks')->alias(static function (string $text) use (&$capturedInputLength): string {
            $capturedInputLength = strlen($text);
            return $text;
        });
        Functions\when('strip_shortcodes')->returnArg();
        Functions\when('wp_strip_all_tags')->alias(static fn (string $str): string => strip_tags($str));

        $plugin = new Plugin();
        $resolved = $plugin->resolve_description();

        $this->assertGreaterThan(Plugin::CONTENT_SLICE_BYTES, strlen($longContent));
        $this->assertLessThan(strlen($longContent), $capturedInputLength);
        $this->assertStringStartsWith('Lead summary paragraph that should be extracted cleanly.', $resolved);
    }

    public function test_resolve_description_uses_object_cache_when_toggle_enabled(): void
    {
        Functions\when('is_404')->justReturn(false);
        Functions\when('is_front_page')->justReturn(false);
        Functions\when('is_home')->justReturn(false);
        Functions\when('is_singular')->justReturn(true);
        Functions\when('get_option')->justReturn('1'); // Object cache toggle ON
        Functions\when('get_queried_object')->justReturn((object) [
            'ID' => 55,
            'post_modified_gmt' => '2026-11-10 12:00:00',
            'post_excerpt' => 'Uncached excerpt fallback.',
            'post_content' => '',
        ]);

        // 1. Cache hit returns cached description directly without stripping/recomputing
        Functions\expect('wp_cache_get')
            ->once()
            ->with('post_desc_55_' . md5('2026-11-10 12:00:00'), Plugin::CACHE_GROUP)
            ->andReturn('Cached description from APCu or Redis.');
        Functions\expect('wp_cache_set')->never();

        $plugin = new Plugin();
        $this->assertSame('Cached description from APCu or Redis.', $plugin->resolve_description());
    }

    public function test_resolve_description_stores_in_object_cache_on_cache_miss_when_toggle_enabled(): void
    {
        Functions\when('is_404')->justReturn(false);
        Functions\when('is_front_page')->justReturn(false);
        Functions\when('is_home')->justReturn(false);
        Functions\when('is_singular')->justReturn(true);
        Functions\when('get_option')->justReturn('1'); // Object cache toggle ON
        Functions\when('get_queried_object')->justReturn((object) [
            'ID' => 77,
            'post_modified_gmt' => '2026-11-10 15:00:00',
            'post_excerpt' => 'Freshly computed post description.',
            'post_content' => '',
        ]);
        Functions\when('wp_strip_all_tags')->returnArg();

        $expectedKey = 'post_desc_77_' . md5('2026-11-10 15:00:00');
        Functions\expect('wp_cache_get')
            ->once()
            ->with($expectedKey, Plugin::CACHE_GROUP)
            ->andReturn(false);
        Functions\expect('wp_cache_set')
            ->once()
            ->with($expectedKey, 'Freshly computed post description.', Plugin::CACHE_GROUP, HOUR_IN_SECONDS)
            ->andReturn(true);

        $plugin = new Plugin();
        $this->assertSame('Freshly computed post description.', $plugin->resolve_description());
    }

    public function test_admin_settings_hooks_and_checkbox_sanitization(): void
    {
        Functions\when('is_admin')->justReturn(true);
        Functions\when('plugin_basename')->justReturn('auto-meta-description/auto-meta-description.php');

        Actions\expectAdded('wp_head')->once();
        Actions\expectAdded('admin_menu')->once();
        Actions\expectAdded('admin_init')->once();
        \Brain\Monkey\Filters\expectAdded('plugin_action_links_auto-meta-description/auto-meta-description.php')->once();
        Actions\expectAdded('after_plugin_row_auto-meta-description/auto-meta-description.php')->once();

        $plugin = new Plugin();
        $this->assertSame('1', $plugin->sanitize_checkbox_setting('1'));
        $this->assertSame('0', $plugin->sanitize_checkbox_setting(null));
        $this->assertSame('0', $plugin->sanitize_checkbox_setting('0'));
    }
}
