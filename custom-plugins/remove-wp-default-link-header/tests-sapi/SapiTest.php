<?php

declare(strict_types=1);

namespace RemoveWpDefaultLinkHeader\TestsSapi;

use PHPUnit\Framework\TestCase;

class SapiTest extends TestCase
{
    public function test_strips_pingback_header_and_disables_xmlrpc_over_nginx_and_php_fpm(): void
    {
        $baseUrl = getenv('SAPI_BASE_URL');
        $this->assertNotEmpty($baseUrl);

        $body = file_get_contents($baseUrl . '/');
        $headers = implode("\n", $http_response_header ?? []);
        $data = json_decode((string) $body, true);

        $this->assertStringContainsString('X-PHP-SAPI: fpm-fcgi', $headers);
        $this->assertStringNotContainsString('X-Pingback:', $headers);
        $this->assertStringContainsString('X-Custom: kept', $headers);
        $this->assertFalse($data['xmlrpc_enabled']);
        $this->assertGreaterThan(0, $data['removed_actions_count']);
    }
}
