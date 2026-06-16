# Laraprint

[![Packagist Version](https://img.shields.io/packagist/v/neocode/laraprint.svg?style=flat-square)](https://packagist.org/packages/neocode/laraprint)
[![Total Downloads](https://img.shields.io/packagist/dt/neocode/laraprint.svg?style=flat-square)](https://packagist.org/packages/neocode/laraprint)
[![PHP Version](https://img.shields.io/packagist/php-v/neocode/laraprint.svg?style=flat-square)](https://packagist.org/packages/neocode/laraprint)
[![Laravel](https://img.shields.io/badge/Laravel-11%20%7C%2012%20%7C%2013-FF2D20.svg?style=flat-square&logo=laravel)](https://laravel.com)
[![Tests](https://img.shields.io/github/actions/workflow/status/neocodesupport/laraprint/tests.yml?branch=main&style=flat-square&label=tests&logo=github)](https://github.com/neocodesupport/laraprint/actions/workflows/tests.yml)
[![License](https://img.shields.io/packagist/l/neocode/laraprint.svg?style=flat-square)](LICENSE)

🇫🇷 [Version française](README.fr.md)

> **Print-to-any-printer** SDK for **Laravel 11+** (compatible with Laravel 12 and 13).

Laraprint lets you **pick the printer** (network, Windows, CUPS, SMB, USB, file) and **print directly** to it: receipts, labels, reports, office documents (PDF/Word/Excel), raw ESC/POS dumps… The SDK is **not limited to POS**: use it whenever you need to send content to a given printer.

- 🖨️ **Direct printing** — text, raw data, or ESC/POS commands to the printer of your choice.
- 🧾 **Receipts** — built-in "cash receipt" formatting (optional, fully configurable).
- 🗂️ **Files** — send raw ESC/POS files, or delegate to the OS spooler for PDF/Word/Excel.
- 🔎 **Discovery** — list the printers installed on the machine (Windows / Linux / macOS).
- 🗄️ **Management** — store printers in the database, choose before printing, **default printer per machine and per session**.
- 📡 **Observability** — Laravel events and logs around every job (optional).
- 🧩 **No views, no coupling** — logic only; works with a plain config array, even outside a full Laravel app.

---

## Table of contents

1. [Requirements](#requirements)
2. [Installation](#installation)
3. [Quick start](#quick-start)
4. [Core concepts](#core-concepts)
5. [Configuration](#configuration)
6. [Connection types](#connection-types)
7. [Direct printing (`DirectPrinter`)](#direct-printing-directprinter)
8. [Printing a file](#printing-a-file)
9. [Receipts (`ThermalPrinter`)](#receipts-thermalprinter)
10. [Discovering machine printers](#discovering-machine-printers)
11. [Printer management (`PrinterRegistry`)](#printer-management-printerregistry)
12. [Default printer: machine + session](#default-printer-machine--session)
13. [Eloquent models](#eloquent-models)
14. [`Laraprint` facade reference](#laraprint-facade-reference)
15. [Paper sizes (`PaperSize`)](#paper-sizes-papersize)
16. [Events & logs](#events--logs)
17. [Recipes & examples](#recipes--examples)
18. [Troubleshooting](#troubleshooting)
19. [Tests & quality](#tests--quality)
20. [SDK structure](#sdk-structure)
21. [License](#license)

---

## Requirements

| Component | Version |
| --- | --- |
| PHP | **8.3+** |
| Laravel | **11, 12 or 13** |
| [`mike42/escpos-php`](https://github.com/mike42/escpos-php) | `^2.2` (installed automatically) |

Depending on the printers you use:

- **Network (port 9100)**: no OS dependency, works everywhere.
- **Windows**: the PHP app must run **on Windows** (uses PowerShell `Get-Printer`, `Set-Printer`, `Start-Process -Verb Print`).
- **CUPS (Linux/macOS)**: `lp` / `lpstat` binaries available.
- **SMB**: configured through a **CUPS** queue pointing at the share (no native SMB wrapper in PHP).

---

## Installation

```bash
composer require neocode/laraprint
```

The `LaraprintServiceProvider` is **registered automatically** (package auto-discovery). It:

- merges the default configuration (`config('laraprint')`);
- registers the `PrinterRegistry` singleton in the container;
- registers the global **`\Laraprint`** alias (so `\Laraprint::printer(...)` works anywhere);
- exposes the publishing tags.

### Publish the configuration

```bash
php artisan vendor:publish --tag=laraprint-config
```

→ creates `config/laraprint.php`.

### Publish the migrations (optional)

Required **only** if you want to **persist your printers in the database** (management, default printer, encrypted credentials) via the `PrinterRegistry`:

```bash
php artisan vendor:publish --tag=laraprint-migrations
php artisan migrate
```

→ creates the `workstations`, `printers`, `printer_credentials` tables.

> 💡 If you provide printers via a **config array** (or from your own model), the migrations are **optional**.

---

## Quick start

```php
use Neocode\Laraprint\Laraprint;

// 1) Describe the target printer
$config = [
    'connection_type' => 'network',
    'settings' => ['ip' => '192.168.1.20', 'port' => 9100],
];

// 2) Print text
Laraprint::printer($config)
    ->printText("Hello!\n")
    ->feed(2)
    ->cut()
    ->close();

// 3) Print a formatted receipt
Laraprint::thermalPrinter($config, config('laraprint.receipt'))
    ->printReceipt([
        'sale_number' => 'SALE-001',
        'sold_at'     => now(),
        'items'       => [['item_name' => 'Coffee', 'quantity' => 1, 'unit_price' => 1000, 'total_amount' => 1000]],
        'subtotal'    => 1000,
        'total_amount'=> 1000,
        'payments'    => [['type' => 'cash', 'amount' => 1000]],
    ]);
```

---

## Core concepts

### `connection_type` — *how* to reach the printer

The **physical channel**: `network`, `windows`, `cups`, `smb`, `file` / `usb`. It determines the **connector** that is created.

### `printer_type` — *which printing strategy*

The **logical type** (enum `Neocode\Laraprint\Support\PrinterType`), which determines **how** a file is sent:

| Value | Constant | Use |
| --- | --- | --- |
| `thermal_escpos_raw` | `PrinterType::ThermalEscposRaw` | **Raw** ESC/POS byte stream (thermal printers, port 9100, device). |
| `windows_spool_document` | `PrinterType::WindowsSpoolDocument` | Delegates to the **Windows spooler** via driver (PDF/Word/Excel). |
| `cups_spool_document` | `PrinterType::CupsSpoolDocument` | Delegates to the **CUPS spooler** (`lp`), conversion per drivers. |

If `printer_type` is not provided, it is **inferred** from `connection_type`:
`windows → windows_spool_document`, `cups`/`smb` → `cups_spool_document`, otherwise `thermal_escpos_raw`.

### `settings` — channel parameters

An array depending on `connection_type` (IP/port, Windows printer name, CUPS name, file path…). See [Connection types](#connection-types).

### The config array (`$config`)

Almost the entire API consumes **the same array**:

```php
$config = [
    'connection_type' => 'network',          // required ('type' alias accepted)
    'settings'        => ['ip' => '...', 'port' => 9100],
    'name'            => 'Register 1',        // optional (label)
    'printer_type'    => 'thermal_escpos_raw',// optional (string or PrinterType)
    'is_active'       => true,                // optional (default true)
];
```

> A printer with `is_active = false` throws an exception on connection: handy for disabling without deleting.

---

## Configuration

File `config/laraprint.php` (after publishing). Every value can be driven by environment variables.

```php
return [
    // Default connection type (network, windows, cups, smb, file, usb)
    'connection_type' => env('LARAPRINT_CONNECTION_TYPE', 'network'),

    // Current workstation (computer) — see "Default printer: machine + session"
    'workstation' => [
        'identifier' => env('LARAPRINT_WORKSTATION', null), // default: system hostname
    ],

    // Connection parameters per type
    'connection' => [
        'network' => [
            'ip'      => env('LARAPRINT_NETWORK_IP', '192.168.1.11'),
            'port'    => (int) env('LARAPRINT_NETWORK_PORT', 9100),
            'timeout' => (int) env('LARAPRINT_NETWORK_TIMEOUT', 5),
        ],
        'windows' => ['printer_name' => env('LARAPRINT_WINDOWS_PRINTER', 'EPSON TM-T20II Receipt')],
        'cups'    => ['cups_name' => env('LARAPRINT_CUPS_NAME', 'POS-Printer')],
        'file'    => ['path' => env('LARAPRINT_FILE_PATH', storage_path('app/receipts/receipt.txt'))],
    ],

    // Receipt configuration (ThermalPrinter)
    'receipt' => [
        'company'  => [
            'name'     => env('LARAPRINT_COMPANY_NAME', 'MEDSOFT'),
            'subtitle' => env('LARAPRINT_COMPANY_SUBTITLE', ''),
            'address'  => env('LARAPRINT_COMPANY_ADDRESS', ''),
            'phone'    => env('LARAPRINT_COMPANY_PHONE', ''),
            'email'    => env('LARAPRINT_COMPANY_EMAIL', ''),
            'website'  => env('LARAPRINT_COMPANY_WEBSITE', 'www.example.com'),
        ],
        'layout'   => [
            'header_size'      => 2,   // title size (1-8)
            'item_name_size'   => 1,   // item name size
            'total_size'       => 2,   // total size
            'separator_char'   => '-', // separator character
            'separator_length' => 32,  // width (32 ≈ 58mm, 48 ≈ 80mm)
        ],
        'currency' => [
            'symbol'              => env('LARAPRINT_CURRENCY_SYMBOL', 'FCFA'),
            'position'            => 'after', // 'before' or 'after'
            'decimals'            => 0,
            'thousands_separator' => ' ',
            'decimal_separator'   => ',',
        ],
        'messages' => [
            'thank_you'    => 'Merci pour votre visite !',
            'keep_receipt' => 'Conservez ce ticket',
        ],
        'qr_code'  => ['enabled' => true, 'size' => 3],
    ],
];
```

### Environment variables

| Variable | Purpose | Default |
| --- | --- | --- |
| `LARAPRINT_CONNECTION_TYPE` | Default connection type | `network` |
| `LARAPRINT_WORKSTATION` | Forced machine identifier (otherwise hostname) | `null` |
| `LARAPRINT_NETWORK_IP` / `_PORT` / `_TIMEOUT` | Network | `192.168.1.11` / `9100` / `5` |
| `LARAPRINT_WINDOWS_PRINTER` | Windows printer name | `EPSON TM-T20II Receipt` |
| `LARAPRINT_CUPS_NAME` | CUPS queue name | `POS-Printer` |
| `LARAPRINT_FILE_PATH` | Path for the file connector | `storage/app/receipts/receipt.txt` |
| `LARAPRINT_COMPANY_*` | Receipt company header | — |
| `LARAPRINT_CURRENCY_SYMBOL` | Currency symbol | `FCFA` |

---

## Connection types

| `connection_type` | required `settings` | Notes |
| --- | --- | --- |
| `network` | `ip` *(required)*, `port` (default `9100`), `timeout` (default `5`) | Raw TCP. Works on all OSes. |
| `windows` | `printer_name` *(required)* | **Windows only.** Throws on other OSes. |
| `cups` | `cups_name` *(required)* | Linux/macOS. |
| `smb` | `cups_name` *(required)* | Goes through a CUPS queue (configure the SMB share in CUPS). |
| `file` / `usb` | `path` or `device_path` *(required)* | Writes to a file or device (`/dev/usb/lp0`, `COM3`, etc.). |

Create a low-level ESC/POS connector (rarely needed — prefer `DirectPrinter`/`ThermalPrinter`):

```php
use Neocode\Laraprint\Connector\ConnectorFactory;
use Neocode\Laraprint\Connector\PrinterConnectionConfig;

$connector = ConnectorFactory::fromArray($config);

// Or via the immutable DTO
$dto = PrinterConnectionConfig::fromArray($config);
$connector = ConnectorFactory::fromConfig($dto);
```

---

## Direct printing (`DirectPrinter`)

To send text, raw bytes, or ESC/POS commands to **any** printer.

```php
use Neocode\Laraprint\DirectPrinter; // or Laraprint::printer($config)

$printer = DirectPrinter::forPrinter($config);

$printer
    ->printText("Document #12345\n")
    ->printText('Date: '.date('d/m/Y H:i')."\n")
    ->feed(2)
    ->cut()
    ->close();
```

### `DirectPrinter` API

| Method | Description |
| --- | --- |
| `DirectPrinter::forPrinter(array $config): self` | Create an instance for the target printer. |
| `printText(string $text): self` | Send text (UTF-8). |
| `printRaw(string $data): self` | Send raw bytes (custom ESC/POS commands, specific protocols). |
| `printFile(string $path, bool $asText = false, ?PrinterType $type = null): self` | Send a file (see below). |
| `printFileAndClose(string $path, bool $asText = false): bool` | Send a file then close. |
| `printTextAndClose(string $text): bool` | Send text then close. |
| `feed(int $lines = 1): self` | Line feed. |
| `cut(int $mode = CUT_FULL, int $lines = 3): self` | Cut the paper (thermal printers). |
| `openCashDrawer(int $pin = 0): self` | Open the cash drawer (ESC/POS pulse). |
| `queryStatus(): PrinterStatus` | Query real-time status (best-effort; network/device). |
| `getEscposPrinter(): \Mike42\Escpos\Printer` | Full access to the ESC/POS API (barcodes, images, QR…). |
| `testConnection(): bool` | Test open/close without printing. |
| `close(): void` | Close the connection (idempotent; also called on `__destruct`). |

> ⚠️ A `DirectPrinter` instance is **single-use**: after `close()`, create a new one to print again.

### Advanced ESC/POS control

```php
$p = DirectPrinter::forPrinter($config);
$escpos = $p->getEscposPrinter();

$escpos->setJustification(\Mike42\Escpos\Printer::JUSTIFY_CENTER);
$escpos->setEmphasis(true);
$escpos->text("MY HEADER\n");
$escpos->setEmphasis(false);
$escpos->barcode('123456789');
$escpos->qrCode('https://example.com');

$p->cut()->close();
```

---

### Cash drawer & status

```php
// Open the cash drawer wired to the printer
Laraprint::openCashDrawer($config);              // or DirectPrinter::forPrinter($config)->openCashDrawer()->close()

// Query real-time status (best-effort; works over network / device connectors)
$status = Laraprint::printerStatus($config);
$status->online;        // ?bool
$status->paperOut;      // ?bool
$status->coverOpen;     // ?bool
$status->paperNearEnd;  // ?bool
$status->isReady();     // online, paper present, cover closed
```

`null` fields mean "unknown" (no response from the printer).

## Printing a file

Three approaches, from simplest to lowest level.

### a) `Laraprint::printFile()` — automatic strategy *(recommended)*

```php
use Neocode\Laraprint\Laraprint;
use Neocode\Laraprint\Support\PrinterType;

// Receipt / ESC/POS dump (UTF-8 text)
Laraprint::printFile($path, $config, asText: true, printerType: PrinterType::ThermalEscposRaw);

// PDF / Word / Excel via the Windows driver
Laraprint::printFile('C:\\docs\\invoice.pdf', [
    'connection_type' => 'windows',
    'settings' => ['printer_name' => 'HP LaserJet'],
], printerType: PrinterType::WindowsSpoolDocument);
```

If `printerType` is omitted, it is inferred from `connection_type` (and from the array's `printer_type` if present).

### b) `DirectPrinter::printFile()` — for the ESC/POS stream

```php
// Raw ESC/POS binary file (.bin, raw dump) — read in 64 KB chunks
DirectPrinter::forPrinter($config)->printFile(storage_path('app/tickets/job.bin'))->feed(2)->cut()->close();

// Text file interpreted as a receipt
DirectPrinter::forPrinter($config)->printFile('/path/document.txt', asText: true)->close();

// Send + close shortcut
DirectPrinter::forPrinter($config)->printFileAndClose($path);
```

> 🚫 In raw ESC/POS mode, trying to print an **office** file (`pdf`, `png`, `docx`, `xlsx`…) throws an explicit exception: use a `windows`/`cups` (spooler) printer.

### c) `Laraprint::spoolFile()` / `SpooledFilePrint::submit()` — OS spooler

To delegate conversion to drivers (PDF, images, documents):

```php
use Neocode\Laraprint\Laraprint;
use Neocode\Laraprint\Printing\SpooledFilePrint;

// CUPS (Linux/macOS) — lp -d <queue> <file>
Laraprint::spoolFile('/path/ticket.pdf', [
    'connection_type' => 'cups',
    'settings' => ['cups_name' => 'EPSON_TM-T20'],
]);

// Windows — temporarily switches the default printer, then Start-Process -Verb Print
SpooledFilePrint::submit('C:\\path\\ticket.pdf', [
    'connection_type' => 'windows',
    'settings' => ['printer_name' => 'Your Windows printer name'],
]);
```

---

## Receipts (`ThermalPrinter`)

`ThermalPrinter` formats a full **cash receipt**: company header, sale info, items, totals, taxes, payments, footer and **QR code**.

### Create the printer

```php
use Neocode\Laraprint\Thermal\ThermalPrinter;

$printer = ThermalPrinter::fromConnectionConfig($config, config('laraprint.receipt'));
// config('laraprint.receipt') can be replaced by an array or a ReceiptConfig
```

### Print a receipt

```php
$printer->printReceipt([
    'sale_number'        => 'SALE202501001',
    'sold_at'            => now(),               // DateTimeInterface or string
    'cashier_name'       => 'John Doe',
    'cash_register_name' => 'Register 1',
    'patient_name'       => 'Mary Smith',        // optional "patient" fields (healthcare context)
    'patient_phone'      => '+225 0700000000',
    'items' => [
        [
            'item_name'        => 'Paracetamol 500mg',
            'item_code'        => 'PAR500',      // optional
            'item_description' => '',            // optional
            'quantity'         => 2,
            'unit_price'       => 500,
            'discount_amount'  => 0,             // optional
            'tax_amount'       => 0,             // optional
            'total_amount'     => 1000,
        ],
    ],
    'subtotal'        => 1000,
    'discount_amount' => 0,
    'tax_amount'      => 0,
    'total_amount'    => 1000,
    'payments' => [
        ['type' => 'cash', 'type_label' => 'Cash', 'amount' => 1000,
         'cash_received' => 2000, 'change_amount' => 1000, 'reference' => null],
    ],
    'taxes_grouped' => [
        // ['name' => 'VAT', 'rate' => 18, 'amount' => 180],
    ],
]);
```

Payment labels auto-resolved when `type_label` is missing: `cash`, `card`, `mobile_money`, `orange_money`, `wave`, `mtn_money`, `moov_money`, `bank_transfer`, `tpe`, `insurance`, `check`, `mixed`.

### Test receipt & connection test

```php
$printer->printTestReceipt(); // prints a small verification receipt
$ok = (new ThermalPrinter(...))->testConnection(); // true/false without printing
```

### Customize formatting (`ReceiptConfig`)

```php
use Neocode\Laraprint\Support\ReceiptConfig;

$cfg = ReceiptConfig::fromArray([
    'company'  => ['name' => 'MY PHARMACY', 'phone' => '+225 0700000000'],
    'layout'   => ['separator_length' => 48], // 80mm
    'currency' => ['symbol' => '€', 'position' => 'before', 'decimals' => 2],
    'qr_code'  => ['enabled' => true, 'size' => 4],
]);

$cfg->formatCurrency(1234.5); // "€ 1 234,50"
$printer = ThermalPrinter::fromConnectionConfig($config, $cfg);
```

---

## Receipt builder (fluent)

Compose a receipt without dropping to the raw ESC/POS API:

```php
Laraprint::build($config)
    ->center()->bold()->size(2, 2)->line('MY SHOP')->bold(false)->size(1, 1)
    ->rule()
    ->left()->line('Item A           1 000')
    ->rule()
    ->qr('https://example.com/receipt/42')
    ->image(public_path('logo.png'))   // optional
    ->feed(2)->cut()
    ->print();
```

Methods: `left/center/right`, `bold`, `underline`, `size`, `text`, `line`, `rule`, `feed`,
`barcode`, `qr`, `image`, `drawer`, `cut`, `print`, and `escpos()` for full control.

## Labels (ZPL / Zebra)

Generate and send **ZPL** labels to Zebra printers (raw bytes, no ESC/POS init):

```php
use Neocode\Laraprint\Label\ZplBuilder;

ZplBuilder::make()
    ->box(20, 20, 760, 380, 3)
    ->text(50, 50, 'Product A', size: 40)
    ->barcode(50, 140, '123456789')
    ->qr(560, 50, 'https://example.com')
    ->print($config);                 // network 9100 or device

$zpl = ZplBuilder::make()->text(0, 0, 'Hi')->toZpl();   // raw ZPL string
```

For any non-ESC/POS protocol, send raw bytes directly:

```php
Laraprint::sendRaw($config, $rawBytes);   // no ESC/POS initialization is prepended
```

## Discovering machine printers

Lists the printers **installed on the machine** where the application runs.

```php
use Neocode\Laraprint\Laraprint; // or Neocode\Laraprint\Discovery\SystemPrinters

$printers = Laraprint::listLocalPrinters();
// [
//   ['connection_type' => 'windows', 'settings' => ['printer_name' => 'EPSON TM-T20II Receipt'],
//    'name' => 'EPSON TM-T20II Receipt', 'printer_type' => 'thermal_escpos_raw'],
//   ...
// ]

foreach ($printers as $cfg) {
    $printer = Laraprint::printer($cfg);
    // ...
}
```

- **Windows**: via PowerShell `Get-Printer` (falls back to `wmic`).
- **Linux/macOS**: via CUPS `lpstat -a` (falls back to `lpstat -p`).
- The `printer_type` is **guessed** from the name (heuristic: `receipt`, `pos`, `ticket`, `TM…` → thermal).

### USB / locally-attached discovery

Detect printers physically connected to the machine:

```php
$usb = Laraprint::listUsbPrinters();
// Windows: printers on USB*/DOT4* ports (windows connector)
// Linux/macOS: /dev/usb/lp*, /dev/lp* (file connector) + CUPS usb:// devices
```

### Network discovery (scan)

Probe the local network for printers listening on a print port (9100 by default):

```php
// Auto-detect the local /24 and scan port 9100
$found = Laraprint::scanNetworkPrinters();

// Explicit range and ports
$found = Laraprint::scanNetworkPrinters('192.168.1.0/24', ports: [9100, 515], timeout: 0.3);
$found = Laraprint::scanNetworkPrinters('192.168.1.10-50');
```

Ranges accept **CIDR** (`192.168.1.0/24`), **intervals** (`192.168.1.10-50` or full `…-192.168.1.50`),
or a **single IP**. Connections run in parallel (non-blocking sockets), so a `/24` scans in seconds.
Ranges larger than 4096 addresses are rejected to avoid accidental massive scans.

### AirPrint discovery (mDNS / Bonjour)

Discover printers advertised on the local network via multicast DNS (AirPrint):

```php
$airprint = Laraprint::discoverAirPrint(timeout: 2.0);
// Queries _pdl-datastream._tcp (9100), _printer._tcp (LPD), _ipp._tcp and
// correlates PTR/SRV/A records into ready-to-use configs.
```

> Requires the PHP `sockets` extension. Without it (or with no response), returns `[]`.

### Printer info via SNMP

Query a network printer's model, status, page count and supply levels (Printer MIB):

```php
$info = Laraprint::snmp('192.168.1.50');           // community 'public' by default
// ['model' => 'EPSON…', 'status' => '3', 'page_count' => '10421',
//  'supply_level' => '80', 'supply_max' => '100', 'supply_percent' => 80]
```

> Requires the PHP `snmp` extension. Without it, returns `[]`.

### IPP / AirPrint printing

Print a document over IPP (port 631) — for real AirPrint/IPP printers, beyond raw 9100:

```php
$pdf = file_get_contents(storage_path('app/invoice.pdf'));

Laraprint::printIpp('ipp://192.168.1.50:631/ipp/print', $pdf, 'application/pdf');
// returns true on a "successful" IPP status
```

### Combined discovery & import

```php
$all = Laraprint::discoverPrinters(network: true, airprint: true);   // system + USB + network + AirPrint

// Persist newly discovered printers (deduplicated by name)
$registry = Laraprint::printers();
$registry->importSystemPrinters();
$registry->importUsbPrinters();
$registry->importNetworkPrinters('192.168.1.0/24');
$registry->importAirPrintPrinters();
```

From the CLI:

```bash
php artisan laraprint:printers scan                 # show system + USB printers
php artisan laraprint:printers scan --network       # also scan the local network
php artisan laraprint:printers scan --mdns          # also discover AirPrint printers
php artisan laraprint:printers import --usb         # persist USB printers
php artisan laraprint:printers import --network --range=192.168.1.0/24
php artisan laraprint:printers import --mdns        # persist AirPrint printers
```

---

## Printer management (`PrinterRegistry`)

When your printers are **stored in the database** (migrations published), the `PrinterRegistry` covers the whole cycle: **list → choose → add → import → set default → print**.

```php
use Neocode\Laraprint\Laraprint;

$registry = Laraprint::printers(); // or app(\Neocode\Laraprint\Printers\PrinterRegistry::class)
```

### Add a printer

```php
$register = $registry->register([
    'name'            => 'Register 1',
    'connection_type' => 'network',
    'printer_type'    => 'thermal_escpos_raw',
    'settings'        => ['ip' => '192.168.1.20', 'port' => 9100],
    'is_default'      => true, // optional: becomes default on creation
]);

// With credentials (password encrypted automatically via Crypt)
$registry->register([
    'name'            => 'HP LaserJet Office',
    'connection_type' => 'windows',
    'printer_type'    => 'windows_spool_document',
    'settings'        => ['printer_name' => 'HP LaserJet'],
], credentials: ['username' => 'station', 'password' => 'secret', 'domain' => 'WORKGROUP']);
```

### Auto-import machine printers

```php
$added = $registry->importSystemPrinters();          // global
$added = $registry->importSystemPrinters($stationId); // attached to a workstation
// Only adds those whose name does not exist yet.
```

### List & choose

```php
$registry->all();              // all (sorted by name)
$registry->active();           // active only
$registry->find($id);          // by id (or null)
$registry->findByName('Register 1');

foreach ($registry->active() as $p) {
    echo "{$p->id} — {$p->name}".($p->is_default ? ' (default)' : '').PHP_EOL;
}
```

### Choose the printer **before** printing

`printer()` / `thermalPrinter()` accept an **instance**, an **id**, a **name**, or `null` (= contextual default):

```php
// Ready-to-use DirectPrinter
$registry->printer($register->id)->printText("Hello\n")->cut()->close();
$registry->printer('Register 1')->printTextAndClose("By name\n");
$registry->printer()->printTextAndClose("On default\n");

// Receipt on the chosen printer
$registry->thermalPrinter($register->id, config('laraprint.receipt'))->printReceipt($data);

// Just the config (to pass elsewhere)
$config = $registry->connectionConfig($register->id);
```

### Set / remove

```php
$registry->setDefault($register->id); // uniqueness guaranteed within the same scope
$registry->default();                 // active default printer (or null)
$registry->forget($id);               // deletes the record
```

### Facade shortcuts

`Laraprint::registerPrinter()`, `Laraprint::setDefaultPrinter()`, `Laraprint::defaultPrinter()`, `Laraprint::usePrinter()`.

### Artisan command

Manage printers from the terminal (requires the published migrations):

```bash
php artisan laraprint:printers list           # list registered printers
php artisan laraprint:printers add \
    --name="Register 1" --type=network \
    --setting=ip=192.168.1.20 --setting=port=9100 \
    --printer-type=thermal_escpos_raw --default
php artisan laraprint:printers default 1 --machine  # set default for the current machine
php artisan laraprint:printers import         # import the machine's printers
php artisan laraprint:printers test 1         # print a test receipt (id, name, or default)
php artisan laraprint:printers remove 1
```

---

## Default printer: machine + session

The default printer is **bound to a given machine** (workstation / computer): each workstation can have **its own** default printer. The current machine is identified by:

1. the **forced** identifier (`config('laraprint.workstation.identifier')` / `LARAPRINT_WORKSTATION`);
2. otherwise the system **hostname** (`gethostname()`);
3. a workstation can also be **set in session** (multiple stations behind one server).

```php
$registry = Laraprint::printers();

// Default printer FOR THIS COMPUTER (falls back to the global default if the station has none)
$registry->defaultForCurrent();              // or Laraprint::defaultPrinter()

// Set the default FOR THIS COMPUTER:
// the printer is attached to the current workstation (created from the hostname if needed), then marked default.
$registry->setDefaultForCurrent($register->id); // or Laraprint::setMachineDefaultPrinter($id)

// Resolved current workstation
$registry->currentWorkstation();              // or Laraprint::currentWorkstation()

// Choose a printer for the SESSION (overrides the machine default)
$registry->selectForSession($other->id);      // or Laraprint::selectPrinterForSession($id)
$registry->clearSessionSelection();           // go back to the machine default

// Print "on the current default"
$registry->printer()->printTextAndClose("Ticket\n");
```

**Resolution order** when no printer is passed explicitly to `printer()` / `resolve()`:

1. printer chosen for the current **session**;
2. **machine** (workstation) **default** printer;
3. **global default** printer.

`default($workstationId)` targets a specific workstation, and `setDefault()` only clears the previous default **within the same scope** (same `workstation_id`). So changing workstation A's default never affects workstation B.

> 🧭 **Typical case** — A Laravel server serves several registers: each register sets its workstation in session (`useWorkstation`) on login, then `printer()` always prints to the correct local printer with no argument.

---

## Eloquent models

Available after publishing the migrations. They remain **optional**: the SDK also works with arrays.

### `Workstation` (station / computer)

| Field | Type |
| --- | --- |
| `name` | string |
| `hostname` | string, unique, nullable (machine identity) |
| `ip_address` | string, unique |
| `location`, `is_active` | string nullable / bool |

Relations & helpers: `printers()`, `defaultPrinter()`, `getActivePrinters()`, `getDefaultPrinter()`, `getByIp($ip)`, scopes `active()`, `byIp()`, `byHostname()`.

### `Printer`

| Field | Type |
| --- | --- |
| `workstation_id` | nullable FK |
| `name` | string |
| `connection_type` | `network`/`windows`/`cups`/`smb`/`usb`/`file` |
| `printer_type` | nullable string |
| `model`, `is_default`, `is_active`, `settings` | string / bool / bool / json |

Methods: `getConnectionConfig()` (returns an **SDK-ready** config), `makeDefault()` (exclusive default per scope), scopes `active()`, `byType()`, `default()`, `forWorkstation()`, relations `workstation()`, `credentials()`.

### `PrinterCredential`

`username`, `password` (**encrypted** via `Crypt`, hidden from serialization), `domain`. Relation `printer()`.

```php
use Neocode\Laraprint\Models\Printer;

$config = Printer::query()->active()->default()->first()?->getConnectionConfig();
Laraprint::printer($config)->printTextAndClose("OK\n");
```

---

## `Laraprint` facade reference

| Method | Returns | Purpose |
| --- | --- | --- |
| `printer(array $config)` | `DirectPrinter` | Direct printing. |
| `build(array $config)` | `ReceiptBuilder` | Fluent receipt builder. |
| `sendRaw(array $config, string $data)` | `void` | Send raw bytes (no ESC/POS init). |
| `fake()` | `PrintRecorder` | Capture prints for testing. |
| `openCashDrawer(array $config, int $pin = 0)` | `void` | Open the cash drawer. |
| `printerStatus(array $config)` | `PrinterStatus` | Query real-time status. |
| `snmp(string $host, string $community = 'public')` | `array` | Printer info via SNMP. |
| `printIpp(string $uri, string $data, string $format)` | `bool` | Print over IPP / AirPrint. |
| `thermalPrinter(array $config, array\|ReceiptConfig $receipt)` | `ThermalPrinter` | Cash receipt. |
| `connector(array $config)` | ESC/POS connector | Low-level connector. |
| `connectionConfig(array $data)` | `PrinterConnectionConfig` | Connection DTO. |
| `receiptConfig(array $data)` | `ReceiptConfig` | Receipt config DTO. |
| `receiptData(array $data)` | `ReceiptData` | Receipt data DTO. |
| `listLocalPrinters()` | `array` | Machine printers (OS). |
| `listUsbPrinters()` | `array` | USB / locally-attached printers. |
| `scanNetworkPrinters(?string $range, array $ports, float $timeout)` | `array` | Network printer scan. |
| `discoverAirPrint(float $timeout = 2.0)` | `array` | mDNS / Bonjour (AirPrint) discovery. |
| `discoverPrinters(bool $network, ?string $range, bool $airprint)` | `array` | System + USB (+ network + AirPrint). |
| `queueText(array $config, string $text, bool $cut = true)` | dispatch | Queue a text print. |
| `queueFile(array $config, string $path, bool $asText = false)` | dispatch | Queue a file print. |
| `queueReceipt(array $config, array $data, ?array $receiptConfig = null)` | dispatch | Queue a receipt print. |
| `spoolFile(string $path, array $config)` | `void` | Submit via OS spooler. |
| `printFile(string $path, array $config, bool $asText = false, ?PrinterType $type = null)` | `void` | File printing (auto strategy). |
| `printers()` | `PrinterRegistry` | Database printer registry. |
| `registerPrinter(array $attrs, ?array $credentials = null)` | `Printer` | Add a printer. |
| `setDefaultPrinter(Printer\|int $p)` | `Printer` | Default (generic). |
| `defaultPrinter(?int $workstationId = null)` | `?Printer` | Machine default (or given station). |
| `setMachineDefaultPrinter(Printer\|int $p)` | `Printer` | Default **for the current machine**. |
| `currentWorkstation()` | `?Workstation` | Current workstation. |
| `selectPrinterForSession(Printer\|int\|string $p)` | `Printer` | Session selection. |
| `usePrinter(Printer\|int\|string\|null $p = null)` | `DirectPrinter` | Chosen registered printer. |

---

## Paper sizes (`PaperSize`)

Utility for your PDF generation/previews (outside direct printing):

```php
use Neocode\Laraprint\Support\PaperSize;

$size = PaperSize::Size58mm;
$size->getWidthInMm();      // 58.0
$size->getHeightInMm();     // 500.0 (continuous roll)
$size->getWidthInPoints();  // 164.41
$size->isThermalSize();     // true
$size->getLabel();          // "58mm (Ticket thermique standard)"
```

Available cases: `Size40mm`, `Size44mm`, `Size48mm`, `Size58mm`, `Size76mm`, `Size80mm`, `A4`, `A5`, `Letter`.

---

## Asynchronous printing (queue)

Print **in the background** via a Laravel queued job — keeps the HTTP request fast and
retries automatically if the printer is briefly unavailable (`$tries = 3`, `$backoff = 5`).

```php
use Neocode\Laraprint\Jobs\PrintJob;

// Facade shortcuts (return the PendingDispatch)
Laraprint::queueText($config, "Ticket\n");
Laraprint::queueFile($config, storage_path('app/tickets/job.bin'));
Laraprint::queueReceipt($config, $receiptData, config('laraprint.receipt'));

// Or dispatch the job directly, with full queue control
dispatch(PrintJob::receipt($config, $receiptData, config('laraprint.receipt')))
    ->onQueue('printing')
    ->onConnection('redis');

dispatch(PrintJob::text($config, "Hello\n")->onQueue('printing'));
```

`$config` is any connection config array (e.g. from `Laraprint::printers()->connectionConfig($id)`).
Failures still fire the `PrintJobFailed` event and are logged. Run a worker: `php artisan queue:work`.

**Job tracking** — if the `print_jobs` migration is published, the queue helpers record each job
(`queued → printing → completed/failed`, attempts, error). Inspect from the CLI:

```bash
php artisan laraprint:jobs                 # recent print jobs
php artisan laraprint:jobs --status=failed --limit=50
```

Query them in code via `Neocode\Laraprint\Models\PrintJobRecord`. Tracking is optional: without the
table, printing works exactly the same.

## Events & logs

The SDK emits **Laravel events** and writes **logs** around every job (thermal receipt, direct file, spooler). **Optional**: outside a Laravel app (container not booted, `events`/`log` services not bound), these calls become **silent no-ops**.

Events — namespace `Neocode\Laraprint\Events`:

| Event | Emitted | Properties |
| --- | --- | --- |
| `PrintJobStarted` | before sending | `$channel`, `$connectionConfig`, `$context` |
| `PrintJobCompleted` | after success | `$channel`, `$connectionConfig`, `$context` |
| `PrintJobFailed` | on error | `$channel`, `$exception`, `$connectionConfig`, `$context` |

`$channel` is e.g. `thermal.receipt`, `thermal.test`, `direct.file`, `direct.text`, `spool.file`.

```php
use Illuminate\Support\Facades\Event;
use Neocode\Laraprint\Events\PrintJobFailed;

Event::listen(PrintJobFailed::class, function (PrintJobFailed $event) {
    report($event->exception);
    logger()->warning('Print failed', [
        'channel' => $event->channel,
        'context' => $event->context,
    ]);
});
```

Logs are prefixed with `[laraprint]` and go through the application's default log channel.

---

## Recipes & examples

### Controller: list then print on the chosen printer

```php
use Neocode\Laraprint\Laraprint;

class PrintController
{
    public function printers()
    {
        return Laraprint::printers()->active()->map->only(['id', 'name', 'is_default']);
    }

    public function print(Request $request)
    {
        $printer = $request->input('printer_id'); // id, name, or null = default

        Laraprint::printers()
            ->printer($printer)
            ->printText($request->input('content'))
            ->feed(2)->cut()->close();

        return response()->noContent();
    }
}
```

### Multiple stations behind one server: pin the workstation in session

When one server serves several registers, set the workstation **in session** on login;
afterwards, `printer()` automatically targets the correct local printer, with no argument.

```php
use Neocode\Laraprint\Printers\CurrentWorkstation;
use Neocode\Laraprint\Laraprint;

// On register login (the workstation was chosen by the user, e.g. $stationId)
(new CurrentWorkstation())->useWorkstation($stationId);

// Later, anywhere in that register's request:
Laraprint::printers()->printer()->printTextAndClose("Register ticket\n"); // machine/session default

// The user can also force a printer for their session:
Laraprint::selectPrinterForSession($printerId);
```

### POS integration: from your `Sale` model

```php
use Neocode\Laraprint\Laraprint;

$sale = Sale::with(['items', 'payments', 'user', 'cashRegister', 'patient'])->findOrFail($id);

$receiptData = [
    'sale_number'        => $sale->sale_number,
    'sold_at'            => $sale->sold_at,
    'cashier_name'       => $sale->user?->name,
    'cash_register_name' => $sale->cashRegister?->name,
    'patient_name'       => $sale->patient?->full_name,
    'patient_phone'      => $sale->patient?->phone,
    'items' => $sale->items->map(fn ($i) => [
        'item_name'    => $i->item_name,
        'item_code'    => $i->item_code,
        'quantity'     => $i->quantity,
        'unit_price'   => $i->unit_price,
        'discount_amount' => $i->discount_amount,
        'tax_amount'   => $i->tax_amount,
        'total_amount' => $i->total_amount,
    ])->all(),
    'subtotal'        => $sale->subtotal,
    'discount_amount' => $sale->discount_amount,
    'tax_amount'      => $sale->tax_amount,
    'total_amount'    => $sale->total_amount,
    'payments' => $sale->payments->map(fn ($p) => [
        'type'       => $p->type->value,
        'type_label' => $p->type->getLabel(),
        'amount'     => $p->amount,
        'reference'  => $p->reference,
    ])->all(),
    'taxes_grouped' => [],
];

// Print on the current register's default printer
Laraprint::printers()
    ->thermalPrinter(null, config('laraprint.receipt'))
    ->printReceipt($receiptData);
```

---

## Troubleshooting

| Symptom | Hint |
| --- | --- |
| *"Windows printers are only available on Windows systems"* | The PHP app runs on Linux/macOS: use `network` (port 9100) or `cups`. |
| *"Missing IP address…"* | `settings.ip` missing for a `network` printer. |
| *"Missing CUPS name"* (including SMB) | Provide `settings.cups_name`; for SMB, first create the share's CUPS queue. |
| Garbage when printing a PDF | You are sending it as **raw ESC/POS**. Use a `windows`/`cups` (spooler) printer or `PrinterType::WindowsSpoolDocument`/`CupsSpoolDocument`. |
| *"Office file (…) cannot be printed directly in ESC/POS mode"* | Same: go through the spooler. |
| *"The printer connection is already closed"* | `DirectPrinter` instance reused after `close()`. Create a new one. |
| Receipt doesn't cut / overflows width | Adjust `layout.separator_length` (32 ≈ 58mm, 48 ≈ 80mm) and the printer's paper size. |
| Two "default" printers | **Always** use `setDefault()` / `setDefaultForCurrent()` (which guarantee uniqueness) instead of writing `is_default` by hand. |

---

## Tests & quality

**PHPUnit** suite (`tests/Unit` + `tests/Feature`, in-memory SQLite):

```bash
composer install
vendor/bin/phpunit
```

Code style via **Laravel Pint**, static analysis via **PHPStan**:

```bash
vendor/bin/pint          # fix style
vendor/bin/pint --test   # check style
vendor/bin/phpstan       # static analysis (level 5)
```

### Testing your app with `Laraprint::fake()`

In your application's tests, fake the SDK to capture prints instead of sending them to a printer
(no hardware/network needed) — similar to `Mail::fake()`:

```php
use Neocode\Laraprint\Laraprint;

$printer = Laraprint::fake();

// ... code under test that prints ...
Laraprint::printer($config)->printText("Ticket #42\n")->cut()->close();

$printer->assertPrinted()
    ->assertPrintedTimes(1)
    ->assertPrintedContains('Ticket #42');

// Receipts, files and the queued PrintJob are captured too
$printer->assertPrinted(fn ($job) => $job['channel'] === 'print');
$printer->assertNothingPrinted();
```

---

## SDK structure

| Namespace | Contents |
| --- | --- |
| `Neocode\Laraprint\Laraprint` | Facade / main entry point. |
| `…\DirectPrinter` | Direct printing (text, raw, ESC/POS, file). |
| `…\Connector\*` | `ConnectorFactory`, `PrinterConnectionConfig` — connector creation. |
| `…\Discovery\*` | `SystemPrinters` (OS), `LocalPrinters` (USB), `NetworkScanner` (network), `MdnsScanner` (AirPrint), `SnmpQuery` (SNMP). |
| `…\Printing\*` | `SpooledFilePrint` (OS spooler), `IppClient` (IPP). |
| `…\Label\ZplBuilder` | ZPL (Zebra) label builder. |
| `…\Thermal\ReceiptBuilder` | Fluent receipt builder. |
| `…\Console\*` | `PrintersCommand` (`laraprint:printers`), `PrintJobsCommand` (`laraprint:jobs`). |
| `…\Jobs\PrintJob` | Queued (asynchronous) print job. |
| `…\Printing\SpooledFilePrint` | File submission via the OS spooler. |
| `…\Thermal\*` | `ThermalPrinter`, `ReceiptData` — cash receipts. |
| `…\Printers\*` | `PrinterRegistry`, `CurrentWorkstation` — management & machine/session default. |
| `…\Models\*` | `Workstation`, `Printer`, `PrinterCredential`, `PrintJobRecord` — persistence (optional). |
| `…\Support\*` | `PaperSize`, `ReceiptConfig`, `PrinterType`, `ConnectionType`, `PrinterStatus`, `Telemetry`. |
| `…\Testing\*` | `PrintRecorder`, `CaptureConnector` — `Laraprint::fake()` support. |
| `…\Events\*` | `PrintJobStarted`, `PrintJobCompleted`, `PrintJobFailed`. |

> The **docs/RECAP_IMPRESSION.md** document lists everything print-related in the original MedSoft project (models, migrations, services, controllers, routes, views, config, tests) — a reference for evolving Laraprint or migrating an app to the SDK.

No views: the SDK targets **any** configurable printer (not just POS). You pick the printer by its config and print the content you want.

---

## License

Released under the **[MIT](LICENSE)** license.
