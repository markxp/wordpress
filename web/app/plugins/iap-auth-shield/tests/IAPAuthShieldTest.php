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
}
