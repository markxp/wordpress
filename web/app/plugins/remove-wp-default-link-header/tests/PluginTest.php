<?php

declare(strict_types=1);

namespace Remove_WP_Default_Link_Header\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Remove_WP_Default_Link_Header\Plugin;
use Tests\TestCase;

class PluginTest extends TestCase
{
    public function test_plugin_registers_hooks_on_instantiation(): void
    {
        Actions\expectAdded('init')->once();
        Filters\expectAdded('xmlrpc_enabled')->once();
        Filters\expectAdded('wp_headers')->once();

        $plugin = new Plugin();
        $this->assertInstanceOf(Plugin::class, $plugin);
    }

    public function test_remove_wp_default_links_and_headers_removes_expected_actions(): void
    {
        Actions\expectRemoved('wp_head')->with('rest_output_link_wp_head', 10);
        Actions\expectRemoved('template_redirect')->with('rest_output_link_header', 11);
        Actions\expectRemoved('xmlrpc_rpc_methods')->with('rest_output_link_wp_head');

        Actions\expectRemoved('wp_head')->with('wp_shortlink_wp_head', 10);
        Actions\expectRemoved('template_redirect')->with('wp_shortlink_header', 11);

        Actions\expectRemoved('wp_head')->with('rsd_link');
        Actions\expectRemoved('wp_head')->with('wlwmanifest_link');

        $plugin = new Plugin();
        $plugin->remove_wp_default_links_and_headers();

        $this->assertTrue(true);
    }

    public function test_remove_pingback_header_unsets_pingback(): void
    {
        $plugin = new Plugin();

        $headersWithPingback = [
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Pingback'   => 'https://example.com/xmlrpc.php',
            'X-Frame-Options' => 'SAMEORIGIN',
        ];

        $cleaned = $plugin->remove_pingback_header($headersWithPingback);

        $this->assertArrayNotHasKey('X-Pingback', $cleaned);
        $this->assertArrayHasKey('Content-Type', $cleaned);
        $this->assertArrayHasKey('X-Frame-Options', $cleaned);
    }

    public function test_remove_pingback_header_handles_array_without_pingback(): void
    {
        $plugin = new Plugin();

        $headers = [
            'Content-Type' => 'text/html; charset=UTF-8',
        ];

        $cleaned = $plugin->remove_pingback_header($headers);
        $this->assertSame($headers, $cleaned);
    }
}
