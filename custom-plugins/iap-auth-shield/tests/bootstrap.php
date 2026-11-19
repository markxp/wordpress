<?php

declare(strict_types=1);

$localAutoload = dirname(__DIR__) . '/vendor/autoload.php';
$rootAutoload = dirname(__DIR__, 3) . '/vendor/autoload.php';

if (file_exists($localAutoload)) {
    require_once $localAutoload;
} elseif (file_exists($rootAutoload)) {
    require_once $rootAutoload;
} else {
    throw new RuntimeException('Composer autoload.php not found. Run `composer install`.');
}

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}
if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
if (!defined('WEEK_IN_SECONDS')) {
    define('WEEK_IN_SECONDS', 604800);
}
if (!defined('YEAR_IN_SECONDS')) {
    define('YEAR_IN_SECONDS', 31536000);
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        private string $code;
        private string $message;
        private array $data;

        public function __construct(string $code = '', string $message = '', $data = '')
        {
            $this->code = $code;
            $this->message = $message;
            $this->data = is_array($data) ? $data : ['status' => $data];
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        public function get_error_data(): array
        {
            return $this->data;
        }
    }
}

if (!class_exists('WP_Styles')) {
    class WP_Styles
    {
        public array $queue = [];
        public array $registered = [];
        public array $to_do = [];
        private array $data = [];

        public function all_deps($queue): bool
        {
            $this->to_do = $this->queue;
            return true;
        }

        public function add_data(string $handle, string $key, $value): bool
        {
            $this->data[$handle][$key] = $value;
            return true;
        }

        public function get_data(string $handle, string $key)
        {
            return $this->data[$handle][$key] ?? null;
        }
    }
}

if (!class_exists('WP_Scripts')) {
    class WP_Scripts
    {
        public array $queue = [];
        public array $registered = [];
        public array $to_do = [];
        private array $data = [];

        public function all_deps($queue): bool
        {
            $this->to_do = $this->queue;
            return true;
        }

        public function add_data(string $handle, string $key, $value): bool
        {
            $this->data[$handle][$key] = $value;
            return true;
        }

        public function get_data(string $handle, string $key)
        {
            return $this->data[$handle][$key] ?? null;
        }
    }
}

if (!class_exists('Tests\TestCase')) {
    eval(<<<'PHP'
        namespace Tests;
        use Brain\Monkey;
        use Brain\Monkey\Functions;
        use Mockery;
        use PHPUnit\Framework\TestCase as PHPUnitTestCase;

        abstract class TestCase extends PHPUnitTestCase
        {
            protected function setUp(): void
            {
                parent::setUp();
                Monkey\setUp();

                Functions\when('is_admin')->justReturn(false);
                Functions\when('is_user_logged_in')->justReturn(false);
                Functions\when('__')->returnArg();
                Functions\when('esc_html')->returnArg();
                Functions\when('esc_attr')->returnArg();
                Functions\when('esc_url')->returnArg();
                Functions\when('plugin_basename')->returnArg();

                $GLOBALS['pagenow'] = '';
            }

            protected function tearDown(): void
            {
                Monkey\tearDown();
                Mockery::close();
                parent::tearDown();
            }
        }
PHP
    );
}
