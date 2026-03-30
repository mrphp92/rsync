<?php
/**
 * Configurazione globale per la sincronizzazione.
 * Modifica i percorsi locali, remoti e l'endpoint del daemon.
 */
return [
    'local_dir' => __DIR__ . DIRECTORY_SEPARATOR . '..',
    'remote_dir' => __DIR__ . DIRECTORY_SEPARATOR . '..',
    'remote_endpoint' => 'https://mysite.altervista.org/rsync/daemon.php',
    'auth_token' => 'ChangeThis',
    'ignore_patterns' => [
        '/\.git/',             // cartella git
        '/\.DS_Store$/',       // macOS
        '/^vendor\//',         // dipendenze
        '/\.log$/i',           // log
        '/^rsync\//i'
    ],
    'gzip_threshold' => 64 * 1024,
    'delete_remote' => true,
    'log_file' => __DIR__ . DIRECTORY_SEPARATOR . 'sync.log',
    'timelimit' => 3600
];
