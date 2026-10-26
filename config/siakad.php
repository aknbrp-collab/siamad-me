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
        'receipt.footer' => null,
        'attendance.min_percent' => 75,
    ],

];
