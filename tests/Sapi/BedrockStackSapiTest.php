<?php

declare(strict_types=1);

namespace Tests\Sapi;

use PHPUnit\Framework\TestCase;

class BedrockStackSapiTest extends TestCase
{
    private function request(string $path): array
    {
        $baseUrl = rtrim((string) (getenv('SAPI_STACK_BASE_URL') ?: 'http://127.0.0.2:18080'), '/');
        $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
        $body = file_get_contents($baseUrl . $path, false, $ctx);
        $headers = $http_response_header ?? [];
        preg_match('/HTTP\/\S+\s+(\d{3})/', $headers[0] ?? '', $m);

        return [(int) ($m[1] ?? 0), implode("\n", $headers), (string) $body];
    }

    public function test_bedrock_homepage_renders_with_custom_plugins_active(): void
    {
        [$status, $headers, $body] = $this->request('/');

        $this->assertSame(200, $status);
        $this->assertStringNotContainsString('X-Pingback:', $headers);
        $this->assertStringContainsString('<meta name="description"', $body);
    }

    public function test_security_hardening_blocks_anonymous_rest_users_endpoint(): void
    {
        [$status] = $this->request('/wp-json/wp/v2/users');

        $this->assertContains($status, [401, 403]);
    }
}
