<?php

declare(strict_types=1);

namespace Http103EarlyHints\TestsSapi;

use PHPUnit\Framework\TestCase;

class SapiTest extends TestCase
{
    public function test_emits_real_link_preload_headers_over_nginx_and_php_fpm(): void
    {
        $baseUrl = getenv('SAPI_BASE_URL');
        $this->assertNotEmpty($baseUrl, 'SAPI_BASE_URL must be set by test-sapi.sh');

        $body = file_get_contents($baseUrl . '/');
        $headers = implode("\n", $http_response_header ?? []);

        $this->assertSame('early-hints-ok', $body);
        $this->assertStringContainsString('X-PHP-SAPI: fpm-fcgi', $headers);
        $this->assertMatchesRegularExpression('/^Link: .*rel=preload; as=style/im', $headers);
        $this->assertMatchesRegularExpression('/^Link: .*rel=preload; as=script/im', $headers);
    }
}
