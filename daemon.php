<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';

$config = sync_config();
set_time_limit($config["timelimit"]);
ignore_user_abort(true);

function daemon_output(array $payload): void
{
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

$body = file_get_contents('php://input');
$request = json_decode($body ?: '', true);
if (!is_array($request)) {
    daemon_output(['status' => 'error', 'message' => 'Payload JSON non valido.']);
}

if (empty($request['token']) || $request['token'] !== $config['auth_token']) {
    daemon_output(['status' => 'error', 'message' => 'Token di autenticazione errato.']);
}

$action = $request['action'] ?? '';
$destDir = $config['remote_dir'] ?? $config['local_dir'];

try {
    switch ($action) {
        case 'inventory':
            if (!is_dir($destDir)) {
                sync_create_directory($destDir);
            }
            $files = sync_scan_dir($destDir, $config['ignore_patterns']);
            daemon_output(['status' => 'ok', 'files' => $files]);
            break;

        case 'upload':
            $relativePath = $request['path'] ?? '';
            if ($relativePath === '') {
                throw new RuntimeException('Path non specificato.');
            }
            if (sync_match_ignore($relativePath, $config['ignore_patterns'])) {
                daemon_output(['status' => 'error', 'message' => 'File ignorato: ' . $relativePath]);
            }

            $targetPath = $destDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            $targetDir = dirname($targetPath);
            sync_create_directory($targetDir);

            $content = base64_decode($request['content'] ?? '');
            if ($content === false) {
                throw new RuntimeException('Contenuto non valido.');
            }

            if (!empty($request['gzip'])) {
                $content = sync_gzip_decode($content);
            }

            if (file_put_contents($targetPath, $content) === false) {
                throw new RuntimeException('Impossibile scrivere il file remoto.');
            }

            if (!empty($request['mtime'])) {
                @touch($targetPath, (int) $request['mtime']);
            }

            sync_log('File ricevuto: ' . $relativePath);
            daemon_output(['status' => 'ok']);
            break;

        case 'download':
            $relativePath = $request['path'] ?? '';
            if ($relativePath === '') {
                throw new RuntimeException('Path non specificato.');
            }
            if (sync_match_ignore($relativePath, $config['ignore_patterns'])) {
                daemon_output(['status' => 'error', 'message' => 'File ignorato: ' . $relativePath]);
            }

            $sourcePath = $destDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            if (!is_file($sourcePath)) {
                throw new RuntimeException('File non trovato: ' . $relativePath);
            }

            $content = file_get_contents($sourcePath);
            if ($content === false) {
                throw new RuntimeException('Impossibile leggere il file remoto.');
            }

            $useGzip = strlen($content) >= $config['gzip_threshold'];
            if ($useGzip) {
                $payloadContent = base64_encode(sync_gzip_encode($content));
            } else {
                $payloadContent = base64_encode($content);
            }

            daemon_output([
                'status' => 'ok',
                'content' => $payloadContent,
                'gzip' => $useGzip ? 1 : 0,
                'mtime' => filemtime($sourcePath),
            ]);
            break;

        case 'delete':
            $relativePath = $request['path'] ?? '';
            if ($relativePath === '') {
                throw new RuntimeException('Path non specificato per delete.');
            }
            $targetPath = $destDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            if (is_file($targetPath)) {
                unlink($targetPath);
                sync_log('File cancellato: ' . $relativePath);
                daemon_output(['status' => 'ok']);
            }
            daemon_output(['status' => 'ok', 'message' => 'File non trovato.']);
            break;

        default:
            daemon_output(['status' => 'error', 'message' => 'Azione non supportata.']);
    }
} catch (Throwable $e) {
    sync_log('ERRORE DAEMON: ' . sync_exception_details($e));
    $errorPayload = [
        'status' => 'error',
        'message' => $e->getMessage(),
        'type' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ];
    if (!empty($request['action'])) {
        $errorPayload['action'] = $request['action'];
    }
    daemon_output($errorPayload);
}
