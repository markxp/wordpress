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
    public function test_hooks_registered_on_instantiation(): void
    {
        Functions\when('is_admin')->justReturn(false);

        Actions\expectAdded('init')->once();
        Filters\expectAdded('rest_authentication_errors')->once();
        Actions\expectAdded('do_feed')->once();
        Actions\expectAdded('do_feed_rss2')->once();
        Filters\expectAdded('request')->once();
        Actions\expectAdded('template_redirect')->once();
        Filters\expectAdded('pings_open')->once();
        Actions\expectAdded('pre_trackback_post')->once();
        Filters\expectAdded('the_generator')->once();
        Filters\expectAdded('style_loader_src')->once();
        Filters\expectAdded('script_loader_src')->once();

        Actions\expectRemoved('wp_head')->with('feed_links', 2);
        Actions\expectRemoved('wp_head')->with('feed_links_extra', 3);
        Actions\expectRemoved('wp_head')->with('wp_generator');

        $plugin = new Plugin();
        $this->assertInstanceOf(Plugin::class, $plugin);
    }

    public function test_admin_hooks_registered_when_is_admin(): void
    {
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
        $vars = ['author' => '1', 'author_name' => 'admin', 'p' => '123'];
        $filtered = $plugin->block_author_query_vars($vars);

        $this->assertArrayNotHasKey('author', $filtered);
        $this->assertArrayNotHasKey('author_name', $filtered);
        $this->assertSame(['p' => '123'], $filtered);
    }

    public function test_block_author_query_vars_dies_on_pretty_permalink_trackback_for_guests(): void
    {
        Functions\when('is_user_logged_in')->justReturn(false);
        Functions\expect('wp_die')
            ->once()
            ->with(
                'Trackbacks are disabled on this site.',
                'Forbidden',
                ['response' => 403],
            );

        $plugin = new Plugin();
        $plugin->block_author_query_vars(['name' => 'post-slug', 'tb' => '1']);
        $this->assertTrue(true);
    }

    public function test_block_trackback_execution_dies_with_403(): void
    {
        Functions\expect('wp_die')
            ->once()
            ->with(
                'Trackbacks are disabled on this site.',
                'Forbidden',
                ['response' => 403],
            );

        $plugin = new Plugin();
        $plugin->block_trackback_execution();
        $this->assertTrue(true);
    }

    public function test_block_author_query_vars_retains_author_for_logged_in_users(): void
    {
        Functions\when('is_user_logged_in')->justReturn(true);

        $plugin = new Plugin();
        $vars = ['author' => '1', 'p' => '123'];
        $filtered = $plugin->block_author_query_vars($vars);

        $this->assertSame($vars, $filtered);
    }

    public function test_remove_wp_ver_from_assets_hashes_core_version_string(): void
    {
        Functions\when('get_bloginfo')->justReturn('6.8.0');
        Functions\when('wp_salt')->justReturn('test-nonce-salt');
        Functions\when('remove_query_arg')->alias(function ($key, $url) {
            return preg_replace('/([?&])' . preg_quote($key, '/') . '=[^&]*(&?)/', '', $url);
        });
        Functions\when('add_query_arg')->alias(function ($key, $val, $url) {
            $sep = str_contains($url, '?') ? '&' : '?';
            return $url . $sep . $key . '=' . $val;
        });

        $plugin = new Plugin();
        $src = 'https://example.com/wp-includes/css/dist/block-library/style.min.css?ver=6.8.0';
        $cleaned = $plugin->remove_wp_ver_from_assets($src);

        $this->assertStringNotContainsString('ver=6.8.0', $cleaned);
        $this->assertMatchesRegularExpression('/\?ver=[a-f0-9]{8}$/', $cleaned);

        // Plugin and third-party versions are left untouched
        $pluginSrc = 'https://example.com/app/plugins/custom/app.js?ver=1.2.3';
        $this->assertSame($pluginSrc, $plugin->remove_wp_ver_from_assets($pluginSrc));
    }

    public function test_remove_wp_ver_from_assets_handles_empty_or_non_string_inputs(): void
    {
        $plugin = new Plugin();
        $this->assertSame('', $plugin->remove_wp_ver_from_assets(''));
        $this->assertNull($plugin->remove_wp_ver_from_assets(null));
        $this->assertFalse($plugin->remove_wp_ver_from_assets(false));
    }

    public function test_remove_wp_ver_from_assets_ignores_assets_without_version_query_param(): void
    {
        Functions\when('get_bloginfo')->justReturn('6.8.0');

        $plugin = new Plugin();
        $srcNoVer = 'https://example.com/wp-includes/css/dist/style.min.css';
        $this->assertSame($srcNoVer, $plugin->remove_wp_ver_from_assets($srcNoVer));
    }

    public function test_remove_wp_ver_from_assets_falls_back_to_nonce_salt_constant(): void
    {
        Functions\when('get_bloginfo')->justReturn('6.8.0');
        // wp_salt returns empty string so fallback to NONCE_SALT constant occurs
        Functions\when('wp_salt')->justReturn('');
        Functions\when('remove_query_arg')->alias(function ($key, $url) {
            return preg_replace('/([?&])' . preg_quote($key, '/') . '=[^&]*(&?)/', '', $url);
        });
        Functions\when('add_query_arg')->alias(function ($key, $val, $url) {
            $sep = str_contains($url, '?') ? '&' : '?';
            return $url . $sep . $key . '=' . $val;
        });

        $plugin = new Plugin();
        $src = 'https://example.com/wp-includes/style.css?ver=6.8.0';
        $cleaned = $plugin->remove_wp_ver_from_assets($src);

        $this->assertStringNotContainsString('ver=6.8.0', $cleaned);
        $this->assertMatchesRegularExpression('/\?ver=[a-f0-9]{8}$/', $cleaned);
    }

    public function test_remove_wp_ver_from_assets_preserves_other_query_parameters(): void
    {
        Functions\when('get_bloginfo')->justReturn('6.8.0');
        Functions\when('wp_salt')->justReturn('test-salt');
        Functions\when('remove_query_arg')->alias(function ($key, $url) {
            $url = preg_replace('/([?&])' . preg_quote($key, '/') . '=[^&]*(&?)/', '$1', $url);
            return rtrim(rtrim($url, '&'), '?');
        });
        Functions\when('add_query_arg')->alias(function ($key, $val, $url) {
            $sep = str_contains($url, '?') ? '&' : '?';
            return $url . $sep . $key . '=' . $val;
        });

        $plugin = new Plugin();
        $src = 'https://example.com/wp-includes/style.css?media=all&ver=6.8.0&debug=true';
        $cleaned = $plugin->remove_wp_ver_from_assets($src);

        $this->assertStringNotContainsString('ver=6.8.0', $cleaned);
        $this->assertStringContainsString('media=all', $cleaned);
        $this->assertStringContainsString('debug=true', $cleaned);
        $this->assertMatchesRegularExpression('/&ver=[a-f0-9]{8}/', $cleaned);
    }

    public function test_remove_wp_ver_from_assets_avoids_prefix_and_param_name_collisions_and_handles_fragments(): void
    {
        Functions\when('get_bloginfo')->justReturn('6.8');
        Functions\when('wp_salt')->justReturn('test-salt');
        Functions\when('remove_query_arg')->alias(function ($key, $url) {
            return preg_replace('/([?&])' . preg_quote($key, '/') . '=[^&#]*(&?)/', '', $url);
        });
        Functions\when('add_query_arg')->alias(function ($key, $val, $url) {
            if (str_contains($url, '#')) {
                [$base, $frag] = explode('#', $url, 2);
                $sep = str_contains($base, '?') ? '&' : '?';
                return $base . $sep . $key . '=' . $val . '#' . $frag;
            }
            $sep = str_contains($url, '?') ? '&' : '?';
            return $url . $sep . $key . '=' . $val;
        });

        $plugin = new Plugin();

        // 1. Plugin version '6.8.2' must NOT match when core version is '6.8'
        $pluginPatchVer = 'https://example.com/app/plugins/foo/app.js?ver=6.8.2';
        $this->assertSame($pluginPatchVer, $plugin->remove_wp_ver_from_assets($pluginPatchVer));

        // 2. Query parameter ending in 'ver=' ('?server=6.8') must NOT match 'ver=6.8'
        $paramCollision = 'https://example.com/app/plugins/foo/app.js?server=6.8';
        $this->assertSame($paramCollision, $plugin->remove_wp_ver_from_assets($paramCollision));

        // 3. Exact core version followed by URL fragment ('?ver=6.8#icon') MUST be hashed
        $withFragment = 'https://example.com/wp-includes/images/sprite.svg?ver=6.8#icon';
        $cleanedFragment = $plugin->remove_wp_ver_from_assets($withFragment);
        $this->assertMatchesRegularExpression('/\?ver=[a-f0-9]{8}#icon$/', $cleanedFragment);
    }

    public function test_remove_wp_ver_from_assets_memoizes_version_and_hash_across_multiple_assets(): void
    {
        Functions\expect('get_bloginfo')->once()->with('version')->andReturn('6.8.0');
        Functions\expect('wp_salt')->once()->with('nonce')->andReturn('memoized-salt');
        Functions\when('remove_query_arg')->alias(function ($key, $url) {
            return preg_replace('/([?&])' . preg_quote($key, '/') . '=[^&]*(&?)/', '', $url);
        });
        Functions\when('add_query_arg')->alias(function ($key, $val, $url) {
            $sep = str_contains($url, '?') ? '&' : '?';
            return $url . $sep . $key . '=' . $val;
        });

        $plugin = new Plugin();
        $first = $plugin->remove_wp_ver_from_assets('https://example.com/wp-includes/a.css?ver=6.8.0');
        $second = $plugin->remove_wp_ver_from_assets('https://example.com/wp-includes/b.js?ver=6.8.0');

        $this->assertMatchesRegularExpression('/\?ver=[a-f0-9]{8}$/', $first);
        $this->assertMatchesRegularExpression('/\?ver=[a-f0-9]{8}$/', $second);
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

    public function test_restrict_rest_api_supports_custom_filter_prefixes(): void
    {
        Functions\when('is_user_logged_in')->justReturn(false);
        Functions\when('wp_parse_url')->justReturn('/wp-json/my-custom/v1/items');
        Functions\when('rest_get_url_prefix')->justReturn('wp-json');
        Filters\expectApplied('security_hardening_allowed_rest_prefixes')
            ->once()
            ->andReturn(['/wp/v2/posts', '/my-custom/v1']);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/wp-json/my-custom/v1/items';

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

    public function test_restrict_rest_api_allows_options_cors_preflight(): void
    {
        Functions\when('is_user_logged_in')->justReturn(false);
        $_SERVER['REQUEST_METHOD'] = 'OPTIONS';

        $plugin = new Plugin();
        $result = $plugin->restrict_rest_api(null);

        $this->assertNull($result);

        unset($_SERVER['REQUEST_METHOD']);
    }

    public function test_restrict_rest_api_allows_head_requests_to_whitelisted_routes(): void
    {
        Functions\when('is_user_logged_in')->justReturn(false);
        Functions\when('wp_parse_url')->justReturn('/wp-json/wp/v2/pages');
        Functions\when('rest_get_url_prefix')->justReturn('wp-json');
        $_SERVER['REQUEST_METHOD'] = 'HEAD';
        $_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/pages';

        $plugin = new Plugin();
        $result = $plugin->restrict_rest_api(null);

        $this->assertNull($result);

        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
    }

    public function test_block_query_parameter_bypasses_dies_on_trackback_parameter(): void
    {
        Functions\when('is_user_logged_in')->justReturn(false);
        $_POST['tb'] = '1';

        Functions\expect('wp_die')
            ->once()
            ->with(
                'Trackbacks are disabled on this site.',
                'Forbidden',
                ['response' => 403],
            );

        $plugin = new Plugin();
        $plugin->block_query_parameter_bypasses();
        $this->assertTrue(true);

        unset($_POST['tb']);
    }

    public function test_block_query_parameter_bypasses_allows_all_for_logged_in_users(): void
    {
        Functions\when('is_user_logged_in')->justReturn(true);
        $_GET['rest_route'] = '/wp/v2/users';
        $_GET['tb'] = '1';

        Functions\expect('wp_die')->never();

        $plugin = new Plugin();
        $plugin->block_query_parameter_bypasses();
        $this->assertTrue(true);

        unset($_GET['rest_route'], $_GET['tb']);
    }

    public function test_register_admin_menu_calls_add_submenu_page(): void
    {
        Functions\expect('add_submenu_page')
            ->once()
            ->with(
                'options.php',
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

    public function test_add_action_links_prepends_settings_link(): void
    {
        Functions\when('admin_url')->justReturn('https://example.com/wp-admin/admin.php?page=security-hardening');

        $plugin = new Plugin();
        $links = ['<a href="plugins.php?action=deactivate">Deactivate</a>'];
        $updated = $plugin->add_action_links($links);

        $this->assertCount(2, $updated);
        $this->assertStringContainsString('Settings', $updated[0]);
    }

    public function test_disable_feed_handler_forces_html_content_type_on_404(): void
    {
        global $wp_query;
        $wp_query = Mockery::mock();
        $wp_query->shouldReceive('set_404')->once();

        Functions\expect('status_header')->once()->with(404);
        Functions\expect('nocache_headers')->once();
        Functions\when('get_query_template')->justReturn('');
        Functions\expect('wp_die')
            ->once()
            ->with('Resource not found.', 'Not Found', ['response' => 404])
            ->andThrow(new \RuntimeException('wp_die'));

        $plugin = new TestablePlugin(false);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('wp_die');
            $plugin->disable_feed_handler();
        } finally {
            $wp_query = null;
            $this->assertSame(['Content-Type: text/html; charset=UTF-8'], $plugin->sentHeaders);
        }
    }

    public function test_disable_feed_handler_skips_header_when_headers_already_sent(): void
    {
        global $wp_query;
        $wp_query = Mockery::mock();
        $wp_query->shouldReceive('set_404')->once();

        Functions\expect('status_header')->once()->with(404);
        Functions\expect('nocache_headers')->once();
        Functions\when('get_query_template')->justReturn('');
        Functions\expect('wp_die')
            ->once()
            ->with('Resource not found.', 'Not Found', ['response' => 404])
            ->andThrow(new \RuntimeException('wp_die'));

        $plugin = new TestablePlugin(true);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('wp_die');
            $plugin->disable_feed_handler();
        } finally {
            $wp_query = null;
            $this->assertSame([], $plugin->sentHeaders);
        }
    }
}

class TestablePlugin extends Plugin
{
    public array $sentHeaders = [];

    public function __construct(private readonly bool $headersAlreadySent)
    {
        parent::__construct();
    }

    protected function headers_sent(): bool
    {
        return $this->headersAlreadySent;
    }

    protected function send_header(string $header): void
    {
        $this->sentHeaders[] = $header;
    }
}
