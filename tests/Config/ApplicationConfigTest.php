<?php

declare(strict_types=1);

namespace Tests\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ApplicationConfigTest extends TestCase
{
    /**
     * Execute config/application.php inside an isolated PHP subprocess so global
     * constants and Roots\WPConfig\Config static state never collide across tests.
     *
     * @param array<string, string> $envVars
     * @param array<string, string> $serverVars
     * @param list<string> $inspectConstants
     * @param list<string> $inspectSuperglobalKeys
     * @return array{
     *     constants: array<string, array{defined: bool, value: mixed, type: string|null}>,
     *     env: array<string, bool>,
     *     server: array<string, bool>,
     *     exception: array{class: string, message: string}|null
     * }
     */
    private function evaluateApplicationConfig(
        array $envVars = [],
        array $serverVars = [],
        array $inspectConstants = [],
        array $inspectSuperglobalKeys = [],
        string $variablesOrder = 'GPCS',
        ?string $dotenvContents = null,
        ?string $dotenvLocalContents = null,
        bool $includeBaseEnv = true,
        bool $expectException = false
    ): array {
        $repoRootDir = dirname(__DIR__, 2);
        $this->assertFileDoesNotExist(
            $repoRootDir . '/.env',
            'Repository root must not contain a .env file during isolated config tests.'
        );

        $tempProjectDir = null;
        $targetRootDir = $repoRootDir;

        if ($dotenvContents !== null || $dotenvLocalContents !== null) {
            $tempProjectDir = sys_get_temp_dir() . '/bedrock-config-test-' . bin2hex(random_bytes(8));
            $this->createIsolatedProjectDirectory($repoRootDir, $tempProjectDir, $dotenvContents, $dotenvLocalContents);
            $targetRootDir = $tempProjectDir;
        }

        try {
            $payload = json_encode([
                'autoloadPath' => $repoRootDir . '/vendor/autoload.php',
                'rootDir' => $targetRootDir,
                'serverVars' => $serverVars,
                'inspectConstants' => $inspectConstants,
                'inspectSuperglobalKeys' => $inspectSuperglobalKeys,
                'expectException' => $expectException,
            ], JSON_THROW_ON_ERROR);

            $runner = <<<'PHP'
                $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
                require_once $input['autoloadPath'];

                foreach ($input['serverVars'] as $k => $v) {
                    $_SERVER[$k] = $v;
                }

                $exception = null;
                try {
                    require $input['rootDir'] . '/config/application.php';
                } catch (\Throwable $e) {
                    if (!$input['expectException']) {
                        throw $e;
                    }
                    $exception = [
                        'class' => $e::class,
                        'message' => $e->getMessage(),
                    ];
                }

                $constants = [];
                foreach ($input['inspectConstants'] as $name) {
                    $isDefined = defined($name);
                    $val = $isDefined ? constant($name) : null;
                    $constants[$name] = [
                        'defined' => $isDefined,
                        'value' => $val,
                        'type' => $isDefined ? get_debug_type($val) : null,
                    ];
                }

                $envPresent = [];
                $serverPresent = [];
                foreach ($input['inspectSuperglobalKeys'] as $key) {
                    $envPresent[$key] = array_key_exists($key, $_ENV);
                    $serverPresent[$key] = array_key_exists($key, $_SERVER);
                }

                echo json_encode([
                    'constants' => $constants,
                    'env' => $envPresent,
                    'server' => $serverPresent,
                    'exception' => $exception,
                ], JSON_THROW_ON_ERROR);
                PHP;

            $baseEnv = $includeBaseEnv ? [
                'WP_ENV' => 'production',
                'WP_HOME' => 'https://example.test',
                'DB_NAME' => 'test_db',
                'DB_USER' => 'test_user',
                'DB_PASSWORD' => 'test_pass',
            ] : [];

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = proc_open(
                [
                    PHP_BINARY,
                    '-d',
                    'variables_order=' . $variablesOrder,
                    '-d',
                    'display_errors=0',
                    '-r',
                    $runner,
                ],
                $descriptors,
                $pipes,
                $targetRootDir,
                array_merge($baseEnv, $envVars)
            );

            $this->assertIsResource($process, 'Failed to start isolated PHP subprocess.');

            fwrite($pipes[0], $payload);
            fclose($pipes[0]);

            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);

            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);

            $exitCode = proc_close($process);

            $this->assertSame(
                0,
                $exitCode,
                sprintf("Subprocess failed (exit %d): %s\n%s", $exitCode, $stderr, $stdout)
            );

            /**
             * @var array{
             *     constants: array<string, array{defined: bool, value: mixed, type: string|null}>,
             *     env: array<string, bool>,
             *     server: array<string, bool>,
             *     exception: array{class: string, message: string}|null
             * } $decoded
             */
            $decoded = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);

            return $decoded;
        } finally {
            if ($tempProjectDir !== null) {
                $this->removeDirectory($tempProjectDir);
            }
        }
    }

    private function createIsolatedProjectDirectory(
        string $repoRootDir,
        string $tempProjectDir,
        ?string $dotenvContents,
        ?string $dotenvLocalContents
    ): void {
        mkdir($tempProjectDir . '/config/environments', 0700, true);
        symlink($repoRootDir . '/vendor', $tempProjectDir . '/vendor');

        copy($repoRootDir . '/config/application.php', $tempProjectDir . '/config/application.php');
        foreach (glob($repoRootDir . '/config/environments/*.php') ?: [] as $envFile) {
            copy($envFile, $tempProjectDir . '/config/environments/' . basename($envFile));
        }

        if ($dotenvContents !== null) {
            file_put_contents($tempProjectDir . '/.env', $dotenvContents);
        }

        if ($dotenvLocalContents !== null) {
            file_put_contents($tempProjectDir . '/.env.local', $dotenvLocalContents);
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if (is_link($path) || $item->isFile()) {
                unlink($path);
            } elseif ($item->isDir()) {
                rmdir($path);
            }
        }

        rmdir($directory);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function variablesOrderProvider(): array
    {
        return [
            'production GPCS' => ['GPCS'],
            'development EGPCS' => ['EGPCS'],
        ];
    }

    #[DataProvider('variablesOrderProvider')]
    public function test_maps_wp_stateless_env_vars_across_string_bool_and_int_types(string $variablesOrder): void
    {
        $result = $this->evaluateApplicationConfig(
            envVars: [
                'WP_STATELESS_MEDIA_MODE' => 'stateless',
                'WP_STATELESS_MEDIA_BUCKET' => 'my-bucket',
                'WP_STATELESS_SKIP_ACL_SET' => 'true',
                'WP_STATELESS_MEDIA_DELETE_REMOTE' => 'false',
                'WP_STATELESS_MEDIA_UPLOAD_CHUNK_SIZE' => '1048576',
                'WP_STATELESS_SYNC_HEALTHCHECK_INTERVAL' => '0',
            ],
            inspectConstants: [
                'WP_STATELESS_MEDIA_MODE',
                'WP_STATELESS_MEDIA_BUCKET',
                'WP_STATELESS_SKIP_ACL_SET',
                'WP_STATELESS_MEDIA_DELETE_REMOTE',
                'WP_STATELESS_MEDIA_UPLOAD_CHUNK_SIZE',
                'WP_STATELESS_SYNC_HEALTHCHECK_INTERVAL',
            ],
            variablesOrder: $variablesOrder
        );

        $this->assertSame(
            ['defined' => true, 'value' => 'stateless', 'type' => 'string'],
            $result['constants']['WP_STATELESS_MEDIA_MODE']
        );
        $this->assertSame(
            ['defined' => true, 'value' => 'my-bucket', 'type' => 'string'],
            $result['constants']['WP_STATELESS_MEDIA_BUCKET']
        );
        $this->assertSame(
            ['defined' => true, 'value' => true, 'type' => 'bool'],
            $result['constants']['WP_STATELESS_SKIP_ACL_SET']
        );
        $this->assertSame(
            ['defined' => true, 'value' => false, 'type' => 'bool'],
            $result['constants']['WP_STATELESS_MEDIA_DELETE_REMOTE']
        );
        $this->assertSame(
            ['defined' => true, 'value' => 1048576, 'type' => 'int'],
            $result['constants']['WP_STATELESS_MEDIA_UPLOAD_CHUNK_SIZE']
        );
        $this->assertSame(
            ['defined' => true, 'value' => 0, 'type' => 'int'],
            $result['constants']['WP_STATELESS_SYNC_HEALTHCHECK_INTERVAL']
        );
    }

    #[DataProvider('variablesOrderProvider')]
    public function test_container_env_overrides_dotenv_under_gpcs_and_egpcs_and_scrubs_superglobals(
        string $variablesOrder
    ): void {
        $dotenv = implode("\n", [
            'WP_ENV=production',
            'WP_HOME=https://dotenv.example.com',
            'DB_NAME=dotenv_db',
            'DB_USER=dotenv_user',
            'DB_PASSWORD=dotenv_secret',
            'WP_STATELESS_MEDIA_MODE=stateless',
            'WP_STATELESS_MEDIA_BUCKET=prod-bucket',
            'IAP_AUTH_ENABLED=false',
        ]) . "\n";

        $result = $this->evaluateApplicationConfig(
            envVars: [
                'WP_STATELESS_MEDIA_MODE' => 'disabled',
                'IAP_AUTH_ENABLED' => 'true',
                'IAP_AUDIENCE' => '/projects/1/global/backendServices/2',
            ],
            inspectConstants: [
                'WP_HOME',
                'DB_NAME',
                'WP_STATELESS_MEDIA_MODE',
                'WP_STATELESS_MEDIA_BUCKET',
                'IAP_AUTH_ENABLED',
                'IAP_AUDIENCE',
            ],
            inspectSuperglobalKeys: [
                'WP_STATELESS_MEDIA_MODE',
                'WP_STATELESS_MEDIA_BUCKET',
                'IAP_AUTH_ENABLED',
                'IAP_AUDIENCE',
                'DB_PASSWORD',
                'WP_HOME',
            ],
            variablesOrder: $variablesOrder,
            dotenvContents: $dotenv,
            includeBaseEnv: false
        );

        $this->assertSame(
            ['defined' => true, 'value' => 'https://dotenv.example.com', 'type' => 'string'],
            $result['constants']['WP_HOME']
        );
        $this->assertSame(
            ['defined' => true, 'value' => 'dotenv_db', 'type' => 'string'],
            $result['constants']['DB_NAME']
        );
        $this->assertSame(
            ['defined' => true, 'value' => 'disabled', 'type' => 'string'],
            $result['constants']['WP_STATELESS_MEDIA_MODE']
        );
        $this->assertSame(
            ['defined' => true, 'value' => 'prod-bucket', 'type' => 'string'],
            $result['constants']['WP_STATELESS_MEDIA_BUCKET']
        );
        $this->assertSame(
            ['defined' => true, 'value' => true, 'type' => 'bool'],
            $result['constants']['IAP_AUTH_ENABLED']
        );
        $this->assertSame(
            ['defined' => true, 'value' => '/projects/1/global/backendServices/2', 'type' => 'string'],
            $result['constants']['IAP_AUDIENCE']
        );

        foreach (
            [
                'WP_STATELESS_MEDIA_MODE',
                'WP_STATELESS_MEDIA_BUCKET',
                'IAP_AUTH_ENABLED',
                'IAP_AUDIENCE',
                'DB_PASSWORD',
                'WP_HOME',
            ] as $scrubbedKey
        ) {
            $this->assertFalse($result['env'][$scrubbedKey], "Expected {$scrubbedKey} to be scrubbed from \$_ENV.");
            $this->assertFalse(
                $result['server'][$scrubbedKey],
                "Expected {$scrubbedKey} to be scrubbed from \$_SERVER."
            );
        }
    }

    public function test_container_database_url_in_gpcs_satisfies_dotenv_required_check(): void
    {
        $dotenv = implode("\n", [
            'WP_ENV=production',
            'WP_HOME=https://dotenv.example.com',
        ]) . "\n";

        $result = $this->evaluateApplicationConfig(
            envVars: [
                'DATABASE_URL' => 'mysql://container_user:container_pass@10.0.0.5:3306/container_db',
            ],
            inspectConstants: [
                'DB_NAME',
                'DB_USER',
                'DB_PASSWORD',
                'DB_HOST',
            ],
            inspectSuperglobalKeys: [
                'DATABASE_URL',
            ],
            variablesOrder: 'GPCS',
            dotenvContents: $dotenv,
            includeBaseEnv: false
        );

        $this->assertSame(
            ['defined' => true, 'value' => 'container_db', 'type' => 'string'],
            $result['constants']['DB_NAME']
        );
        $this->assertSame(
            ['defined' => true, 'value' => 'container_user', 'type' => 'string'],
            $result['constants']['DB_USER']
        );
        $this->assertSame(
            ['defined' => true, 'value' => 'container_pass', 'type' => 'string'],
            $result['constants']['DB_PASSWORD']
        );
        $this->assertSame(
            ['defined' => true, 'value' => '10.0.0.5:3306', 'type' => 'string'],
            $result['constants']['DB_HOST']
        );
        $this->assertFalse($result['env']['DATABASE_URL']);
        $this->assertFalse($result['server']['DATABASE_URL']);
    }

    public function test_skips_empty_string_and_null_wp_stateless_values(): void
    {
        $result = $this->evaluateApplicationConfig(
            envVars: [
                'WP_STATELESS_MEDIA_BUCKET' => '',
                'WP_STATELESS_MEDIA_JSON_KEY' => '',
                'WP_STATELESS_MEDIA_ROOT_DIR' => 'null',
            ],
            inspectConstants: [
                'WP_STATELESS_MEDIA_BUCKET',
                'WP_STATELESS_MEDIA_JSON_KEY',
                'WP_STATELESS_MEDIA_ROOT_DIR',
            ]
        );

        $this->assertFalse($result['constants']['WP_STATELESS_MEDIA_BUCKET']['defined']);
        $this->assertFalse($result['constants']['WP_STATELESS_MEDIA_JSON_KEY']['defined']);
        $this->assertFalse($result['constants']['WP_STATELESS_MEDIA_ROOT_DIR']['defined']);
    }

    public function test_ignores_malformed_names_and_http_headers_while_accepting_sapi_server_keys(): void
    {
        $result = $this->evaluateApplicationConfig(
            envVars: [
                'WP_STATELESS_' => 'bare-prefix',
                'WP_STATELESS_lowercase' => 'lowercase-suffix',
                'WP_STATELESS_FOO-BAR' => 'hyphenated-key',
            ],
            serverVars: [
                'HTTP_WP_STATELESS_MEDIA_MODE' => 'injected-via-http-header',
                'WP_STATELESS_SAPI_PARAM' => 'from-fastcgi-param',
            ],
            inspectConstants: [
                'WP_STATELESS_',
                'WP_STATELESS_lowercase',
                'WP_STATELESS_FOO-BAR',
                'HTTP_WP_STATELESS_MEDIA_MODE',
                'WP_STATELESS_MEDIA_MODE',
                'WP_STATELESS_SAPI_PARAM',
            ],
            inspectSuperglobalKeys: [
                'WP_STATELESS_SAPI_PARAM',
            ]
        );

        $this->assertFalse($result['constants']['WP_STATELESS_']['defined']);
        $this->assertFalse($result['constants']['WP_STATELESS_lowercase']['defined']);
        $this->assertFalse($result['constants']['WP_STATELESS_FOO-BAR']['defined']);
        $this->assertFalse($result['constants']['HTTP_WP_STATELESS_MEDIA_MODE']['defined']);
        $this->assertFalse($result['constants']['WP_STATELESS_MEDIA_MODE']['defined']);

        $this->assertSame(
            ['defined' => true, 'value' => 'from-fastcgi-param', 'type' => 'string'],
            $result['constants']['WP_STATELESS_SAPI_PARAM']
        );
        $this->assertFalse($result['env']['WP_STATELESS_SAPI_PARAM']);
        $this->assertFalse($result['server']['WP_STATELESS_SAPI_PARAM']);
    }

    /**
     * @param array<string, string> $serverVars
     */
    #[DataProvider('validHostHeadersProvider')]
    public function test_accepts_valid_http_host_and_forwarded_host_headers(
        array $serverVars,
        string $expectedHome,
        string $expectedSiteUrl
    ): void {
        $result = $this->evaluateApplicationConfig(
            serverVars: $serverVars,
            inspectConstants: ['WP_HOME', 'WP_SITEURL']
        );

        $this->assertSame(
            ['defined' => true, 'value' => $expectedHome, 'type' => 'string'],
            $result['constants']['WP_HOME']
        );
        $this->assertSame(
            ['defined' => true, 'value' => $expectedSiteUrl, 'type' => 'string'],
            $result['constants']['WP_SITEURL']
        );
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string, 2: string}>
     */
    public static function validHostHeadersProvider(): array
    {
        return [
            'domain over http' => [
                ['HTTP_HOST' => 'blog.example.com'],
                'http://blog.example.com',
                'http://blog.example.com/wp',
            ],
            'ipv4 with port' => [
                ['HTTP_HOST' => '127.0.0.1:18080'],
                'http://127.0.0.1:18080',
                'http://127.0.0.1:18080/wp',
            ],
            'forwarded host with https proto' => [
                [
                    'HTTP_HOST' => 'internal-service.run.app',
                    'HTTP_X_FORWARDED_HOST' => 'editor.example.com',
                    'HTTP_X_FORWARDED_PROTO' => 'https',
                ],
                'https://editor.example.com',
                'https://editor.example.com/wp',
            ],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedHostHeadersProvider(): array
    {
        return [
            'path injection' => ['evil.com/path'],
            'xss payload' => ['evil.com"><script>'],
            'comma-separated forwarded hosts' => ['a.com, b.com'],
            'userinfo injection' => ['user@evil.com'],
        ];
    }

    #[DataProvider('malformedHostHeadersProvider')]
    public function test_rejects_malformed_host_headers_unsets_server_keys_and_falls_back_to_wp_home(
        string $malformedHost
    ): void {
        $result = $this->evaluateApplicationConfig(
            envVars: [
                'WP_HOME' => 'https://canonical.example.com',
            ],
            serverVars: [
                'HTTP_HOST' => 'initial.example.com',
                'HTTP_X_FORWARDED_HOST' => $malformedHost,
            ],
            inspectConstants: ['WP_HOME', 'WP_SITEURL'],
            inspectSuperglobalKeys: ['HTTP_HOST', 'HTTP_X_FORWARDED_HOST']
        );

        $this->assertSame(
            ['defined' => true, 'value' => 'https://canonical.example.com', 'type' => 'string'],
            $result['constants']['WP_HOME']
        );
        $this->assertSame(
            ['defined' => true, 'value' => 'https://canonical.example.com/wp', 'type' => 'string'],
            $result['constants']['WP_SITEURL']
        );
        $this->assertFalse($result['server']['HTTP_HOST']);
        $this->assertFalse($result['server']['HTTP_X_FORWARDED_HOST']);
    }

    #[DataProvider('malformedHostHeadersProvider')]
    public function test_malformed_host_header_throws_runtime_exception_when_wp_home_is_absent(
        string $malformedHost
    ): void {
        $result = $this->evaluateApplicationConfig(
            envVars: [
                'WP_ENV' => 'production',
                'DB_NAME' => 'test_db',
                'DB_USER' => 'test_user',
                'DB_PASSWORD' => 'test_pass',
            ],
            serverVars: [
                'HTTP_HOST' => $malformedHost,
            ],
            inspectSuperglobalKeys: ['HTTP_HOST', 'HTTP_X_FORWARDED_HOST'],
            includeBaseEnv: false,
            expectException: true
        );

        $this->assertNotNull($result['exception']);
        $this->assertSame(\RuntimeException::class, $result['exception']['class']);
        $this->assertStringContainsString('WP_HOME is required', $result['exception']['message']);
        $this->assertFalse($result['server']['HTTP_HOST']);
        $this->assertFalse($result['server']['HTTP_X_FORWARDED_HOST']);
    }

    public function test_scrubs_all_bedrock_and_stateless_keys_without_dotenv_while_preserving_otel_keys(): void
    {
        $jsonKey = json_encode([
            'type' => 'service_account',
            'project_id' => 'demo-gcp-project',
            'private_key_id' => 'key-123',
            'client_email' => 'wp-stateless@demo-gcp-project.iam.gserviceaccount.com',
        ], JSON_THROW_ON_ERROR);

        $result = $this->evaluateApplicationConfig(
            envVars: [
                'WP_ENV' => 'production',
                'WP_ENVIRONMENT_TYPE' => 'production',
                'WP_HOME' => 'https://example.test',
                'WP_SITEURL' => 'https://example.test/wp',
                'DB_NAME' => 'test_db',
                'DB_USER' => 'test_user',
                'DB_PASSWORD' => 'test_pass',
                'GOOGLESITEKIT_ENCRYPTION_KEY' => 'sitekit-enc-key',
                'GOOGLESITEKIT_ENCRYPTION_SALT' => 'sitekit-enc-salt',
                'IAP_AUTH_ENABLED' => 'true',
                'IAP_AUDIENCE' => '/projects/123/global/backendServices/456',
                'WP_STATELESS_MEDIA_JSON_KEY' => $jsonKey,
                'OTEL_SERVICE_NAME' => 'wordpress-editor',
                'OTEL_EXPORTER_OTLP_ENDPOINT' => 'http://127.0.0.1:4317',
            ],
            inspectConstants: [
                'WP_STATELESS_MEDIA_JSON_KEY',
                'GOOGLESITEKIT_ENCRYPTION_KEY',
                'GOOGLESITEKIT_ENCRYPTION_SALT',
                'IAP_AUTH_ENABLED',
                'IAP_AUDIENCE',
            ],
            inspectSuperglobalKeys: [
                'WP_STATELESS_MEDIA_JSON_KEY',
                'GOOGLESITEKIT_ENCRYPTION_KEY',
                'GOOGLESITEKIT_ENCRYPTION_SALT',
                'IAP_AUTH_ENABLED',
                'IAP_AUDIENCE',
                'WP_ENV',
                'WP_ENVIRONMENT_TYPE',
                'WP_HOME',
                'WP_SITEURL',
                'DB_PASSWORD',
                'OTEL_SERVICE_NAME',
                'OTEL_EXPORTER_OTLP_ENDPOINT',
            ],
            variablesOrder: 'GPCS',
            includeBaseEnv: false
        );

        $this->assertSame(
            ['defined' => true, 'value' => $jsonKey, 'type' => 'string'],
            $result['constants']['WP_STATELESS_MEDIA_JSON_KEY']
        );
        $this->assertSame(
            ['defined' => true, 'value' => 'sitekit-enc-key', 'type' => 'string'],
            $result['constants']['GOOGLESITEKIT_ENCRYPTION_KEY']
        );
        $this->assertSame(
            ['defined' => true, 'value' => 'sitekit-enc-salt', 'type' => 'string'],
            $result['constants']['GOOGLESITEKIT_ENCRYPTION_SALT']
        );
        $this->assertSame(
            ['defined' => true, 'value' => true, 'type' => 'bool'],
            $result['constants']['IAP_AUTH_ENABLED']
        );
        $this->assertSame(
            ['defined' => true, 'value' => '/projects/123/global/backendServices/456', 'type' => 'string'],
            $result['constants']['IAP_AUDIENCE']
        );

        foreach (
            [
                'WP_STATELESS_MEDIA_JSON_KEY',
                'GOOGLESITEKIT_ENCRYPTION_KEY',
                'GOOGLESITEKIT_ENCRYPTION_SALT',
                'IAP_AUTH_ENABLED',
                'IAP_AUDIENCE',
                'WP_ENV',
                'WP_ENVIRONMENT_TYPE',
                'WP_HOME',
                'WP_SITEURL',
                'DB_PASSWORD',
            ] as $scrubbedKey
        ) {
            $this->assertFalse($result['env'][$scrubbedKey], "Expected {$scrubbedKey} to be absent from \$_ENV.");
            $this->assertFalse(
                $result['server'][$scrubbedKey],
                "Expected {$scrubbedKey} to be absent from \$_SERVER."
            );
        }

        // Under variables_order=GPCS, untouched OS env vars remain in $_SERVER (not backfilled to $_ENV).
        $this->assertTrue($result['server']['OTEL_SERVICE_NAME']);
        $this->assertTrue($result['server']['OTEL_EXPORTER_OTLP_ENDPOINT']);
    }

    public function test_preserves_otel_keys_in_both_env_and_server_under_egpcs(): void
    {
        $result = $this->evaluateApplicationConfig(
            envVars: [
                'OTEL_SERVICE_NAME' => 'wordpress-reader',
                'OTEL_EXPORTER_OTLP_ENDPOINT' => 'http://127.0.0.1:4317',
            ],
            inspectSuperglobalKeys: [
                'DB_PASSWORD',
                'OTEL_SERVICE_NAME',
                'OTEL_EXPORTER_OTLP_ENDPOINT',
            ],
            variablesOrder: 'EGPCS'
        );

        $this->assertFalse($result['env']['DB_PASSWORD']);
        $this->assertFalse($result['server']['DB_PASSWORD']);
        $this->assertTrue($result['env']['OTEL_SERVICE_NAME']);
        $this->assertTrue($result['server']['OTEL_SERVICE_NAME']);
        $this->assertTrue($result['env']['OTEL_EXPORTER_OTLP_ENDPOINT']);
        $this->assertTrue($result['server']['OTEL_EXPORTER_OTLP_ENDPOINT']);
    }
}
