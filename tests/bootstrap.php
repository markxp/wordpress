<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Define WordPress constants used across plugins
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/web/wp/');
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

// WordPress Stub Classes if not loaded from core
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

if (!class_exists('WP_Duotone')) {
    class WP_Duotone
    {
        public static array $calls = [];
        public static $onOutputBlockStyles = null;
        public static $onOutputGlobalStyles = null;

        public static function reset(): void
        {
            self::$calls = [];
            self::$onOutputBlockStyles = null;
            self::$onOutputGlobalStyles = null;
        }

        public static function output_block_styles(): void
        {
            self::$calls[] = 'output_block_styles';
            if (is_callable(self::$onOutputBlockStyles)) {
                (self::$onOutputBlockStyles)();
            }
        }

        public static function output_global_styles(): void
        {
            self::$calls[] = 'output_global_styles';
            if (is_callable(self::$onOutputGlobalStyles)) {
                (self::$onOutputGlobalStyles)();
            }
        }
    }
}

// Load base TestCase
require_once __DIR__ . '/TestCase.php';
