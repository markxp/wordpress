<?php

declare(strict_types=1);

namespace SecurityHardening\TestsSapi;

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

    public function test_enforces_rest_feed_and_author_404_over_nginx_and_php_fpm(): void
    {
        [$restStatus, $restHeaders, $restBody] = $this->request('/wp-json/wp/v2/users?scenario=rest');
        $this->assertSame(401, $restStatus, "Unexpected body: {$restBody}");
        $this->assertStringContainsString('X-PHP-SAPI: fpm-fcgi', $restHeaders);
        $this->assertSame('rest_cannot_access', $restBody);

        [$feedStatus] = $this->request('/?scenario=feed');
        $this->assertSame(404, $feedStatus);

        [$authorStatus] = $this->request('/?scenario=author');
        $this->assertSame(404, $authorStatus);
    }
}
