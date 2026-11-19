<?php

declare(strict_types=1);

namespace ModernSiteIcons\Tests;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use ModernSiteIcons\Plugin;
use ReflectionMethod;
use Tests\TestCase;

class PluginTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/modern_site_icons_test_' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_file($this->tempDir . '/icon.svg')) {
            unlink($this->tempDir . '/icon.svg');
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    public function test_constructor_registers_filters(): void
    {
        Filters\expectAdded('site_icon_meta_tags')->once();
        Filters\expectAdded('get_site_icon_url')->once();

        new Plugin();
        $this->assertTrue(true);
    }

    public function test_get_web_root_falls_back_to_abspath_when_wp_content_dir_undefined(): void
    {
        $plugin = new Plugin();
        $method = new ReflectionMethod(Plugin::class, 'get_web_root');

        $webRoot = $method->invoke($plugin);

        $this->assertNotSame('', $webRoot);
        $this->assertSame(rtrim((string) ABSPATH, '/\\'), $webRoot);
    }

    public function test_filter_returns_original_meta_tags_when_sentinel_icon_missing_on_disk(): void
    {
        $plugin = new WebRootOverridePlugin($this->tempDir);
        $original = ['<link rel="icon" href="https://storage.googleapis.com/legacy.png" sizes="32x32" />'];

        $result = $plugin->filter_site_icon_meta_tags($original);

        $this->assertSame($original, $result);
    }

    public function test_filter_returns_original_meta_tags_when_web_root_empty(): void
    {
        $plugin = new WebRootOverridePlugin('');
        $original = ['<link rel="icon" href="https://storage.googleapis.com/legacy.png" sizes="32x32" />'];

        $result = $plugin->filter_site_icon_meta_tags($original);

        $this->assertSame($original, $result);
    }

    public function test_filter_handles_non_array_input_safely_when_sentinel_missing(): void
    {
        $plugin = new WebRootOverridePlugin($this->tempDir);

        $this->assertNull($plugin->filter_site_icon_meta_tags(null));
    }

    public function test_filter_emits_modern_tags_when_sentinel_icon_exists_on_disk(): void
    {
        file_put_contents($this->tempDir . '/icon.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        Functions\when('home_url')->alias(static fn (string $path = ''): string => 'https://example.com' . $path);
        Functions\when('esc_url')->returnArg();

        $plugin = new WebRootOverridePlugin($this->tempDir);
        $original = ['<link rel="icon" href="https://storage.googleapis.com/legacy.png" sizes="32x32" />'];

        $expected = [
            '<link rel="icon" type="image/png" href="https://example.com/favicon-96x96.png" sizes="96x96" />',
            '<link rel="icon" type="image/svg+xml" href="https://example.com/icon.svg" />',
            '<link rel="shortcut icon" href="https://example.com/favicon.ico" />',
            '<link rel="apple-touch-icon" sizes="180x180" href="https://example.com/apple-touch-icon.png" />',
            '<link rel="manifest" href="https://example.com/site.webmanifest" />',
        ];

        Filters\expectApplied('modern_site_icons_meta_tags')
            ->once()
            ->with($expected, $original)
            ->andReturn($expected);

        $result = $plugin->filter_site_icon_meta_tags($original);

        $this->assertSame($expected, $result);
    }

    public function test_filter_falls_back_to_default_tags_when_downstream_filter_returns_non_array(): void
    {
        file_put_contents($this->tempDir . '/icon.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        Functions\when('home_url')->alias(static fn (string $path = ''): string => 'https://example.com' . $path);
        Functions\when('esc_url')->returnArg();

        $plugin = new WebRootOverridePlugin($this->tempDir);

        Filters\expectApplied('modern_site_icons_meta_tags')
            ->once()
            ->andReturn(null);

        $result = $plugin->filter_site_icon_meta_tags(null);

        $this->assertIsArray($result);
        $this->assertCount(5, $result);
    }

    public function test_has_static_icons_memoizes_filesystem_check(): void
    {
        file_put_contents($this->tempDir . '/icon.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        Functions\when('home_url')->alias(static fn (string $path = ''): string => 'https://example.com' . $path);
        Functions\when('esc_url')->returnArg();

        $plugin = new CountingPlugin($this->tempDir);
        $plugin->filter_get_site_icon_url('', 512);
        $plugin->filter_site_icon_meta_tags([]);

        $this->assertSame(1, $plugin->assetChecks);
    }

    public function test_get_site_icon_url_preserves_existing_attachment_url(): void
    {
        file_put_contents($this->tempDir . '/icon.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        $plugin = new WebRootOverridePlugin($this->tempDir);
        $existing = 'https://storage.googleapis.com/bucket/site-icon-512.png';

        $this->assertSame($existing, $plugin->filter_get_site_icon_url($existing, 512));
    }

    public function test_get_site_icon_url_returns_empty_when_sentinel_missing(): void
    {
        $plugin = new WebRootOverridePlugin($this->tempDir);

        $this->assertSame('', $plugin->filter_get_site_icon_url('', 512));
    }

    public function test_get_site_icon_url_provides_size_appropriate_static_fallbacks_when_db_icon_unset(): void
    {
        file_put_contents($this->tempDir . '/icon.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');

        Functions\when('home_url')->alias(static fn (string $path = ''): string => 'https://example.com' . $path);

        $plugin = new WebRootOverridePlugin($this->tempDir);

        $this->assertSame('https://example.com/favicon-96x96.png', $plugin->filter_get_site_icon_url('', 32));
        $this->assertSame('https://example.com/favicon-96x96.png', $plugin->filter_get_site_icon_url('', 96));
        $this->assertSame('https://example.com/apple-touch-icon.png', $plugin->filter_get_site_icon_url('', 180));
        $this->assertSame('https://example.com/icon.svg', $plugin->filter_get_site_icon_url('', 512));
    }
}

class WebRootOverridePlugin extends Plugin
{
    public function __construct(private readonly string $webRoot)
    {
        parent::__construct();
    }

    protected function get_web_root(): string
    {
        return $this->webRoot;
    }
}

class CountingPlugin extends WebRootOverridePlugin
{
    public int $assetChecks = 0;

    protected function asset_exists(string $path): bool
    {
        $this->assetChecks++;

        return parent::asset_exists($path);
    }
}
