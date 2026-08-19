<?php

declare(strict_types=1);

namespace Legispect\Security\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Legispect\Security\Plugin;
use Mockery;
use Tests\TestCase;
use WP_Error;

class PluginTest extends TestCase
{
    public function test_env_enabled_detection(): void
    {
        putenv('SECURITY_HARDENING_ENABLED=false');
        $plugin = new Plugin();
        $this->assertFalse($plugin->is_env_enabled());
        $this->assertFalse($plugin->is_instance_on());

        putenv('SECURITY_HARDENING_ENABLED=true');
        $plugin = new Plugin();
        $this->assertTrue($plugin->is_env_enabled());
        $this->assertTrue($plugin->is_instance_on());

        putenv('SECURITY_HARDENING_ENABLED'); // unset
    }

    public function test_hooks_registered_when_instance_on(): void
    {
        putenv('SECURITY_HARDENING_ENABLED=true');
        Functions\when('is_admin')->justReturn(false);

        Actions\expectAdded('init')->once();
        Filters\expectAdded('rest_authentication_errors')->once();
        Actions\expectAdded('do_feed')->once();
        Actions\expectAdded('do_feed_rss2')->once();
        Filters\expectAdded('request')->once();
        Actions\expectAdded('template_redirect')->once();
        Filters\expectAdded('the_generator')->once();
        Filters\expectAdded('style_loader_src')->once();
        Filters\expectAdded('script_loader_src')->once();

        Actions\expectRemoved('wp_head')->with('feed_links', 2);
        Actions\expectRemoved('wp_head')->with('feed_links_extra', 3);
        Actions\expectRemoved('wp_head')->with('wp_generator');

        $plugin = new Plugin();
        $this->assertInstanceOf(Plugin::class, $plugin);

        putenv('SECURITY_HARDENING_ENABLED');
    }

    public function test_admin_hooks_registered_when_is_admin(): void
    {
        putenv('SECURITY_HARDENING_ENABLED=false');
        Functions\when('is_admin')->justReturn(true);
        Functions\when('plugin_basename')->justReturn('security-hardening/security-hardening.php');

        Actions\expectAdded('admin_menu')->once();
        Filters\expectAdded('plugin_action_links_security-hardening/security-hardening.php')->once();
        Actions\expectAdded('after_plugin_row_security-hardening/security-hardening.php')->once();

        $plugin = new Plugin();
        $this->assertInstanceOf(Plugin::class, $plugin);
    }

    public function test_block_author_query_vars_strips_author_for_guests(): void
    {
        Functions\when('is_user_logged_in')->justReturn(false);

        $plugin = new Plugin();
        $vars = ['author' => '1', 'p' => '123'];
        $filtered = $plugin->block_author_query_vars($vars);

        $this->assertArrayNotHasKey('author', $filtered);
        $this->assertSame(['p' => '123'], $filtered);
    }

    public function test_block_author_query_vars_retains_author_for_logged_in_users(): void
    {
        Functions\when('is_user_logged_in')->justReturn(true);

        $plugin = new Plugin();
        $vars = ['author' => '1', 'p' => '123'];
        $filtered = $plugin->block_author_query_vars($vars);

        $this->assertSame($vars, $filtered);
    }

    public function test_remove_wp_ver_from_assets_removes_version_string(): void
    {
        Functions\when('get_bloginfo')->justReturn('6.8.0');
        Functions\when('remove_query_arg')->alias(function ($key, $url) {
            return preg_replace('/([?&])' . preg_quote($key, '/') . '=[^&]*(&?)/', '$1', $url);
        });

        $plugin = new Plugin();
        $src = 'https://example.com/wp-includes/css/dist/block-library/style.min.css?ver=6.8.0';
        $cleaned = $plugin->remove_wp_ver_from_assets($src);

        $this->assertStringNotContainsString('ver=6.8.0', $cleaned);
    }

    public function test_restrict_rest_api_allows_logged_in_users(): void
    {
        Functions\when('is_user_logged_in')->justReturn(true);

        $plugin = new Plugin();
        $result = $plugin->restrict_rest_api(null);

        $this->assertNull($result);
    }

    public function test_restrict_rest_api_blocks_anonymous_write_methods(): void
    {
        Functions\when('is_user_logged_in')->justReturn(false);
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $plugin = new Plugin();
        $result = $plugin->restrict_rest_api(null);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('rest_cannot_access', $result->get_error_code());

        unset($_SERVER['REQUEST_METHOD']);
    }

    public function test_restrict_rest_api_allows_whitelisted_get_routes(): void
    {
        Functions\when('is_user_logged_in')->justReturn(false);
        Functions\when('wp_parse_url')->justReturn('/wp-json/wp/v2/posts');
        Functions\when('rest_get_url_prefix')->justReturn('wp-json');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';

        $plugin = new Plugin();
        $result = $plugin->restrict_rest_api(null);

        $this->assertNull($result);

        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
    }

    public function test_restrict_rest_api_blocks_sensitive_endpoints_like_users(): void
    {
        Functions\when('is_user_logged_in')->justReturn(false);
        Functions\when('wp_parse_url')->justReturn('/wp-json/wp/v2/users');
        Functions\when('rest_get_url_prefix')->justReturn('wp-json');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/users';

        $plugin = new Plugin();
        $result = $plugin->restrict_rest_api(null);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('rest_cannot_access', $result->get_error_code());

        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
    }

    public function test_block_query_parameter_bypasses_dies_on_rest_route(): void
    {
        Functions\when('is_user_logged_in')->justReturn(false);
        $_GET['rest_route'] = '/wp/v2/users';

        Functions\expect('wp_die')
            ->once()
            ->with(
                'Direct rest_route query parameter access is disabled for security.',
                'Bad Request',
                ['response' => 400],
            );

        $plugin = new Plugin();
        $plugin->block_query_parameter_bypasses();
        $this->assertTrue(true);

        unset($_GET['rest_route']);
    }

    public function test_register_admin_menu_calls_add_options_page(): void
    {
        Functions\expect('add_options_page')
            ->once()
            ->with(
                'Security Hardening Status',
                'Security Hardening',
                'manage_options',
                'security-hardening',
                Mockery::type('array'),
            );

        $plugin = new Plugin();
        $plugin->register_admin_menu();
        $this->assertTrue(true);
    }

    public function test_add_action_links_prepends_status_link(): void
    {
        Functions\when('admin_url')->justReturn('https://example.com/wp-admin/options-general.php?page=security-hardening');

        $plugin = new Plugin();
        $links = ['<a href="plugins.php?action=deactivate">Deactivate</a>'];
        $updated = $plugin->add_action_links($links);

        $this->assertCount(2, $updated);
        $this->assertStringContainsString('Status & Settings', $updated[0]);
    }
}
