# PHP Sync Utility

Utility PHP per sincronizzare due directory via HTTP/HTTPS.

## File principali
- `db.php`: configurazione globale
- `client.php`: esegue la scansione locale e invia i file al `daemon`
- `daemon.php`: endpoint remoto che riceve inventario e file
- `lib.php`: funzioni comuni

## Configurazione
Modifica `db.php`:
- `local_dir`: cartella di origine / destinazione
- `remote_endpoint`: URL di `daemon.php`
- `auth_token`: token condiviso per autenticazione
- `ignore_patterns`: regex per ignorare file/cartelle
- `gzip_threshold`: dimensione oltre la quale viene usata la compressione gzip
- `delete_remote`: se `true`, cancella i file remoti non presenti localmente
- il client aggiorna anche i file locali più recenti sul remoto e scarica dal remoto i file più aggiornati

## Uso
1. Carica `daemon.php`, `db.php`, `lib.php` su Altervista.
2. Assicurati che `local_dir` sul server remoto sia scrivibile.
3. Avvia `client.php` dal browser: `client.php?debug=1&log=1`
4. Il client mostrerà il log in tempo reale e invierà i file modificati.

## Note
- Multipiattaforma: usa `DIRECTORY_SEPARATOR` e percorsi compatibili.
- `client.php` usa `set_time_limit(0)` per prolungare il timeout.
- La lista di ignorati è gestita con regex.
- I file grandi vengono compressi con gzip prima dell'invio.
