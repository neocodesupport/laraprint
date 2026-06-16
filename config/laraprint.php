<?php

declare(strict_types=1);

/**
 * Configuration par défaut du SDK Laraprint.
 * Vous pouvez publier ce fichier (php artisan vendor:publish --tag=laraprint-config)
 * et l'adapter à votre application.
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Type de connexion par défaut (network, windows, cups, smb, file, usb)
    |--------------------------------------------------------------------------
    */
    'connection_type' => env('LARAPRINT_CONNECTION_TYPE', 'network'),

    /*
    |--------------------------------------------------------------------------
    | Poste (ordinateur) courant
    |--------------------------------------------------------------------------
    | L'imprimante par défaut est liée à une machine donnée (poste). Par défaut,
    | la machine est identifiée par son nom d'hôte système. Vous pouvez forcer un
    | identifiant (utile en environnement conteneurisé ou multi-postes).
    */
    'workstation' => [
        'identifier' => env('LARAPRINT_WORKSTATION', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Paramètres de connexion (selon le type)
    |--------------------------------------------------------------------------
    */
    'connection' => [
        'network' => [
            'ip' => env('LARAPRINT_NETWORK_IP', '192.168.1.11'),
            'port' => (int) env('LARAPRINT_NETWORK_PORT', 9100),
            'timeout' => (int) env('LARAPRINT_NETWORK_TIMEOUT', 5),
        ],
        'windows' => [
            'printer_name' => env('LARAPRINT_WINDOWS_PRINTER', 'EPSON TM-T20II Receipt'),
        ],
        'cups' => [
            'cups_name' => env('LARAPRINT_CUPS_NAME', 'POS-Printer'),
        ],
        'smb' => [
            // SMB passe par une file CUPS configurée pour le partage.
            'cups_name' => env('LARAPRINT_SMB_CUPS_NAME', 'POS-Printer'),
        ],
        'usb' => [
            // Périphérique brut (ex. /dev/usb/lp0, COM3).
            'path' => env('LARAPRINT_USB_PATH', '/dev/usb/lp0'),
        ],
        'file' => [
            'path' => env('LARAPRINT_FILE_PATH', storage_path('app/receipts/receipt.txt')),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Configuration ticket (entreprise, mise en page, devise, messages)
    |--------------------------------------------------------------------------
    */
    'receipt' => [
        'company' => [
            'name' => env('LARAPRINT_COMPANY_NAME', 'MEDSOFT'),
            'subtitle' => env('LARAPRINT_COMPANY_SUBTITLE', ''),
            'address' => env('LARAPRINT_COMPANY_ADDRESS', ''),
            'phone' => env('LARAPRINT_COMPANY_PHONE', ''),
            'email' => env('LARAPRINT_COMPANY_EMAIL', ''),
            'website' => env('LARAPRINT_COMPANY_WEBSITE', 'www.example.com'),
        ],
        'layout' => [
            'header_size' => 2,
            'item_name_size' => 1,
            'total_size' => 2,
            'separator_char' => '-',
            'separator_length' => 32,
        ],
        'currency' => [
            'symbol' => env('LARAPRINT_CURRENCY_SYMBOL', 'FCFA'),
            'position' => 'after',
            'decimals' => 0,
            'thousands_separator' => ' ',
            'decimal_separator' => ',',
        ],
        'messages' => [
            'thank_you' => 'Merci pour votre visite !',
            'keep_receipt' => 'Conservez ce ticket',
        ],
        'qr_code' => [
            'enabled' => true,
            'size' => 3,
        ],
    ],
];
