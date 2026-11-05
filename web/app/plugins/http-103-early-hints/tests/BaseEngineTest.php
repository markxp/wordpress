<?php

declare(strict_types=1);

namespace HTTP_103_Early_Hints\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HTTP_103_Early_Hints\Base;
use Tests\TestCase;
use WP_Scripts;
use WP_Styles;

class TestableEarlyHints extends Base
{
    public bool $mockHeadersSent = false;
    public ?string $emittedHeader = null;

    protected function headers_sent(): bool
    {
        return $this->mockHeadersSent;
    }

    protected function send_link_header(string $header_value): void
    {
        $this->emittedHeader = $header_value;
    }
}

class BaseEngineTest extends TestCase
{
    public function test_constructor_registers_hooks(): void
    {
        Actions\expectAdded('template_redirect')->once();
        Filters\expectAdded('should_load_separate_core_block_assets')->once();
        Filters\expectAdded('styles_inline_size_limit')->once();

        $instance = new TestableEarlyHints();
        $this->assertInstanceOf(Base::class, $instance);
    }

    public function test_should_load_separate_core_block_assets_reads_option(): void
    {
        Functions\when('get_option')->justReturn('1');
        Functions\when('apply_filters')->returnArg(2);

        $instance = new TestableEarlyHints();
        $result = $instance->should_load_separate_core_block_assets(false);

        $this->assertTrue($result);
    }

    public function test_styles_inline_size_limit_reads_option(): void
    {
        Functions\when('get_option')->justReturn('500');
        Functions\when('apply_filters')->returnArg(2);

        $instance = new TestableEarlyHints();
        $result = $instance->styles_inline_size_limit(0);

        $this->assertSame(500, $result);
    }

    public function test_inspect_and_emit_link_headers_bails_on_admin(): void
    {
        Functions\when('is_admin')->justReturn(true);
        Functions\when('wp_is_json_request')->justReturn(false);

        Actions\expectDone('wp_enqueue_scripts')->never();

        $instance = new TestableEarlyHints();
        $instance->inspect_and_emit_link_headers();

        $this->assertTrue(true);
    }

    public function test_inspect_and_emit_link_headers_processes_styles_and_scripts(): void
    {
        Functions\when('is_admin')->justReturn(false);
        Functions\when('wp_is_json_request')->justReturn(false);
        Functions\when('get_stylesheet')->justReturn('twentytwentyfour');
        Functions\when('did_action')->justReturn(false);
        Functions\when('get_option')->justReturn('0');
        Functions\when('site_url')->returnArg();
        Functions\when('add_query_arg')->alias(function ($key, $val, $url) {
            return $url . '?' . $key . '=' . $val;
        });

        // Mock preload resources
        Filters\expectApplied('wp_preload_resources')
            ->once()
            ->andReturn([
                ['href' => 'https://example.com/font.woff2', 'as' => 'font', 'crossorigin' => 'anonymous'],
            ]);

        global $wp_styles, $wp_scripts;
        $wp_styles = new WP_Styles();
        $wp_scripts = new WP_Scripts();

        // Enqueue styles
        $wp_styles->queue = ['main-style', 'print-style'];
        $wp_styles->registered = [
            'main-style' => (object) ['src' => 'https://example.com/style.css', 'ver' => '1.0.0', 'args' => 'all'],
            'print-style' => (object) ['src' => 'https://example.com/print.css', 'ver' => '1.0.0', 'args' => 'print'],
        ];

        // Enqueue scripts (1 sync header, 1 async, 1 footer)
        $wp_scripts->queue = ['header-sync', 'header-async', 'footer-script'];
        $wp_scripts->registered = [
            'header-sync' => (object) ['src' => 'https://example.com/app.js', 'ver' => '2.0.0'],
            'header-async' => (object) ['src' => 'https://example.com/async.js', 'ver' => '2.0.0'],
            'footer-script' => (object) ['src' => 'https://example.com/footer.js', 'ver' => '2.0.0'],
        ];
        $wp_scripts->add_data('header-async', 'strategy', 'async');
        $wp_scripts->add_data('footer-script', 'group', 1);

        $instance = new TestableEarlyHints();
        $instance->mockHeadersSent = false;
        $instance->inspect_and_emit_link_headers();

        $this->assertNotNull($instance->emittedHeader);
        $this->assertStringContainsString('https://example.com/font.woff2', $instance->emittedHeader);
        $this->assertStringContainsString('https://example.com/style.css?ver=1.0.0', $instance->emittedHeader);
        $this->assertStringContainsString('https://example.com/app.js?ver=2.0.0', $instance->emittedHeader);
        $this->assertStringNotContainsString('print.css', $instance->emittedHeader);
        $this->assertStringNotContainsString('async.js', $instance->emittedHeader);
        $this->assertStringNotContainsString('footer.js', $instance->emittedHeader);
    }

    public function test_inspect_and_emit_link_headers_applies_style_and_script_loader_filters(): void
    {
        Functions\when('is_admin')->justReturn(false);
        Functions\when('wp_is_json_request')->justReturn(false);
        Functions\when('get_stylesheet')->justReturn('twentytwentyfour');
        Functions\when('did_action')->justReturn(false);
        Functions\when('get_option')->justReturn('0');
        Functions\when('site_url')->returnArg();
        Functions\when('add_query_arg')->alias(function ($key, $val, $url) {
            return $url . '?' . $key . '=' . $val;
        });

        // Filter hooks: style_loader_src rewrites URL; script_loader_src drops the script
        Filters\expectApplied('style_loader_src')
            ->once()
            ->andReturnUsing(function ($src, $handle) {
                return $src . '&hashed=custom123';
            });
        Filters\expectApplied('script_loader_src')
            ->once()
            ->andReturn(''); // Returning empty string excludes the asset

        global $wp_styles, $wp_scripts;
        $wp_styles = new WP_Styles();
        $wp_scripts = new WP_Scripts();

        $wp_styles->queue = ['theme-style'];
        $wp_styles->registered = [
            'theme-style' => (object) ['src' => 'https://example.com/theme.css', 'ver' => '1.0.0'],
        ];

        $wp_scripts->queue = ['app-script'];
        $wp_scripts->registered = [
            'app-script' => (object) ['src' => 'https://example.com/app.js', 'ver' => '1.0.0'],
        ];

        $instance = new TestableEarlyHints();
        $instance->mockHeadersSent = false;
        $instance->inspect_and_emit_link_headers();

        $this->assertNotNull($instance->emittedHeader);
        $this->assertStringContainsString('https://example.com/theme.css?ver=1.0.0&hashed=custom123', $instance->emittedHeader);
        $this->assertStringNotContainsString('app.js', $instance->emittedHeader);
    }

    public function test_inspect_and_emit_link_headers_skips_inlined_styles_under_cumulative_limit(): void
    {
        Functions\when('is_admin')->justReturn(false);
        Functions\when('wp_is_json_request')->justReturn(false);
        Functions\when('get_stylesheet')->justReturn('twentytwentyfour');
        Functions\when('did_action')->justReturn(false);
        Functions\when('get_option')->justReturn('150'); // 150 bytes cumulative limit
        Functions\when('site_url')->returnArg();
        Functions\when('add_query_arg')->alias(function ($key, $val, $url) {
            return $url . '?' . $key . '=' . $val;
        });

        // Create 3 temporary CSS files of 100 bytes each
        $tmp1 = tempnam(sys_get_temp_dir(), 'css1_');
        $tmp2 = tempnam(sys_get_temp_dir(), 'css2_');
        $tmp3 = tempnam(sys_get_temp_dir(), 'css3_');
        file_put_contents($tmp1, str_repeat('a', 100)); // 100 bytes (<= 150: skipped, cumulative = 100)
        file_put_contents($tmp2, str_repeat('b', 100)); // 100 bytes (100 + 100 = 200 > 150: NOT skipped)
        file_put_contents($tmp3, str_repeat('c', 20));  // 20 bytes with 'after' inline data (NOT skipped)

        try {
            global $wp_styles, $wp_scripts;
            $wp_styles = new WP_Styles();
            $wp_scripts = new WP_Scripts();

            $wp_styles->queue = ['style-one', 'style-two', 'style-three'];
            $wp_styles->registered = [
                'style-one'   => (object) ['src' => 'https://example.com/one.css', 'ver' => null],
                'style-two'   => (object) ['src' => 'https://example.com/two.css', 'ver' => null],
                'style-three' => (object) ['src' => 'https://example.com/three.css', 'ver' => null],
            ];
            $wp_styles->add_data('style-one', 'path', $tmp1);
            $wp_styles->add_data('style-two', 'path', $tmp2);
            $wp_styles->add_data('style-three', 'path', $tmp3);
            $wp_styles->add_data('style-three', 'after', ['/* inline after */']);

            $instance = new TestableEarlyHints();
            $instance->mockHeadersSent = false;
            $instance->inspect_and_emit_link_headers();

            $this->assertNotNull($instance->emittedHeader);
            $this->assertStringNotContainsString('one.css', $instance->emittedHeader);
            $this->assertStringContainsString('two.css', $instance->emittedHeader);
            $this->assertStringContainsString('three.css', $instance->emittedHeader);
        } finally {
            @unlink($tmp1);
            @unlink($tmp2);
            @unlink($tmp3);
        }
    }

    public function test_inspect_and_emit_link_headers_skips_scripts_with_legacy_script_execution(): void
    {
        Functions\when('is_admin')->justReturn(false);
        Functions\when('wp_is_json_request')->justReturn(false);
        Functions\when('get_stylesheet')->justReturn('twentytwentyfour');
        Functions\when('did_action')->justReturn(false);
        Functions\when('get_option')->justReturn('0');
        Functions\when('site_url')->returnArg();
        Functions\when('add_query_arg')->alias(function ($key, $val, $url) {
            return $url . '?' . $key . '=' . $val;
        });

        global $wp_styles, $wp_scripts;
        $wp_styles = new WP_Styles();
        $wp_scripts = new WP_Scripts();

        $wp_scripts->queue = ['sync-script', 'defer-legacy', 'async-legacy'];
        $wp_scripts->registered = [
            'sync-script'  => (object) ['src' => 'https://example.com/sync.js', 'ver' => '1.0.0'],
            'defer-legacy' => (object) ['src' => 'https://example.com/defer.js', 'ver' => '1.0.0'],
            'async-legacy' => (object) ['src' => 'https://example.com/async.js', 'ver' => '1.0.0'],
        ];
        $wp_scripts->add_data('defer-legacy', 'script_execution', 'defer');
        $wp_scripts->add_data('async-legacy', 'script_execution', 'async');

        $instance = new TestableEarlyHints();
        $instance->mockHeadersSent = false;
        $instance->inspect_and_emit_link_headers();

        $this->assertNotNull($instance->emittedHeader);
        $this->assertStringContainsString('sync.js', $instance->emittedHeader);
        $this->assertStringNotContainsString('defer.js', $instance->emittedHeader);
        $this->assertStringNotContainsString('async.js', $instance->emittedHeader);
    }

    public function test_inspect_and_emit_link_headers_caps_at_five_preload_resources(): void
    {
        Functions\when('is_admin')->justReturn(false);
        Functions\when('wp_is_json_request')->justReturn(false);
        Functions\when('get_stylesheet')->justReturn('twentytwentyfour');
        Functions\when('did_action')->justReturn(false);
        Functions\when('get_option')->justReturn('0');
        Functions\when('site_url')->returnArg();
        Functions\when('add_query_arg')->alias(function ($key, $val, $url) {
            return $url . '?' . $key . '=' . $val;
        });

        global $wp_styles, $wp_scripts;
        $wp_styles = new WP_Styles();
        $wp_scripts = new WP_Scripts();

        // Register 8 distinct stylesheets
        $wp_styles->queue = ['s1', 's2', 's3', 's4', 's5', 's6', 's7', 's8'];
        foreach (range(1, 8) as $i) {
            $wp_styles->registered["s{$i}"] = (object) ['src' => "https://example.com/style{$i}.css", 'ver' => null];
        }

        $instance = new TestableEarlyHints();
        $instance->mockHeadersSent = false;
        $instance->inspect_and_emit_link_headers();

        $this->assertNotNull($instance->emittedHeader);
        $parts = explode(', ', $instance->emittedHeader);
        $this->assertCount(5, $parts);
        $this->assertStringContainsString('style1.css', $instance->emittedHeader);
        $this->assertStringContainsString('style5.css', $instance->emittedHeader);
        $this->assertStringNotContainsString('style6.css', $instance->emittedHeader);
    }

    public function test_inspect_and_emit_link_headers_skips_when_headers_already_sent(): void
    {
        Functions\when('is_admin')->justReturn(false);
        Functions\when('wp_is_json_request')->justReturn(false);

        $instance = new TestableEarlyHints();
        $instance->mockHeadersSent = true;
        $instance->inspect_and_emit_link_headers();

        $this->assertNull($instance->emittedHeader);
    }

    public function test_inspect_and_emit_link_headers_bails_on_json_request(): void
    {
        Functions\when('is_admin')->justReturn(false);
        Functions\when('wp_is_json_request')->justReturn(true);

        $instance = new TestableEarlyHints();
        $instance->mockHeadersSent = false;
        $instance->inspect_and_emit_link_headers();

        $this->assertNull($instance->emittedHeader);
    }

    public function test_inspect_and_emit_link_headers_deduplicates_same_asset_url(): void
    {
        Functions\when('is_admin')->justReturn(false);
        Functions\when('wp_is_json_request')->justReturn(false);
        Functions\when('get_stylesheet')->justReturn('twentytwentyfour');
        Functions\when('did_action')->justReturn(false);
        Functions\when('get_option')->justReturn('0');
        Functions\when('site_url')->returnArg();
        Functions\when('add_query_arg')->alias(function ($key, $val, $url) {
            return $url . '?' . $key . '=' . $val;
        });

        global $wp_styles, $wp_scripts;
        $wp_styles = new WP_Styles();
        $wp_scripts = new WP_Scripts();

        // Two handles pointing to the exact same URL
        $wp_styles->queue = ['handle-a', 'handle-b'];
        $wp_styles->registered = [
            'handle-a' => (object) ['src' => 'https://example.com/shared.css', 'ver' => '1.0'],
            'handle-b' => (object) ['src' => 'https://example.com/shared.css', 'ver' => '1.0'],
        ];

        $instance = new TestableEarlyHints();
        $instance->mockHeadersSent = false;
        $instance->inspect_and_emit_link_headers();

        $this->assertNotNull($instance->emittedHeader);
        $this->assertSame('<https://example.com/shared.css?ver=1.0>; rel=preload; as=style', $instance->emittedHeader);
    }

    public function test_inspect_and_emit_link_headers_supports_protocol_relative_urls(): void
    {
        Functions\when('is_admin')->justReturn(false);
        Functions\when('wp_is_json_request')->justReturn(false);
        Functions\when('get_stylesheet')->justReturn('twentytwentyfour');
        Functions\when('did_action')->justReturn(false);
        Functions\when('get_option')->justReturn('0');
        Functions\when('site_url')->justReturn('https://my-site.com');

        global $wp_styles, $wp_scripts;
        $wp_styles = new WP_Styles();
        $wp_scripts = new WP_Scripts();

        $wp_styles->queue = ['cdn-style'];
        $wp_styles->registered = [
            'cdn-style' => (object) ['src' => '//cdn.example.com/lib.css', 'ver' => null],
        ];

        $instance = new TestableEarlyHints();
        $instance->mockHeadersSent = false;
        $instance->inspect_and_emit_link_headers();

        $this->assertNotNull($instance->emittedHeader);
        // Should NOT prepend site_url() to protocol-relative URLs
        $this->assertStringContainsString('//cdn.example.com/lib.css', $instance->emittedHeader);
        $this->assertStringNotContainsString('my-site.com', $instance->emittedHeader);
    }

    public function test_inspect_and_emit_link_headers_does_not_skip_styles_when_inline_limit_zero(): void
    {
        Functions\when('is_admin')->justReturn(false);
        Functions\when('wp_is_json_request')->justReturn(false);
        Functions\when('get_stylesheet')->justReturn('twentytwentyfour');
        Functions\when('did_action')->justReturn(false);
        Functions\when('get_option')->justReturn('0'); // Disabled inlining
        Functions\when('site_url')->returnArg();

        $tmp = tempnam(sys_get_temp_dir(), 'zero_limit_');
        file_put_contents($tmp, 'body { color: blue; }');

        try {
            global $wp_styles, $wp_scripts;
            $wp_styles = new WP_Styles();
            $wp_scripts = new WP_Scripts();

            $wp_styles->queue = ['always-external'];
            $wp_styles->registered = [
                'always-external' => (object) ['src' => 'https://example.com/ext.css', 'ver' => null],
            ];
            $wp_styles->add_data('always-external', 'path', $tmp);

            $instance = new TestableEarlyHints();
            $instance->mockHeadersSent = false;
            $instance->inspect_and_emit_link_headers();

            $this->assertNotNull($instance->emittedHeader);
            $this->assertStringContainsString('ext.css', $instance->emittedHeader);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_inspect_and_emit_link_headers_does_not_skip_styles_when_file_does_not_exist(): void
    {
        Functions\when('is_admin')->justReturn(false);
        Functions\when('wp_is_json_request')->justReturn(false);
        Functions\when('get_stylesheet')->justReturn('twentytwentyfour');
        Functions\when('did_action')->justReturn(false);
        Functions\when('get_option')->justReturn('50000');
        Functions\when('site_url')->returnArg();

        global $wp_styles, $wp_scripts;
        $wp_styles = new WP_Styles();
        $wp_scripts = new WP_Scripts();

        $wp_styles->queue = ['missing-file'];
        $wp_styles->registered = [
            'missing-file' => (object) ['src' => 'https://example.com/missing.css', 'ver' => null],
        ];
        $wp_styles->add_data('missing-file', 'path', '/path/to/nonexistent/file.css');

        $instance = new TestableEarlyHints();
        $instance->mockHeadersSent = false;
        $instance->inspect_and_emit_link_headers();

        $this->assertNotNull($instance->emittedHeader);
        $this->assertStringContainsString('missing.css', $instance->emittedHeader);
    }
}
