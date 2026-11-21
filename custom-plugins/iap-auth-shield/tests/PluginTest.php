<?php

declare(strict_types=1);

namespace IapAuthShield\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Firebase\JWT\JWT;
use IapAuthShield\Plugin;
use Mockery;
use Tests\TestCase;
use WP_Error;

class TestableIAPPlugin extends Plugin
{
    public array $mockPayload = [];
    public int $verifyJwtCallCount = 0;
    public bool $useRealVerifyJwt = false;
    public bool $terminated = false;
    public int $terminateCount = 0;

    protected function verify_jwt($jwt)
    {
        $this->verifyJwtCallCount++;
        if ($this->useRealVerifyJwt) {
            return parent::verify_jwt($jwt);
        }

        return $this->mockPayload;
    }

    protected function terminate_request(): void
    {
        $this->terminated = true;
        $this->terminateCount++;
    }

    public function callGetGoogleIapKeys(bool $forceRefresh = false)
    {
        return $this->get_google_iap_keys($forceRefresh);
    }

    public function callVerifyJwt(string $jwt): array
    {
        return parent::verify_jwt($jwt);
    }
}

class PluginTest extends TestCase
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

        Actions\expectAdded('init')->once()->with(Mockery::type('array'), 0);
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

    public function test_register_admin_menu_calls_add_submenu_page_with_hidden_parent(): void
    {
        Functions\expect('add_submenu_page')
            ->once()
            ->with(
                'options.php',
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
        Functions\when('is_user_logged_in')->justReturn(false);
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

    public function test_handle_iap_authentication_fast_paths_when_already_logged_in_as_matching_user(): void
    {
        $_ENV['IAP_AUTH_ENABLED'] = 'true';
        $_ENV['IAP_AUDIENCE'] = 'test-audience';

        Functions\when('is_admin')->justReturn(true);
        Functions\when('is_ssl')->justReturn(true);
        Functions\when('is_user_logged_in')->justReturn(true);
        Functions\when('wp_get_current_user')->justReturn((object) ['ID' => 42, 'user_email' => 'admin@example.com']);
        Functions\when('wp_validate_auth_cookie')->justReturn(42);
        $_SERVER['HTTP_X_GOOG_IAP_JWT_ASSERTION'] = 'mock.jwt.token';
        $_SERVER['HTTP_X_GOOG_AUTHENTICATED_USER_EMAIL'] = 'accounts.google.com:admin@example.com';

        Functions\expect('get_user_by')->never();
        Functions\expect('wp_set_auth_cookie')->never();

        $testPlugin = new TestableIAPPlugin();
        $testPlugin->handle_iap_authentication();

        $this->assertSame(0, $testPlugin->verifyJwtCallCount);

        unset(
            $_ENV['IAP_AUTH_ENABLED'],
            $_ENV['IAP_AUDIENCE'],
            $_SERVER['HTTP_X_GOOG_IAP_JWT_ASSERTION'],
            $_SERVER['HTTP_X_GOOG_AUTHENTICATED_USER_EMAIL'],
        );
    }

    public function test_handle_iap_authentication_does_not_skip_jwt_when_email_header_missing(): void
    {
        $_ENV['IAP_AUTH_ENABLED'] = 'true';
        $_ENV['IAP_AUDIENCE'] = 'test-audience';

        Functions\when('is_admin')->justReturn(true);
        Functions\when('is_ssl')->justReturn(true);
        Functions\when('is_user_logged_in')->justReturn(true);
        Functions\when('wp_get_current_user')->justReturn((object) ['ID' => 42, 'user_email' => 'admin@example.com']);
        Functions\when('wp_validate_auth_cookie')->justReturn(42);
        $_SERVER['HTTP_X_GOOG_IAP_JWT_ASSERTION'] = 'mock.jwt.token';
        unset($_SERVER['HTTP_X_GOOG_AUTHENTICATED_USER_EMAIL']);

        Functions\when('get_user_by')->justReturn((object) ['ID' => 42, 'user_email' => 'admin@example.com']);
        Functions\expect('wp_set_auth_cookie')->never();

        $testPlugin = new TestableIAPPlugin();
        $testPlugin->mockPayload = ['email' => 'admin@example.com'];
        $testPlugin->handle_iap_authentication();

        $this->assertSame(1, $testPlugin->verifyJwtCallCount);

        unset(
            $_ENV['IAP_AUTH_ENABLED'],
            $_ENV['IAP_AUDIENCE'],
            $_SERVER['HTTP_X_GOOG_IAP_JWT_ASSERTION'],
        );
    }

    public function test_handle_iap_authentication_escapes_email_in_wp_die_when_user_not_found(): void
    {
        $_ENV['IAP_AUTH_ENABLED'] = 'true';
        $_ENV['IAP_AUDIENCE'] = 'test-audience';

        Functions\when('is_admin')->justReturn(true);
        Functions\when('is_user_logged_in')->justReturn(false);
        Functions\when('esc_html')->alias(static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
        $_SERVER['HTTP_X_GOOG_IAP_JWT_ASSERTION'] = 'mock.jwt.token';

        Functions\when('get_user_by')->justReturn(false);
        Functions\expect('wp_die')
            ->once()
            ->with(
                Mockery::on(static fn (string $msg): bool => str_contains($msg, '&lt;script&gt;alert(1)&lt;/script&gt;@example.com') && !str_contains($msg, '<script>')),
                'Unauthorized',
                ['response' => 403],
            );

        $testPlugin = new TestableIAPPlugin();
        $testPlugin->mockPayload = ['email' => '<script>alert(1)</script>@example.com'];
        $testPlugin->handle_iap_authentication();

        $this->assertTrue(true);

        unset(
            $_ENV['IAP_AUTH_ENABLED'],
            $_ENV['IAP_AUDIENCE'],
            $_SERVER['HTTP_X_GOOG_IAP_JWT_ASSERTION'],
        );
    }

    public function test_handle_iap_authentication_redirects_from_wp_login_even_when_already_logged_in_without_header_email(): void
    {
        $_ENV['IAP_AUTH_ENABLED'] = 'true';
        $_ENV['IAP_AUDIENCE'] = 'test-audience';
        $GLOBALS['pagenow'] = 'wp-login.php';

        Functions\when('is_admin')->justReturn(false);
        Functions\when('is_ssl')->justReturn(true);
        Functions\when('is_user_logged_in')->justReturn(true);
        Functions\when('wp_get_current_user')->justReturn((object) ['ID' => 42, 'user_email' => 'admin@example.com']);
        Functions\when('wp_validate_auth_cookie')->justReturn(42);
        Functions\when('admin_url')->justReturn('https://example.com/wp/wp-admin/');
        $_SERVER['HTTP_X_GOOG_IAP_JWT_ASSERTION'] = 'mock.jwt.token';
        unset($_SERVER['HTTP_X_GOOG_AUTHENTICATED_USER_EMAIL']);

        Functions\when('get_user_by')->justReturn((object) ['ID' => 42, 'user_email' => 'admin@example.com']);
        Functions\expect('wp_set_auth_cookie')->never();
        Functions\expect('wp_safe_redirect')
            ->once()
            ->with('https://example.com/wp/wp-admin/');

        $testPlugin = new TestableIAPPlugin();
        $testPlugin->mockPayload = ['email' => 'admin@example.com'];

        try {
            $testPlugin->handle_iap_authentication();
            $this->assertTrue($testPlugin->terminated);
        } finally {
            $GLOBALS['pagenow'] = '';
            unset(
                $_ENV['IAP_AUTH_ENABLED'],
                $_ENV['IAP_AUDIENCE'],
                $_SERVER['HTTP_X_GOOG_IAP_JWT_ASSERTION'],
            );
        }
    }

    public function test_establish_wp_session_syncs_cookies_into_superglobal_and_cleans_up_hooks(): void
    {
        if (!defined('SECURE_AUTH_COOKIE')) {
            define('SECURE_AUTH_COOKIE', 'wordpress_sec_test');
        }
        if (!defined('AUTH_COOKIE')) {
            define('AUTH_COOKIE', 'wordpress_test');
        }
        if (!defined('LOGGED_IN_COOKIE')) {
            define('LOGGED_IN_COOKIE', 'wordpress_logged_in_test');
        }

        $_ENV['IAP_AUTH_ENABLED'] = 'true';
        $_ENV['IAP_AUDIENCE'] = 'test-audience';
        $_COOKIE = [];

        Functions\when('is_admin')->justReturn(true);
        Functions\when('is_user_logged_in')->justReturn(false);
        $_SERVER['HTTP_X_GOOG_IAP_JWT_ASSERTION'] = 'mock.jwt.token';
        Functions\when('get_user_by')->justReturn((object) ['ID' => 42, 'user_email' => 'admin@example.com']);

        $capturedAuthCb = null;
        $capturedLoggedInCb = null;
        Actions\expectAdded('set_auth_cookie')
            ->once()
            ->whenHappen(static function (callable $cb) use (&$capturedAuthCb): void {
                $capturedAuthCb = $cb;
            });
        Actions\expectAdded('set_logged_in_cookie')
            ->once()
            ->whenHappen(static function (callable $cb) use (&$capturedLoggedInCb): void {
                $capturedLoggedInCb = $cb;
            });

        Functions\expect('wp_set_current_user')->once()->with(42);
        Functions\expect('wp_set_auth_cookie')
            ->once()
            ->with(42)
            ->andReturnUsing(static function () use (&$capturedAuthCb, &$capturedLoggedInCb): void {
                if (is_callable($capturedAuthCb)) {
                    $capturedAuthCb('minted-sec-cookie', 0, time() + 3600, 42, 'secure_auth');
                    $capturedAuthCb('minted-auth-cookie', 0, time() + 3600, 42, 'auth');
                }
                if (is_callable($capturedLoggedInCb)) {
                    $capturedLoggedInCb('minted-logged-in-cookie');
                }
            });

        $testPlugin = new TestableIAPPlugin();
        $testPlugin->mockPayload = ['email' => 'admin@example.com'];

        try {
            $testPlugin->handle_iap_authentication();

            $this->assertSame('minted-sec-cookie', $_COOKIE[SECURE_AUTH_COOKIE] ?? null);
            $this->assertSame('minted-auth-cookie', $_COOKIE[AUTH_COOKIE] ?? null);
            $this->assertSame('minted-logged-in-cookie', $_COOKIE[LOGGED_IN_COOKIE] ?? null);
            $this->assertFalse(has_action('set_auth_cookie'));
            $this->assertFalse(has_action('set_logged_in_cookie'));
        } finally {
            $_COOKIE = [];
            unset(
                $_ENV['IAP_AUTH_ENABLED'],
                $_ENV['IAP_AUDIENCE'],
                $_SERVER['HTTP_X_GOOG_IAP_JWT_ASSERTION'],
            );
        }
    }

    public function test_handle_iap_authentication_reissues_cookies_when_admin_auth_cookie_missing_despite_logged_in_cookie(): void
    {
        $_ENV['IAP_AUTH_ENABLED'] = 'true';
        $_ENV['IAP_AUDIENCE'] = 'test-audience';
        $GLOBALS['pagenow'] = 'wp-login.php';

        Functions\when('is_admin')->justReturn(false);
        Functions\when('is_ssl')->justReturn(true);
        Functions\when('is_user_logged_in')->justReturn(true);
        Functions\when('wp_get_current_user')->justReturn((object) ['ID' => 42, 'user_email' => 'admin@example.com']);
        Functions\when('wp_validate_auth_cookie')->justReturn(0);
        Functions\when('admin_url')->justReturn('https://example.com/wp/wp-admin/');
        Functions\when('get_user_by')->justReturn((object) ['ID' => 42, 'user_email' => 'admin@example.com']);

        $_SERVER['HTTP_X_GOOG_IAP_JWT_ASSERTION'] = 'mock.jwt.token';
        $_SERVER['HTTP_X_GOOG_AUTHENTICATED_USER_EMAIL'] = 'accounts.google.com:admin@example.com';

        Functions\expect('wp_set_current_user')->once()->with(42);
        Functions\expect('wp_set_auth_cookie')->once()->with(42);
        Functions\expect('wp_safe_redirect')->once()->with('https://example.com/wp/wp-admin/');

        $testPlugin = new TestableIAPPlugin();
        $testPlugin->mockPayload = ['email' => 'admin@example.com'];

        try {
            $testPlugin->handle_iap_authentication();

            $this->assertSame(1, $testPlugin->verifyJwtCallCount);
            $this->assertTrue($testPlugin->terminated);
        } finally {
            $GLOBALS['pagenow'] = '';
            unset(
                $_ENV['IAP_AUTH_ENABLED'],
                $_ENV['IAP_AUDIENCE'],
                $_SERVER['HTTP_X_GOOG_IAP_JWT_ASSERTION'],
                $_SERVER['HTTP_X_GOOG_AUTHENTICATED_USER_EMAIL'],
            );
        }
    }

    public function test_handle_iap_authentication_wp_login_honors_validated_redirect_to(): void
    {
        $_ENV['IAP_AUTH_ENABLED'] = 'true';
        $_ENV['IAP_AUDIENCE'] = 'test-audience';
        $GLOBALS['pagenow'] = 'wp-login.php';
        $_REQUEST['redirect_to'] = 'https://example.com/wp/wp-admin/edit.php?post_type=page';

        Functions\when('is_admin')->justReturn(false);
        Functions\when('is_ssl')->justReturn(true);
        Functions\when('is_user_logged_in')->justReturn(true);
        Functions\when('wp_get_current_user')->justReturn((object) ['ID' => 42, 'user_email' => 'admin@example.com']);
        Functions\when('wp_validate_auth_cookie')->justReturn(42);
        Functions\when('admin_url')->justReturn('https://example.com/wp/wp-admin/');
        Functions\expect('wp_validate_redirect')
            ->once()
            ->with('https://example.com/wp/wp-admin/edit.php?post_type=page', 'https://example.com/wp/wp-admin/')
            ->andReturn('https://example.com/wp/wp-admin/edit.php?post_type=page');
        Functions\expect('wp_safe_redirect')
            ->once()
            ->with('https://example.com/wp/wp-admin/edit.php?post_type=page');

        $_SERVER['HTTP_X_GOOG_IAP_JWT_ASSERTION'] = 'mock.jwt.token';
        $_SERVER['HTTP_X_GOOG_AUTHENTICATED_USER_EMAIL'] = 'accounts.google.com:admin@example.com';

        $testPlugin = new TestableIAPPlugin();

        try {
            $testPlugin->handle_iap_authentication();
            $this->assertTrue($testPlugin->terminated);
        } finally {
            $GLOBALS['pagenow'] = '';
            unset(
                $_ENV['IAP_AUTH_ENABLED'],
                $_ENV['IAP_AUDIENCE'],
                $_REQUEST['redirect_to'],
                $_SERVER['HTTP_X_GOOG_IAP_JWT_ASSERTION'],
                $_SERVER['HTTP_X_GOOG_AUTHENTICATED_USER_EMAIL'],
            );
        }
    }

    public function test_handle_iap_authentication_wp_login_interim_login_outputs_success_html_without_redirect(): void
    {
        $_ENV['IAP_AUTH_ENABLED'] = 'true';
        $_ENV['IAP_AUDIENCE'] = 'test-audience';
        $GLOBALS['pagenow'] = 'wp-login.php';
        $_REQUEST['interim-login'] = '1';

        Functions\when('is_admin')->justReturn(false);
        Functions\when('is_ssl')->justReturn(true);
        Functions\when('is_user_logged_in')->justReturn(true);
        Functions\when('wp_get_current_user')->justReturn((object) ['ID' => 42, 'user_email' => 'admin@example.com']);
        Functions\when('wp_validate_auth_cookie')->justReturn(42);
        Functions\expect('wp_safe_redirect')->never();

        $_SERVER['HTTP_X_GOOG_IAP_JWT_ASSERTION'] = 'mock.jwt.token';
        $_SERVER['HTTP_X_GOOG_AUTHENTICATED_USER_EMAIL'] = 'accounts.google.com:admin@example.com';

        $this->expectOutputString('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body class="login interim-login-success"></body></html>');

        $testPlugin = new TestableIAPPlugin();

        try {
            $testPlugin->handle_iap_authentication();
            $this->assertTrue($testPlugin->terminated);
        } finally {
            $GLOBALS['pagenow'] = '';
            unset(
                $_ENV['IAP_AUTH_ENABLED'],
                $_ENV['IAP_AUDIENCE'],
                $_REQUEST['interim-login'],
                $_SERVER['HTTP_X_GOOG_IAP_JWT_ASSERTION'],
                $_SERVER['HTTP_X_GOOG_AUTHENTICATED_USER_EMAIL'],
            );
        }
    }

    public function test_handle_iap_authentication_wp_login_does_not_redirect_non_login_actions(): void
    {
        $_ENV['IAP_AUTH_ENABLED'] = 'true';
        $_ENV['IAP_AUDIENCE'] = 'test-audience';
        $GLOBALS['pagenow'] = 'wp-login.php';

        Functions\when('is_admin')->justReturn(false);
        Functions\when('is_ssl')->justReturn(true);
        Functions\when('is_user_logged_in')->justReturn(true);
        Functions\when('wp_get_current_user')->justReturn((object) ['ID' => 42, 'user_email' => 'admin@example.com']);
        Functions\when('wp_validate_auth_cookie')->justReturn(42);
        Functions\expect('wp_safe_redirect')->never();

        $_SERVER['HTTP_X_GOOG_IAP_JWT_ASSERTION'] = 'mock.jwt.token';
        $_SERVER['HTTP_X_GOOG_AUTHENTICATED_USER_EMAIL'] = 'accounts.google.com:admin@example.com';

        try {
            foreach (['postpass', 'confirm_admin_email'] as $action) {
                $_REQUEST['action'] = $action;
                $testPlugin = new TestableIAPPlugin();
                $testPlugin->handle_iap_authentication();
                $this->assertFalse($testPlugin->terminated);
            }
        } finally {
            $GLOBALS['pagenow'] = '';
            unset(
                $_ENV['IAP_AUTH_ENABLED'],
                $_ENV['IAP_AUDIENCE'],
                $_REQUEST['action'],
                $_SERVER['HTTP_X_GOOG_IAP_JWT_ASSERTION'],
                $_SERVER['HTTP_X_GOOG_AUTHENTICATED_USER_EMAIL'],
            );
        }
    }

    public function test_handle_iap_authentication_maps_session_on_frontend_when_iap_jwt_present(): void
    {
        $_ENV['IAP_AUTH_ENABLED'] = 'true';
        $_ENV['IAP_AUDIENCE'] = 'test-audience';
        $GLOBALS['pagenow'] = 'index.php';

        Functions\when('is_admin')->justReturn(false);
        Functions\when('is_user_logged_in')->justReturn(false);
        Functions\when('get_user_by')->justReturn((object) ['ID' => 42, 'user_email' => 'editor@example.com']);
        $_SERVER['HTTP_X_GOOG_IAP_JWT_ASSERTION'] = 'mock.jwt.token';

        Functions\expect('wp_set_current_user')->once()->with(42);
        Functions\expect('wp_set_auth_cookie')->once()->with(42);

        $testPlugin = new TestableIAPPlugin();
        $testPlugin->mockPayload = ['email' => 'editor@example.com'];

        try {
            $testPlugin->handle_iap_authentication();
            $this->assertSame(1, $testPlugin->verifyJwtCallCount);
        } finally {
            $GLOBALS['pagenow'] = '';
            unset(
                $_ENV['IAP_AUTH_ENABLED'],
                $_ENV['IAP_AUDIENCE'],
                $_SERVER['HTTP_X_GOOG_IAP_JWT_ASSERTION'],
            );
        }
    }

    public function test_handle_iap_authentication_returns_silently_on_frontend_when_iap_email_has_no_wp_user(): void
    {
        $_ENV['IAP_AUTH_ENABLED'] = 'true';
        $_ENV['IAP_AUDIENCE'] = 'test-audience';
        $GLOBALS['pagenow'] = 'index.php';

        Functions\when('is_admin')->justReturn(false);
        Functions\when('is_user_logged_in')->justReturn(false);
        Functions\when('get_user_by')->justReturn(false);
        $_SERVER['HTTP_X_GOOG_IAP_JWT_ASSERTION'] = 'mock.jwt.token';

        Functions\expect('wp_die')->never();

        $testPlugin = new TestableIAPPlugin();
        $testPlugin->mockPayload = ['email' => 'viewer@example.com'];

        try {
            $testPlugin->handle_iap_authentication();
            $this->assertSame(1, $testPlugin->verifyJwtCallCount);
        } finally {
            $GLOBALS['pagenow'] = '';
            unset(
                $_ENV['IAP_AUTH_ENABLED'],
                $_ENV['IAP_AUDIENCE'],
                $_SERVER['HTTP_X_GOOG_IAP_JWT_ASSERTION'],
            );
        }
    }

    public function test_get_google_iap_keys_passes_3_second_timeout_to_wp_remote_get(): void
    {
        Functions\when('get_transient')->justReturn(false);
        Functions\expect('wp_remote_get')
            ->once()
            ->with('https://www.gstatic.com/iap/verify/public_key', ['timeout' => 3])
            ->andReturn(['body' => '{"kid1":"pem1"}']);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{"kid1":"pem1"}');
        Functions\expect('set_transient')->once();

        $testPlugin = new TestableIAPPlugin();
        $keys = $testPlugin->callGetGoogleIapKeys();

        $this->assertSame(['kid1' => 'pem1'], $keys);
    }

    public function test_get_google_iap_keys_rejects_non_200_status_without_setting_transient(): void
    {
        Functions\when('get_transient')->justReturn(false);
        Functions\when('wp_remote_get')->justReturn(['response' => ['code' => 503]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(503);
        Functions\expect('set_transient')->never();

        $testPlugin = new TestableIAPPlugin();

        try {
            $testPlugin->callGetGoogleIapKeys();
            $this->fail('Expected exception when Google IAP keys endpoint returns HTTP 503.');
        } catch (\Exception $e) {
            $this->assertSame('Unexpected HTTP status fetching Google public keys: 503', $e->getMessage());
        }
    }

    public function test_get_google_iap_keys_rejects_malformed_json_payloads_without_setting_transient(): void
    {
        Functions\when('get_transient')->justReturn(false);
        Functions\when('wp_remote_get')->justReturn(['body' => '']);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\expect('set_transient')->never();

        $testPlugin = new TestableIAPPlugin();

        foreach (['{"error":{"code":503}}', '[]', '<html>Bad Gateway</html>', '{"kid1":"   "}'] as $badBody) {
            Functions\when('wp_remote_retrieve_body')->justReturn($badBody);
            try {
                $testPlugin->callGetGoogleIapKeys();
                $this->fail('Expected exception for malformed key payload: ' . $badBody);
            } catch (\Exception $e) {
                $this->assertSame('Invalid Google IAP public keys payload.', $e->getMessage());
            }
        }
    }

    public function test_verify_jwt_retries_key_fetch_once_on_unknown_kid(): void
    {
        $_ENV['IAP_AUTH_ENABLED'] = 'true';
        $_ENV['IAP_AUDIENCE'] = 'expected-audience';

        $oldKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $newKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertNotFalse($oldKey);
        $this->assertNotFalse($newKey);

        $newPrivPem = '';
        openssl_pkey_export($newKey, $newPrivPem);
        $oldPubPem = openssl_pkey_get_details($oldKey)['key'];
        $newPubPem = openssl_pkey_get_details($newKey)['key'];

        $now = time();
        $rotatedJwt = JWT::encode([
            'iss' => 'https://cloud.google.com/iap',
            'aud' => 'expected-audience',
            'email' => 'rotated@example.com',
            'iat' => $now,
            'exp' => $now + 300,
        ], $newPrivPem, 'ES256', 'kid-rotated');

        // Cached transient only has kid-old; force_refresh fetches kid-rotated via wp_remote_get.
        Functions\expect('get_transient')
            ->once()
            ->with('gcp_iap_public_keys')
            ->andReturn(['kid-old' => $oldPubPem]);
        Functions\expect('wp_remote_get')
            ->once()
            ->with('https://www.gstatic.com/iap/verify/public_key', ['timeout' => 3])
            ->andReturn(['body' => json_encode(['kid-rotated' => $newPubPem])]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn((string) json_encode(['kid-rotated' => $newPubPem]));
        Functions\expect('set_transient')->once();

        $testPlugin = new TestableIAPPlugin();

        try {
            $decoded = $testPlugin->callVerifyJwt($rotatedJwt);
            $this->assertSame('rotated@example.com', $decoded['email']);
        } finally {
            unset($_ENV['IAP_AUTH_ENABLED'], $_ENV['IAP_AUDIENCE']);
        }
    }

    public function test_verify_jwt_validates_missing_or_non_string_claims_without_warnings(): void
    {
        $_ENV['IAP_AUTH_ENABLED'] = 'true';
        $_ENV['IAP_AUDIENCE'] = 'expected-audience';

        $ecKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertNotFalse($ecKey);

        $privPem = '';
        openssl_pkey_export($ecKey, $privPem);
        $pubPem = openssl_pkey_get_details($ecKey)['key'];

        Functions\when('get_transient')->justReturn(['kid-1' => $pubPem]);

        $now = time();
        $testPlugin = new TestableIAPPlugin();

        try {
            $missingIssJwt = JWT::encode([
                'aud' => 'expected-audience',
                'email' => 'user@example.com',
                'iat' => $now,
                'exp' => $now + 300,
            ], $privPem, 'ES256', 'kid-1');

            try {
                $testPlugin->callVerifyJwt($missingIssJwt);
                $this->fail('Expected exception when iss claim is missing.');
            } catch (\Exception $e) {
                $this->assertStringContainsString('Invalid issuer ->', $e->getMessage());
            }

            $invalidAudJwt = JWT::encode([
                'iss' => 'https://cloud.google.com/iap',
                'aud' => ['unexpected-array-aud'],
                'email' => 'user@example.com',
                'iat' => $now,
                'exp' => $now + 300,
            ], $privPem, 'ES256', 'kid-1');

            try {
                $testPlugin->callVerifyJwt($invalidAudJwt);
                $this->fail('Expected exception when aud claim is non-string.');
            } catch (\Exception $e) {
                $this->assertStringContainsString('Invalid audience.', $e->getMessage());
            }
        } finally {
            unset($_ENV['IAP_AUTH_ENABLED'], $_ENV['IAP_AUDIENCE']);
        }
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

    public function test_api_firewall_allows_comments_get_and_blocks_anonymous_post(): void
    {
        Functions\when('is_user_logged_in')->justReturn(false);

        $getRequest = Mockery::mock();
        $getRequest->shouldReceive('get_route')->andReturn('/wp/v2/comments');
        $getRequest->shouldReceive('get_method')->andReturn('GET');

        $postRequest = Mockery::mock();
        $postRequest->shouldReceive('get_route')->andReturn('/wp/v2/comments');
        $postRequest->shouldReceive('get_method')->andReturn('POST');

        $plugin = new Plugin();

        $this->assertNull($plugin->api_firewall(null, null, $getRequest));

        $postResult = $plugin->api_firewall(null, null, $postRequest);
        $this->assertInstanceOf(WP_Error::class, $postResult);
        $this->assertSame('rest_forbidden', $postResult->get_error_code());
        $this->assertSame(['status' => 401], $postResult->get_error_data());
    }

    public function test_api_firewall_allows_removing_comments_via_whitelist_filter(): void
    {
        Functions\when('is_user_logged_in')->justReturn(false);
        Filters\expectApplied('iap_auth_shield_api_whitelist')
            ->once()
            ->andReturn(['/wp/v2/posts']);

        $getRequest = Mockery::mock();
        $getRequest->shouldReceive('get_route')->andReturn('/wp/v2/comments');
        $getRequest->shouldReceive('get_method')->andReturn('GET');

        $plugin = new Plugin();
        $result = $plugin->api_firewall(null, null, $getRequest);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertSame('rest_forbidden', $result->get_error_code());
        $this->assertSame(['status' => 401], $result->get_error_data());
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

    public function test_normalize_editor_urls_handles_wp_slashed_json_block_attributes(): void
    {
        Functions\when('home_url')->justReturn('https://editor-bs4ppedptq-de.a.run.app:8443/');
        Functions\when('wp_parse_url')->alias(static function (string $url, int $component = -1) {
            return parse_url($url, $component);
        });

        $plugin = new Plugin();
        // Simulate wp_slash() applied by wp_insert_post() before wp_insert_post_data filter runs
        $slashedContent = '<!-- wp:cover {\"url\":\"https:\\/\\/editor-bs4ppedptq-de.a.run.app:8443\\/app\\/themes\\/twentytwentyfour\\/assets\\/hero.webp\"} -->';
        $output = $plugin->normalize_editor_urls_in_post_data(['post_content' => $slashedContent]);

        $this->assertSame(
            '<!-- wp:cover {\"url\":\"\\/app\\/themes\\/twentytwentyfour\\/assets\\/hero.webp\"} -->',
            $output['post_content'],
        );
    }
}
