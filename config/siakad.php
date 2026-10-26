<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Setting Defaults
    |--------------------------------------------------------------------------
    |
    | Default values for App\Models\Setting. Superadmin can override them from
    | the settings page; overrides are stored in the "settings" table.
    |
    */

    'settings' => [
        'institution.name' => env('APP_NAME', 'SIAKAD Mahad Aly'),
        'institution.address' => null,
        'institution.phone' => null,
        'payment.max_installments' => 3,
        'payment.min_installment_amount' => 200000,
        // Shown to mahasantri and wali santri who pay by transfer, e.g. "BSI 7123456789 a.n. Yayasan …".
        'payment.bank_accounts' => null,
        'receipt.footer' => null,
        'attendance.min_percent' => 75,
    ],

    /*
    |--------------------------------------------------------------------------
    | Backups (php artisan siakad:backup, scheduled daily)
    |--------------------------------------------------------------------------
    |
    | Each run writes a folder with a pg_dump of the database and a zip of
    | the private files (photos, documents, proofs, materials, submissions).
    | Point BACKUP_PATH at a disk other than the server's own when possible.
    |
    */

    'backup' => [
        'path' => env('BACKUP_PATH') ?: storage_path('app'.DIRECTORY_SEPARATOR.'backups'),
        'connection' => env('BACKUP_DB_CONNECTION', env('DB_CONNECTION', 'pgsql')),
        'pg_dump' => env('BACKUP_PG_DUMP', 'pg_dump'),
        'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 30),
        'time' => env('BACKUP_TIME', '02:00'),
    ],

];
