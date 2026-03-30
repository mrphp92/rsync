<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';

$config = sync_config();
sync_reset_log();
$debug = !isset($_GET['debug']) || $_GET['debug'] === '1';
$log = !isset($_GET['log']) || $_GET['log'] === '1';

set_time_limit($config["timelimit"]);
ignore_user_abort(true);

while (ob_get_level() > 0) {
    ob_end_flush();
}
header('Content-Type: text/plain; charset=UTF-8');

function client_output(string $message): void
{
    global $log;
    echo $message . "\n";
    if ($log) {
        sync_log($message);
    }
    sync_flush();
}

client_output('--- SYNC CLIENT START ---');
if ($debug) {
    client_output('Debug attivo.');
}

$localDir = $config['local_dir'];
if (!is_dir($localDir)) {
    client_output("Errore: directory locale non trovata: {$localDir}");
    exit(1);
}

try {
    client_output('Scansione della directory locale: ' . $localDir);
    $localFiles = sync_scan_dir($localDir, $config['ignore_patterns']);
    client_output('File locali trovati: ' . count($localFiles));

    client_output('Richiesta inventario remoto a: ' . $config['remote_endpoint']);
    $remoteResponse = sync_http_request($config['remote_endpoint'], [
        'action' => 'inventory',
        'token' => $config['auth_token'],
    ]);

    if (empty($remoteResponse['status']) || $remoteResponse['status'] !== 'ok') {
        throw new RuntimeException('Inventario remoto non disponibile.');
    }

    $remoteFiles = $remoteResponse['files'] ?? [];
    client_output('File remoti trovati: ' . count($remoteFiles));

    $toUpload = [];
    $toDownload = [];

    foreach ($localFiles as $relativePath => $localInfo) {
        if (!isset($remoteFiles[$relativePath])) {
            $toUpload[$relativePath] = $localInfo;
            continue;
        }

        $remoteInfo = $remoteFiles[$relativePath];
        if ($localInfo['hash'] === $remoteInfo['hash']) {
            continue;
        }

        if ($localInfo['mtime'] > $remoteInfo['mtime']) {
            $toUpload[$relativePath] = $localInfo;
        } elseif ($remoteInfo['mtime'] > $localInfo['mtime']) {
            $toDownload[$relativePath] = $remoteInfo;
        } else {
            client_output("Conflitto hash: {$relativePath} (stesso mtime, hash diverso) - mantengo locale");
        }
    }

    $remoteOnly = array_diff_key($remoteFiles, $localFiles);
    if ($config['delete_remote']) {
        client_output('File remoti non presenti localmente (elimino remoto): ' . count($remoteOnly));
        foreach ($remoteOnly as $relativePath => $_) {
            $payload = [
                'action' => 'delete',
                'token' => $config['auth_token'],
                'path' => $relativePath,
            ];
            $deleteResponse = sync_http_request($config['remote_endpoint'], $payload);
            client_output('Delete ' . $relativePath . ': ' . ($deleteResponse['status'] === 'ok' ? 'ok' : 'errore'));
        }
    } else {
        foreach ($remoteOnly as $relativePath => $remoteInfo) {
            $toDownload[$relativePath] = $remoteInfo;
        }
    }

    client_output('File da scaricare: ' . count($toDownload));
    foreach ($toDownload as $relativePath => $remoteInfo) {
        $payload = [
            'action' => 'download',
            'token' => $config['auth_token'],
            'path' => $relativePath,
        ];

        client_output("Scarico {$relativePath} ...");
        $downloadResponse = sync_http_request($config['remote_endpoint'], $payload);
        if (empty($downloadResponse['status']) || $downloadResponse['status'] !== 'ok' || !array_key_exists('content', $downloadResponse)) {
            client_output("Errore download {$relativePath}: " . json_encode($downloadResponse));
            continue;
        }

        $content = base64_decode($downloadResponse['content'], true);
        if ($content === false) {
            client_output("Errore decodifica download {$relativePath}");
            continue;
        }

        if (!empty($downloadResponse['gzip'])) {
            try {
                $content = sync_gzip_decode($content);
            } catch (Throwable $e) {
                client_output("Errore decompresssione {$relativePath}: " . $e->getMessage());
                continue;
            }
        }

        $targetPath = $localDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        sync_create_directory(dirname($targetPath));
        if (file_put_contents($targetPath, $content) === false) {
            client_output("Impossibile scrivere il file locale: {$relativePath}");
            continue;
        }

        if (!empty($downloadResponse['mtime'])) {
            @touch($targetPath, (int) $downloadResponse['mtime']);
        }

        client_output("Scaricato: {$relativePath}");
        $localFiles[$relativePath] = [
            'path' => $relativePath,
            'size' => strlen($content),
            'mtime' => !empty($downloadResponse['mtime']) ? (int) $downloadResponse['mtime'] : filemtime($targetPath),
            'hash' => md5($content),
        ];
    }

    client_output('File da caricare: ' . count($toUpload));

    foreach ($toUpload as $relativePath => $info) {
        if (sync_match_ignore($relativePath, $config['ignore_patterns'])) {
            client_output("Ignorato localmente: {$relativePath}");
            continue;
        }

        $sourcePath = $localDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $data = file_get_contents($sourcePath);
        if ($data === false) {
            client_output("Impossibile leggere il file: {$relativePath}");
            continue;
        }

        $useGzip = $info['size'] >= $config['gzip_threshold'];
        if ($useGzip) {
            $payloadData = base64_encode(sync_gzip_encode($data));
        } else {
            $payloadData = base64_encode($data);
        }

        $payload = [
            'action' => 'upload',
            'token' => $config['auth_token'],
            'path' => $relativePath,
            'mtime' => $info['mtime'],
            'content' => $payloadData,
            'gzip' => $useGzip ? 1 : 0,
        ];

        client_output("Invio {$relativePath} ({$info['size']} bytes) ...");
        $uploadResponse = sync_http_request($config['remote_endpoint'], $payload);
        if (!empty($uploadResponse['status']) && $uploadResponse['status'] === 'ok') {
            client_output("Caricato: {$relativePath}");
        } else {
            client_output("Errore upload {$relativePath}: " . json_encode($uploadResponse));
        }
    }

    client_output('--- SYNC CLIENT COMPLETATO ---');
} catch (Throwable $e) {
    client_output('ERRORE: ' . $e->getMessage());
    client_output('Dettagli: ' . sync_exception_details($e));
    if ($debug) {
        client_output($e->getTraceAsString());
    }
    exit(1);
}
