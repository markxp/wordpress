<?php

declare(strict_types=1);

namespace ModernSiteIcons\TestsSapi;

use PHPUnit\Framework\TestCase;

class SapiTest extends TestCase
{
    public function test_renders_site_icon_tags_over_nginx_and_php_fpm(): void
    {
        $body = file_get_contents(getenv('SAPI_BASE_URL') . '/');
        $headers = implode("\n", $http_response_header ?? []);

        $this->assertStringContainsString('X-PHP-SAPI: fpm-fcgi', $headers);
        $this->assertStringContainsString('rel="icon"', (string) $body);
        $this->assertStringContainsString('site.webmanifest', (string) $body);
    }
}
