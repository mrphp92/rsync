<?php
/**
 * Funzioni di supporto per il sincronizzatore.
 */

function sync_config(): array
{
    static $config;
    if ($config === null) {
        $config = require __DIR__ . DIRECTORY_SEPARATOR . 'db.php';
    }
    return $config;
}

function sync_log(string $message): void
{
    $config = sync_config();
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    $path = $config['log_file'];
    $existing = '';
    if (file_exists($path)) {
        $existing = file_get_contents($path);
        if ($existing === false) {
            $existing = '';
        }
    }
    file_put_contents($path, $line . $existing, LOCK_EX);
}

function sync_reset_log(): void
{
    $config = sync_config();
    file_put_contents($config['log_file'], '', LOCK_EX);
}

function sync_exception_details(Throwable $e): string
{
    return sprintf(
        "%s: %s in %s on line %d",
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    );
}

function sync_flush(): void
{
    @ob_flush();
    @flush();
}

function sync_match_ignore(string $relativePath, array $patterns): bool
{
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $relativePath)) {
            return true;
        }
    }
    return false;
}

function sync_scan_dir(string $baseDir, array $ignorePatterns): array
{
    $result = [];
    $directory = new RecursiveDirectoryIterator($baseDir, RecursiveDirectoryIterator::SKIP_DOTS);
    $iterator = new RecursiveIteratorIterator($directory);

    foreach ($iterator as $fileinfo) {
        if (!$fileinfo->isFile()) {
            continue;
        }

        $relativePath = str_replace($baseDir . DIRECTORY_SEPARATOR, '', $fileinfo->getPathname());
        $relativePath = str_replace('\\', '/', $relativePath);

        if (sync_match_ignore($relativePath, $ignorePatterns)) {
            continue;
        }

        $result[$relativePath] = [
            'path' => $relativePath,
            'size' => $fileinfo->getSize(),
            'mtime' => $fileinfo->getMTime(),
            'hash' => md5_file($fileinfo->getPathname()),
        ];
    }

    ksort($result);
    return $result;
}

function sync_gzip_encode(string $data): string
{
    return gzencode($data, 6);
}

function sync_gzip_decode(string $data): string
{
    $decoded = @gzdecode($data);
    if ($decoded === false) {
        throw new RuntimeException('Impossibile decomprimere il payload gzip.');
    }
    return $decoded;
}

function sync_http_request(string $url, array $payload, bool $usePost = true, int $timeout = 120): array
{
    if (function_exists('curl_version')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        if ($usePost) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException("HTTP request failed: {$error}");
        }

        return json_decode($response ?: '', true) ?: [];
    }

    $options = [
        'http' => [
            'method' => $usePost ? 'POST' : 'GET',
            'header' => "Content-Type: application/json\r\n",
            'content' => $usePost ? json_encode($payload) : null,
            'timeout' => $timeout,
        ],
    ];

    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        $error = error_get_last();
        throw new RuntimeException('HTTP request failed: ' . ($error['message'] ?? 'unknown'));
    }

    return json_decode($response, true) ?: [];
}

function sync_normalize_path(string $path): string
{
    return rtrim(str_replace('\\', '/', $path), '/');
}

function sync_create_directory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
        throw new RuntimeException("Impossibile creare la cartella: {$path}");
    }
}
