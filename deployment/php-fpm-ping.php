#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Zero-dependency FastCGI status probe for PHP-FPM (`pm.status_listen` / `pm.status_path`).
 * Replaces `libfcgi-bin` (`cgi-fcgi`), `php-fpm-healthcheck`, and external Go health sidecars.
 */
$listen = trim((string) (getenv('PM_STATUS_LISTEN') ?: '127.0.0.1:9001'));
$statusPath = trim((string) (getenv('PM_STATUS_PATH') ?: '/status'));

if (str_starts_with($listen, '/')) {
    $uri = "unix://{$listen}";
} elseif (str_contains($listen, ':')) {
    $uri = "tcp://{$listen}";
} else {
    $uri = "tcp://127.0.0.1:{$listen}";
}

$fp = @stream_socket_client($uri, $errno, $errstr, 2.0);
if ($fp === false) {
    fwrite(STDERR, "PHP-FPM status connection failed ({$uri}): [{$errno}] {$errstr}\n");
    exit(1);
}
stream_set_timeout($fp, 2);

$encodeLen = static fn(int $len): string => $len < 128 ? chr($len) : pack('N', $len | 0x80000000);
$nvPair = static fn(string $k, string $v): string => $encodeLen(strlen($k)) . $encodeLen(strlen($v)) . $k . $v;
$record = static function (int $type, string $content = ''): string {
    $len = strlen($content);
    $pad = (8 - ($len % 8)) % 8;

    return pack('CCnnCC', 1, $type, 1, $len, $pad, 0) . $content . str_repeat("\0", $pad);
};

$params = $nvPair('REQUEST_METHOD', 'GET')
    . $nvPair('SCRIPT_NAME', $statusPath)
    . $nvPair('SCRIPT_FILENAME', $statusPath);

// FCGI_BEGIN_REQUEST (role=FCGI_RESPONDER=1) + FCGI_PARAMS + empty FCGI_PARAMS + empty FCGI_STDIN
$packet = $record(1, pack('nCCCCCC', 1, 0, 0, 0, 0, 0, 0))
    . $record(4, $params)
    . $record(4)
    . $record(5);

if (@fwrite($fp, $packet) !== strlen($packet)) {
    fclose($fp);
    fwrite(STDERR, "Failed to write FastCGI status packet to {$uri}\n");
    exit(1);
}

$stdout = '';
while (!feof($fp)) {
    $header = fread($fp, 8);
    if ($header === false || strlen($header) < 8) {
        break;
    }
    /** @var array{ver:int,type:int,req:int,len:int,pad:int,res:int} $hdr */
    $hdr = unpack('Cver/Ctype/nreq/nlen/Cpad/Cres', $header);
    $body = $hdr['len'] > 0 ? (string) stream_get_contents($fp, $hdr['len']) : '';
    if ($hdr['pad'] > 0) {
        stream_get_contents($fp, $hdr['pad']);
    }
    if ($hdr['type'] === 6) { // FCGI_STDOUT
        $stdout .= $body;
    } elseif ($hdr['type'] === 3) { // FCGI_END_REQUEST
        break;
    }
}
fclose($fp);

if (!str_contains($stdout, 'pool:') || !str_contains($stdout, 'process manager:')) {
    fwrite(STDERR, "Unexpected PHP-FPM status response from {$uri}: {$stdout}\n");
    exit(1);
}
