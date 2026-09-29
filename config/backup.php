<?php

/*
| Nightly backups (DECISIONS D38). Each backup is one AES-256 encrypted zip holding a SQL dump of the
| database and the private, confidential and media files. It is kept on this server, outside the web root,
| which protects against mistakes but not against losing the hosting account: copy backups off the server
| as well (docs/BACKUP-AND-RESTORE.md).
*/

return [
    // Kept outside public/ and outside the folders that are themselves backed up.
    'path' => env('BACKUP_PATH', storage_path('app/backups')),

    // Required: backups are never written unencrypted. Keep a copy of this password away from the server.
    'password' => env('BACKUP_PASSWORD'),

    // How many backups to keep on the server; older ones are removed after a successful new backup.
    'keep' => (int) env('BACKUP_KEEP', 7),

    // Local time (firm timezone) for the nightly run.
    'at' => env('BACKUP_AT', '02:30'),

    // File areas included, by filesystem disk. Temporary uploads are left out.
    'disks' => ['local', 'confidential', 'media', 'public'],
    'exclude' => ['livewire-tmp'],
];
