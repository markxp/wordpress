<?php

declare(strict_types=1);

namespace IapAuthShield\TestsSapi;

use PHPUnit\Framework\TestCase;

class SapiTest extends TestCase
{
    private function request(string $path): array
    {
        $ctx = stream_context_create(['http' => ['ignore_errors' => true]]);
        $body = file_get_contents(getenv('SAPI_BASE_URL') . $path, false, $ctx);
        $headers = $http_response_header ?? [];
        preg_match('/HTTP\/\S+\s+(\d{3})/', $headers[0] ?? '', $m);
        return [(int) ($m[1] ?? 0), implode("\n", $headers), (string) $body];
    }

    public function test_blocks_unauthenticated_wp_admin_and_rest_over_nginx_and_php_fpm(): void
    {
        [$adminStatus, $adminHeaders] = $this->request('/wp-admin/');
        $this->assertSame(401, $adminStatus);
        $this->assertStringContainsString('X-PHP-SAPI: fpm-fcgi', $adminHeaders);

        [$restStatus, , $restBody] = $this->request('/?scenario=rest_private');
        $this->assertSame(401, $restStatus);
        $this->assertSame('rest_forbidden', $restBody);
    }
}
