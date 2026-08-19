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
}
