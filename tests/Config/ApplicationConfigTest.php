<?php

declare(strict_types=1);

namespace Tests\Config;

use PHPUnit\Framework\TestCase;

class ApplicationConfigTest extends TestCase
{
    /**
     * Execute config/application.php inside an isolated PHP subprocess so global
     * constants and Roots\WPConfig\Config static state never collide across tests.
     *
     * @param array<string, string> $envVars
     * @param array<string, string> $serverOnlyVars
     * @param list<string> $inspectConstants
     * @param list<string> $inspectSuperglobalKeys
     * @return array{
     *     constants: array<string, array{defined: bool, value: mixed, type: string|null}>,
     *     env: array<string, bool>,
     *     server: array<string, bool>
     * }
     */
    private function evaluateApplicationConfig(
        array $envVars,
        array $serverOnlyVars = [],
        array $inspectConstants = [],
        array $inspectSuperglobalKeys = []
    ): array {
        $rootDir = dirname(__DIR__, 2);
        $this->assertFileDoesNotExist(
            $rootDir . '/.env',
            'Repository root must not contain a .env file during isolated config tests.'
        );

        $payload = json_encode([
            'rootDir' => $rootDir,
            'serverOnlyVars' => $serverOnlyVars,
            'inspectConstants' => $inspectConstants,
            'inspectSuperglobalKeys' => $inspectSuperglobalKeys,
        ], JSON_THROW_ON_ERROR);

        $runner = <<<'PHP'
            $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
            require_once $input['rootDir'] . '/vendor/autoload.php';

            foreach ($input['serverOnlyVars'] as $k => $v) {
                unset($_ENV[$k]);
                $_SERVER[$k] = $v;
            }

            require $input['rootDir'] . '/config/application.php';

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
            ], JSON_THROW_ON_ERROR);
            PHP;

        $baseEnv = [
            'WP_ENV' => 'production',
            'WP_HOME' => 'https://example.test',
            'DB_NAME' => 'test_db',
            'DB_USER' => 'test_user',
            'DB_PASSWORD' => 'test_pass',
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            [PHP_BINARY, '-d', 'variables_order=EGPCS', '-d', 'display_errors=0', '-r', $runner],
            $descriptors,
            $pipes,
            $rootDir,
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

        $this->assertSame(0, $exitCode, sprintf("Subprocess failed (exit %d): %s\n%s", $exitCode, $stderr, $stdout));

        /** @var array{constants: array<string, array{defined: bool, value: mixed, type: string|null}>, env: array<string, bool>, server: array<string, bool>} $decoded */
        $decoded = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    public function test_maps_wp_stateless_env_vars_across_string_bool_and_int_types(): void
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
            ]
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

    public function test_ignores_malformed_wp_stateless_names_and_server_only_keys(): void
    {
        $result = $this->evaluateApplicationConfig(
            envVars: [
                'WP_STATELESS_' => 'bare-prefix',
                'WP_STATELESS_lowercase' => 'lowercase-suffix',
                'WP_STATELESS_FOO-BAR' => 'hyphenated-key',
            ],
            serverOnlyVars: [
                'WP_STATELESS_SERVER_ONLY' => 'from-server-only',
            ],
            inspectConstants: [
                'WP_STATELESS_',
                'WP_STATELESS_lowercase',
                'WP_STATELESS_FOO-BAR',
                'WP_STATELESS_SERVER_ONLY',
            ]
        );

        $this->assertFalse($result['constants']['WP_STATELESS_']['defined']);
        $this->assertFalse($result['constants']['WP_STATELESS_lowercase']['defined']);
        $this->assertFalse($result['constants']['WP_STATELESS_FOO-BAR']['defined']);
        $this->assertFalse($result['constants']['WP_STATELESS_SERVER_ONLY']['defined']);
    }

    public function test_defines_and_scrubs_wp_stateless_media_json_key_while_preserving_otel_keys(): void
    {
        $jsonKey = json_encode([
            'type' => 'service_account',
            'project_id' => 'demo-gcp-project',
            'private_key_id' => 'key-123',
            'client_email' => 'wp-stateless@demo-gcp-project.iam.gserviceaccount.com',
        ], JSON_THROW_ON_ERROR);

        $result = $this->evaluateApplicationConfig(
            envVars: [
                'WP_STATELESS_MEDIA_JSON_KEY' => $jsonKey,
                'OTEL_SERVICE_NAME' => 'wordpress-editor',
                'OTEL_EXPORTER_OTLP_ENDPOINT' => 'http://127.0.0.1:4317',
            ],
            inspectConstants: [
                'WP_STATELESS_MEDIA_JSON_KEY',
            ],
            inspectSuperglobalKeys: [
                'WP_STATELESS_MEDIA_JSON_KEY',
                'DB_PASSWORD',
                'OTEL_SERVICE_NAME',
                'OTEL_EXPORTER_OTLP_ENDPOINT',
            ]
        );

        $this->assertSame(
            ['defined' => true, 'value' => $jsonKey, 'type' => 'string'],
            $result['constants']['WP_STATELESS_MEDIA_JSON_KEY']
        );

        $this->assertFalse($result['env']['WP_STATELESS_MEDIA_JSON_KEY']);
        $this->assertFalse($result['server']['WP_STATELESS_MEDIA_JSON_KEY']);
        $this->assertFalse($result['env']['DB_PASSWORD']);
        $this->assertFalse($result['server']['DB_PASSWORD']);

        $this->assertTrue($result['env']['OTEL_SERVICE_NAME']);
        $this->assertTrue($result['server']['OTEL_SERVICE_NAME']);
        $this->assertTrue($result['env']['OTEL_EXPORTER_OTLP_ENDPOINT']);
        $this->assertTrue($result['server']['OTEL_EXPORTER_OTLP_ENDPOINT']);
    }
}
