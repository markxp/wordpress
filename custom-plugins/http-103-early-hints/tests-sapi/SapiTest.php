<?php

declare(strict_types=1);

namespace Http103EarlyHints\TestsSapi;

use PHPUnit\Framework\TestCase;

class SapiTest extends TestCase
{
    /**
     * @return array{0: string, 1: string}
     */
    private function fetch(string $query = ''): array
    {
        $baseUrl = getenv('SAPI_BASE_URL');
        $this->assertNotEmpty($baseUrl, 'SAPI_BASE_URL must be set by test-sapi.sh');

        $body = file_get_contents(rtrim($baseUrl, '/') . '/' . $query);
        $this->assertIsString($body);
        $headers = implode("\n", $http_response_header ?? []);

        return [$headers, $body];
    }

    public function test_emits_real_link_preload_headers_over_nginx_and_php_fpm(): void
    {
        [$headers, $body] = $this->fetch();

        $this->assertStringContainsString('X-PHP-SAPI: fpm-fcgi', $headers);
        $this->assertStringContainsString('X-WP-Enqueue-Scripts-Count: 1', $headers);
        $this->assertStringContainsString('X-WP-Footer-Skip-Link-Remaining: 0', $headers);
        $this->assertStringContainsString(
            'X-WP-Head-Trace: '
            . 'wp_enqueue_block_template_skip_link,'
            . 'wp_enqueue_block_style_variation_styles,'
            . 'wp_enqueue_block_custom_css,'
            . 'WP_Duotone::output_block_styles,'
            . 'wp_enqueue_stored_styles,'
            . 'WP_Duotone::output_global_styles,'
            . 'wp_maybe_inline_styles,'
            . 'wp_print_styles',
            $headers,
        );

        $this->assertMatchesRegularExpression('/^Link: .*style\.css\?ver=1\.0>; rel=preload; as=style/im', $headers);
        $this->assertMatchesRegularExpression(
            '/^Link: .*paragraph\/style\.min\.css\?ver=7\.1>; rel=preload; as=style/im',
            $headers,
        );
        $this->assertMatchesRegularExpression('/^Link: .*app\.js\?ver=1\.0>; rel=preload; as=script/im', $headers);

        $this->assertStringContainsString('early-hints-ok', $body);
        $this->assertStringContainsString('<a id="wp-skip-link"', $body);
        $this->assertStringContainsString('id="wp-block-template-skip-link-css"', $body);
        $this->assertStringContainsString('<style id="core-block-supports-inline-css">', $body);
        $this->assertStringContainsString('.wp-container-core-group-is-layout-1{display:flex;gap:1rem;}', $body);
        $this->assertStringContainsString('.wp-duotone-sunrise{filter:url(#wp-duotone-sunrise);}', $body);
        $this->assertStringContainsString('<style id="global-styles-inline-css">', $body);
        $this->assertStringContainsString('--wp--preset--duotone--sunrise', $body);
        $this->assertStringContainsString('<style id="block-style-variation-styles-inline-css">', $body);
        $this->assertStringContainsString('<style id="wp-block-custom-css-inline-css">', $body);
    }

    public function test_skips_inlined_styles_from_link_header_when_inline_limit_is_positive(): void
    {
        [$headers, $body] = $this->fetch('?inline_limit=40000');

        $this->assertStringContainsString('X-WP-Enqueue-Scripts-Count: 1', $headers);
        $this->assertMatchesRegularExpression('/^Link: .*style\.css\?ver=1\.0>; rel=preload; as=style/im', $headers);
        $this->assertMatchesRegularExpression('/^Link: .*app\.js\?ver=1\.0>; rel=preload; as=script/im', $headers);
        $this->assertDoesNotMatchRegularExpression('/^Link: .*paragraph\/style\.min\.css/im', $headers);

        $this->assertStringContainsString('<style id="block-small-style-inline-css">', $body);
        $this->assertStringContainsString('<style id="wp-block-template-skip-link-inline-css">', $body);
        $this->assertStringNotContainsString('id="wp-block-template-skip-link-css"', $body);
        $this->assertStringContainsString('<style id="core-block-supports-inline-css">', $body);
    }

    public function test_respects_skip_link_opt_out_when_unhooked(): void
    {
        [$headers, $body] = $this->fetch('?disable_skip_link=1');

        $this->assertStringContainsString('X-WP-Enqueue-Scripts-Count: 1', $headers);
        $this->assertStringNotContainsString('id="wp-skip-link"', $body);
        $this->assertStringNotContainsString('wp-block-template-skip-link', $body);
        $this->assertStringContainsString('<style id="core-block-supports-inline-css">', $body);
    }
}
