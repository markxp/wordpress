<?php

declare(strict_types=1);

namespace HTTP_103_Early_Hints\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HTTP_103_Early_Hints\Plugin;
use Mockery;
use Tests\TestCase;

class PluginSettingsTest extends TestCase
{
    public function test_admin_hooks_registered_when_is_admin(): void
    {
        Functions\when('is_admin')->justReturn(true);
        Functions\when('plugin_basename')->justReturn('http-103-early-hints/http-103-early-hints.php');

        Actions\expectAdded('admin_menu')->once();
        Actions\expectAdded('admin_init')->once();
        Filters\expectAdded('plugin_action_links_http-103-early-hints/http-103-early-hints.php')->once();
        Actions\expectAdded('after_plugin_row_http-103-early-hints/http-103-early-hints.php')->once();

        $plugin = new Plugin();
        $this->assertInstanceOf(Plugin::class, $plugin);
    }

    public function test_register_admin_settings_page_calls_add_options_page(): void
    {
        Functions\expect('add_options_page')
            ->once()
            ->with(
                'HTTP 103 Early Hints Configuration & Status',
                'HTTP 103 Early Hints',
                'manage_options',
                'hlhg-settings',
                Mockery::type('array'),
            );

        $plugin = new Plugin();
        $plugin->register_admin_settings_page();
        $this->assertTrue(true);
    }

    public function test_register_plugin_settings_registers_settings_and_sections(): void
    {
        Functions\expect('register_setting')->twice();
        Functions\expect('add_settings_section')->twice();
        Functions\expect('add_settings_field')->twice();

        $plugin = new Plugin();
        $plugin->register_plugin_settings();
        $this->assertTrue(true);
    }

    public function test_add_action_links_prepends_settings_link(): void
    {
        Functions\when('admin_url')->justReturn('https://example.com/wp-admin/options-general.php?page=hlhg-settings');

        $plugin = new Plugin();
        $links = ['<a href="plugins.php?action=deactivate">Deactivate</a>'];
        $updated = $plugin->add_action_links($links);

        $this->assertCount(2, $updated);
        $this->assertStringContainsString('Settings & Status', $updated[0]);
    }
}
