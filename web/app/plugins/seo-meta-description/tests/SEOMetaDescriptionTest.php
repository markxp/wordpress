<?php

declare(strict_types=1);

namespace SEO_Meta_Description\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use SEO_Meta_Description\Plugin;
use Tests\TestCase;

class SEOMetaDescriptionTest extends TestCase
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

        // Brain Monkey filter expectation for seo_meta_description_content
        \Brain\Monkey\Filters\expectApplied('seo_meta_description_content')
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

        \Brain\Monkey\Filters\expectApplied('seo_meta_description_content')
            ->once()
            ->andReturn('');

        $plugin = new Plugin();
        ob_start();
        $plugin->render_meta_description();
        $output = (string) ob_get_clean();

        $this->assertSame('', $output);
    }
}
