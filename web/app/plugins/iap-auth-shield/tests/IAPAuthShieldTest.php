<?php

declare(strict_types=1);

namespace IAP_Auth_Shield\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use IAP_Auth_Shield\Plugin;
use Mockery;
use Tests\TestCase;
use WP_Error;

class TestableIAPPlugin extends Plugin
{
    public array $mockPayload = [];

    protected function verify_jwt($jwt)
    {
        return $this->mockPayload;
    }
}

class IAPAuthShieldTest extends TestCase
{
    public function test_instance_status_conditions(): void
    {
        putenv('IAP_AUTH_ENABLED=false');
        putenv('IAP_AUDIENCE=');
        $plugin = new Plugin();
        $this->assertFalse($plugin->is_instance_on());

        putenv('IAP_AUTH_ENABLED=true');
        putenv('IAP_AUDIENCE=');
        $plugin = new Plugin();
        $this->assertFalse($plugin->is_instance_on());

        putenv('IAP_AUTH_ENABLED=true');
        putenv('IAP_AUDIENCE=/projects/123/global/backendServices/456');
        $plugin = new Plugin();
        $this->assertTrue($plugin->is_instance_on());

        putenv('IAP_AUTH_ENABLED');
        putenv('IAP_AUDIENCE');
    }

    public function test_hooks_registration_when_instance_on(): void
    {
        putenv('IAP_AUTH_ENABLED=true');
        putenv('IAP_AUDIENCE=test-audience');
        Functions\when('is_admin')->justReturn(false);

        Actions\expectAdded('init')->once();
        Filters\expectAdded('rest_pre_dispatch')->once();
        Filters\expectAdded('wp_insert_post_data')->once();

        $plugin = new Plugin();
        $this->assertInstanceOf(Plugin::class, $plugin);

        putenv('IAP_AUTH_ENABLED');
        putenv('IAP_AUDIENCE');
    }

    public function test_admin_hooks_registration_when_is_admin(): void
    {
        putenv('IAP_AUTH_ENABLED=false');
        putenv('IAP_AUDIENCE=');
        Functions\when('is_admin')->justReturn(true);
        Functions\when('plugin_basename')->justReturn('iap-auth-shield/iap-auth-shield.php');

        Actions\expectAdded('admin_menu')->once();
        Filters\expectAdded('plugin_action_links_iap-auth-shield/iap-auth-shield.php')->once();
        Actions\expectAdded('after_plugin_row_iap-auth-shield/iap-auth-shield.php')->once();

        $plugin = new Plugin();
        $this->assertInstanceOf(Plugin::class, $plugin);
    }

    public function test_register_admin_menu_calls_add_submenu_page_with_null_parent(): void
    {
        Functions\expect('add_submenu_page')
            ->once()
            ->with(
                null,
                'IAP Auth Shield Status',
                'IAP Auth Shield',
                'manage_options',
                'iap-auth-shield',
                Mockery::type('array'),
            );

        $plugin = new Plugin();
        $plugin->register_admin_menu();
        $this->assertTrue(true);
    }

    public function test_add_action_links_prepends_settings_link(): void
    {
        Functions\when('admin_url')->justReturn('https://example.com/wp-admin/admin.php?page=iap-auth-shield');

        $plugin = new Plugin();
        $links = ['<a href="plugins.php?action=deactivate">Deactivate</a>'];
        $updated = $plugin->add_action_links($links);

        $this->assertCount(2, $updated);
        $this->assertStringContainsString('Settings', $updated[0]);
    }

    public function test_handle_iap_authentication_skips_frontend_requests(): void
    {
        putenv('IAP_AUTH_ENABLED=true');
        putenv('IAP_AUDIENCE=test-audience');

        Functions\when('is_admin')->justReturn(false);
        $GLOBALS['pagenow'] = 'index.php';

        $plugin = new Plugin();
        $plugin->handle_iap_authentication();

        $this->assertTrue(true);

        putenv('IAP_AUTH_ENABLED');
        putenv('IAP_AUDIENCE');
    }

    public function test_handle_iap_authentication_dies_when_jwt_header_missing(): void
    {
        putenv('IAP_AUTH_ENABLED=true');
        putenv('IAP_AUDIENCE=test-audience');

        Functions\when('is_admin')->justReturn(true);
        unset($_SERVER['HTTP_X_GOOG_IAP_JWT_ASSERTION']);

        Functions\expect('wp_die')
            ->once()
            ->with(
                'Access Denied: Missing IAP credentials. Please access through the correct domain.',
                'Unauthorized',
                ['response' => 401],
            );

        $plugin = new Plugin();
        $plugin->handle_iap_authentication();

        $this->assertTrue(true);

        putenv('IAP_AUTH_ENABLED');
        putenv('IAP_AUDIENCE');
    }

    public function test_handle_iap_authentication_dies_when_user_not_in_database(): void
    {
        putenv('IAP_AUTH_ENABLED=true');
        putenv('IAP_AUDIENCE=test-audience');

        Functions\when('is_admin')->justReturn(true);
        $_SERVER['HTTP_X_GOOG_IAP_JWT_ASSERTION'] = 'mock.jwt.token';

        Functions\when('get_user_by')->justReturn(false);

        Functions\expect('wp_die')
            ->once()
            ->with(
                Mockery::pattern('/Your Google account .* verified successfully, but there is no corresponding user/'),
                'Unauthorized',
                ['response' => 403],
            );

        $testPlugin = new TestableIAPPlugin();
        $testPlugin->mockPayload = ['email' => 'unknown@example.com'];
        $testPlugin->handle_iap_authentication();

        $this->assertTrue(true);

        unset($_SERVER['HTTP_X_GOOG_IAP_JWT_ASSERTION']);
        putenv('IAP_AUTH_ENABLED');
        putenv('IAP_AUDIENCE');
    }

    public function test_handle_iap_authentication_logs_in_valid_user(): void
    {
        putenv('IAP_AUTH_ENABLED=true');
        putenv('IAP_AUDIENCE=test-audience');

        Functions\when('is_admin')->justReturn(true);
        Functions\when('is_user_logged_in')->justReturn(false);
        $_SERVER['HTTP_X_GOOG_IAP_JWT_ASSERTION'] = 'mock.jwt.token';

        $mockUser = (object) ['ID' => 42, 'user_email' => 'admin@example.com'];
        Functions\when('get_user_by')->justReturn($mockUser);

        Functions\expect('wp_set_current_user')->once()->with(42);
        Functions\expect('wp_set_auth_cookie')->once()->with(42);

        $testPlugin = new TestableIAPPlugin();
        $testPlugin->mockPayload = ['email' => 'admin@example.com'];
        $testPlugin->handle_iap_authentication();

        $this->assertTrue(true);

        unset($_SERVER['HTTP_X_GOOG_IAP_JWT_ASSERTION']);
        putenv('IAP_AUTH_ENABLED');
        putenv('IAP_AUDIENCE');
    }

    public function test_api_firewall_allows_logged_in_users(): void
    {
        Functions\when('is_user_logged_in')->justReturn(true);

        $request = Mockery::mock();
        $request->shouldReceive('get_route')->andReturn('/wp/v2/users');
        $request->shouldReceive('get_method')->andReturn('POST');

        $plugin = new Plugin();
        $result = $plugin->api_firewall(null, null, $request);

        $this->assertNull($result);
    }

    public function test_api_firewall_allows_comments_post_and_get(): void
    {
        Functions\when('is_user_logged_in')->justReturn(false);

        $request = Mockery::mock();
        $request->shouldReceive('get_route')->andReturn('/wp/v2/comments');
        $request->shouldReceive('get_method')->andReturn('POST');

        $plugin = new Plugin();
        $result = $plugin->api_firewall(null, null, $request);

        $this->assertNull($result);
    }

    public function test_api_firewall_allows_whitelisted_get_requests(): void
    {
        Functions\when('is_user_logged_in')->justReturn(false);

        $request = Mockery::mock();
        $request->shouldReceive('get_route')->andReturn('/wp/v2/posts/1');
        $request->shouldReceive('get_method')->andReturn('GET');

        $plugin = new Plugin();
        $result = $plugin->api_firewall(null, null, $request);

        $this->assertNull($result);
    }

    public function test_api_firewall_supports_custom_filter_prefixes(): void
    {
        Functions\when('is_user_logged_in')->justReturn(false);
        Filters\expectApplied('iap_auth_shield_api_whitelist')
            ->once()
            ->andReturn(['/wp/v2/posts', '/my-custom/v1']);

        $request = Mockery::mock();
        $request->shouldReceive('get_route')->andReturn('/my-custom/v1/items');
        $request->shouldReceive('get_method')->andReturn('GET');

        $plugin = new Plugin();
        $result = $plugin->api_firewall(null, null, $request);

        $this->assertNull($result);
    }

    public function test_api_firewall_blocks_unauthorized_endpoints(): void
    {
        Functions\when('is_user_logged_in')->justReturn(false);

        $request = Mockery::mock();
        $request->shouldReceive('get_route')->andReturn('/wp/v2/users');
        $request->shouldReceive('get_method')->andReturn('GET');

        $plugin = new Plugin();
        $result = $plugin->api_firewall(null, null, $request);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('rest_forbidden', $result->get_error_code());
    }

    public function test_normalize_editor_urls_in_post_data_converts_current_origin_to_relative(): void
    {
        Functions\when('home_url')->justReturn('https://editor-bs4ppedptq-de.a.run.app');
        Functions\when('wp_parse_url')->justReturn('editor-bs4ppedptq-de.a.run.app');

        $plugin = new Plugin();
        $input = [
            'post_content' => '<!-- wp:cover {"url":"https:\/\/editor-bs4ppedptq-de.a.run.app\/app\/themes\/twentytwentyfour\/assets\/images\/icon-message.webp"} --><img src="https://editor-bs4ppedptq-de.a.run.app/app/themes/twentytwentyfour/assets/images/icon-message.webp"><img src="https://storage.googleapis.com/bucket/header.webp"><!-- /wp:cover -->',
        ];

        $output = $plugin->normalize_editor_urls_in_post_data($input);

        $this->assertSame(
            '<!-- wp:cover {"url":"\/app\/themes\/twentytwentyfour\/assets\/images\/icon-message.webp"} --><img src="/app/themes/twentytwentyfour/assets/images/icon-message.webp"><img src="https://storage.googleapis.com/bucket/header.webp"><!-- /wp:cover -->',
            $output['post_content'],
        );
    }

    public function test_normalize_editor_urls_handles_custom_port_and_multiple_schemes(): void
    {
        Functions\when('home_url')->justReturn('https://editor-dev.run.app:8443');
        Functions\when('wp_parse_url')->justReturn('editor-dev.run.app');

        $plugin = new Plugin();
        $input = [
            'post_content' => '<a href="https://editor-dev.run.app:8443/app/uploads/file.pdf">Doc</a><script src="http://editor-dev.run.app:8443/wp/wp-includes/js/script.js"></script>',
        ];

        $output = $plugin->normalize_editor_urls_in_post_data($input);

        $this->assertSame(
            '<a href="/app/uploads/file.pdf">Doc</a><script src="/wp/wp-includes/js/script.js"></script>',
            $output['post_content'],
        );
    }

    public function test_normalize_editor_urls_handles_non_array_or_empty_content(): void
    {
        $plugin = new Plugin();

        $this->assertNull($plugin->normalize_editor_urls_in_post_data(null));
        $this->assertSame('string-data', $plugin->normalize_editor_urls_in_post_data('string-data'));

        $emptyArray = ['post_title' => 'Sample'];
        $this->assertSame($emptyArray, $plugin->normalize_editor_urls_in_post_data($emptyArray));

        $emptyContent = ['post_content' => ''];
        $this->assertSame($emptyContent, $plugin->normalize_editor_urls_in_post_data($emptyContent));

        $nonStringContent = ['post_content' => 12345];
        $this->assertSame($nonStringContent, $plugin->normalize_editor_urls_in_post_data($nonStringContent));
    }

    public function test_normalize_editor_urls_preserves_external_urls_and_non_app_wp_paths(): void
    {
        Functions\when('home_url')->justReturn('https://editor-bs4ppedptq-de.a.run.app');
        Functions\when('wp_parse_url')->justReturn('editor-bs4ppedptq-de.a.run.app');

        $plugin = new Plugin();
        $input = [
            'post_content' => '<a href="https://example.com/app/test.png">External</a><a href="https://editor-bs4ppedptq-de.a.run.app/some-page">Internal Page</a>',
        ];

        $output = $plugin->normalize_editor_urls_in_post_data($input);

        // Non-app/wp paths and different hosts must remain untouched
        $this->assertSame($input['post_content'], $output['post_content']);
    }

    public function test_strip_current_origin_returns_unmodified_when_home_url_empty_or_has_no_host(): void
    {
        Functions\when('home_url')->justReturn('');
        $plugin = new Plugin();
        $content = '<img src="https://editor.run.app/app/img.png">';
        $this->assertSame($content, $plugin->strip_current_origin_from_local_paths($content));

        Functions\when('home_url')->justReturn('invalid-url-without-scheme');
        Functions\when('wp_parse_url')->justReturn(null);
        $this->assertSame($content, $plugin->strip_current_origin_from_local_paths($content));
    }

    public function test_strip_current_origin_handles_mixed_case_host_and_scheme(): void
    {
        Functions\when('home_url')->justReturn('https://EDITOR-HOST.run.app');
        Functions\when('wp_parse_url')->justReturn('EDITOR-HOST.run.app');

        $plugin = new Plugin();
        $input = '<img src="HTTPS://editor-host.run.app/app/uploads/pic.jpg">';
        $output = $plugin->strip_current_origin_from_local_paths($input);

        $this->assertSame('<img src="/app/uploads/pic.jpg">', $output);
    }

    public function test_strip_current_origin_preserves_query_string_and_anchor(): void
    {
        Functions\when('home_url')->justReturn('https://editor-host.run.app');
        Functions\when('wp_parse_url')->justReturn('editor-host.run.app');

        $plugin = new Plugin();
        $input = '<a href="https://editor-host.run.app/app/docs/file.pdf?ver=2.0#page=3">Download</a>';
        $output = $plugin->strip_current_origin_from_local_paths($input);

        $this->assertSame('<a href="/app/docs/file.pdf?ver=2.0#page=3">Download</a>', $output);
    }

    public function test_strip_current_origin_handles_multiple_asset_tags_in_single_content(): void
    {
        Functions\when('home_url')->justReturn('https://editor.run.app');
        Functions\when('wp_parse_url')->justReturn('editor.run.app');

        $plugin = new Plugin();
        $input = implode('', [
            '<link rel="stylesheet" href="https://editor.run.app/app/themes/theme/style.css">',
            '<script src="https://editor.run.app/wp/wp-includes/js/jquery.js"></script>',
            '<img src="https://editor.run.app/app/uploads/img1.png">',
            '<img src="https://editor.run.app/app/uploads/img2.jpg">',
        ]);

        $output = $plugin->strip_current_origin_from_local_paths($input);

        $expected = implode('', [
            '<link rel="stylesheet" href="/app/themes/theme/style.css">',
            '<script src="/wp/wp-includes/js/jquery.js"></script>',
            '<img src="/app/uploads/img1.png">',
            '<img src="/app/uploads/img2.jpg">',
        ]);

        $this->assertSame($expected, $output);
    }
}
