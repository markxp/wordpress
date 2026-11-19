<?php

declare(strict_types=1);

namespace AutoMetaDescription\TestsSapi;

use PHPUnit\Framework\TestCase;

class SapiTest extends TestCase
{
    public function test_renders_meta_description_over_nginx_and_php_fpm(): void
    {
        $body = file_get_contents(getenv('SAPI_BASE_URL') . '/');
        $headers = implode("\n", $http_response_header ?? []);

        $this->assertStringContainsString('X-PHP-SAPI: fpm-fcgi', $headers);
        $this->assertStringContainsString('<meta name="description" content="Cloud-native WordPress site" />', (string) $body);
    }
}
