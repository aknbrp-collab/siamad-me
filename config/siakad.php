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
        'institution.short_name' => null,
        'institution.foundation' => null,
        'institution.address' => null,
        'institution.city' => null,
        'institution.phone' => null,
        'institution.email' => null,
        'institution.website' => null,
        // Operating licence (SK izin operasional), printed on the letterhead.
        'institution.licence' => null,
        // The head of the institution (Mudir), used by letters.
        'institution.head' => null,
        'payment.max_installments' => 3,
        'payment.min_installment_amount' => 200000,
        // Shown to mahasantri and wali santri who pay by transfer, e.g. "BSI 7123456789 a.n. Yayasan …".
        'payment.bank_accounts' => null,
        'receipt.footer' => null,
        'attendance.min_percent' => 75,
    ],

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    |
    | One size limit (in kilobytes) for every uploaded file. PHP must allow
    | it: upload_max_filesize and post_max_size in php.ini need to be larger,
    | or the file is refused before SIAKAD sees it.
    |
    */

    'uploads' => [
        'max_kb' => (int) env('SIAKAD_UPLOAD_MAX_KB', 10240),
    ],

    /*
    |--------------------------------------------------------------------------
    | LibreOffice
    |--------------------------------------------------------------------------
    |
    | Turns letters made from a Word template into PDF. Leave empty to look in
    | the usual install places; without LibreOffice those letters are given
    | out as Word files.
    |
    */

    'libreoffice' => env('SIAKAD_LIBREOFFICE'),

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

    /*
    |--------------------------------------------------------------------------
    | Web Push (notifications that pop up outside the app)
    |--------------------------------------------------------------------------
    |
    | Generate the key pair once with `php artisan siakad:vapid` and keep it:
    | a new pair silently breaks every device that already turned pop-ups on.
    | Browsers allow push only over HTTPS (or on localhost). Without keys,
    | notifications stay in the app only.
    |
    */

    'push' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        // A mailto: or https: address the push services can contact about problems.
        'subject' => env('VAPID_SUBJECT', 'mailto:admin@example.com'),
    ],

];
