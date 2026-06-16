# Laraprint

[![Packagist Version](https://img.shields.io/packagist/v/neocode/laraprint.svg?style=flat-square)](https://packagist.org/packages/neocode/laraprint)
[![Total Downloads](https://img.shields.io/packagist/dt/neocode/laraprint.svg?style=flat-square)](https://packagist.org/packages/neocode/laraprint)
[![PHP Version](https://img.shields.io/packagist/php-v/neocode/laraprint.svg?style=flat-square)](https://packagist.org/packages/neocode/laraprint)
[![Laravel](https://img.shields.io/badge/Laravel-11%20%7C%2012%20%7C%2013-FF2D20.svg?style=flat-square&logo=laravel)](https://laravel.com)
[![Tests](https://img.shields.io/github/actions/workflow/status/neocodesupport/laraprint/tests.yml?branch=main&style=flat-square&label=tests&logo=github)](https://github.com/neocodesupport/laraprint/actions/workflows/tests.yml)
[![License](https://img.shields.io/packagist/l/neocode/laraprint.svg?style=flat-square)](LICENSE)

🇬🇧 [English version](README.md)

> SDK d'**impression sur tout type d'imprimante** pour **Laravel 11+** (compatible Laravel 12 et 13).

Laraprint vous laisse **choisir l'imprimante** (réseau, Windows, CUPS, SMB, USB, fichier) et **imprimer directement** dessus : tickets de caisse, étiquettes, reçus, rapports, documents bureautiques (PDF/Word/Excel), dumps ESC/POS… Le SDK **n'est pas limité au POS** : il sert dès qu'il faut envoyer du contenu vers une imprimante donnée.

- 🖨️ **Impression directe** — texte, données brutes ou commandes ESC/POS vers l'imprimante de votre choix.
- 🧾 **Tickets / reçus** — aide à la mise en forme « ticket de caisse » (optionnel, entièrement configurable).
- 🗂️ **Fichiers** — envoi de fichiers ESC/POS bruts, ou délégation au spouleur OS pour PDF/Word/Excel.
- 🔎 **Découverte** — liste des imprimantes installées sur le poste (Windows / Linux / macOS).
- 🗄️ **Gestion** — enregistrement des imprimantes en base, choix avant impression, **imprimante par défaut par machine et par session**.
- 📡 **Observabilité** — événements Laravel et logs autour de chaque job (optionnels).
- 🧩 **Sans vue, sans couplage** — logique uniquement ; fonctionne avec un simple tableau de configuration, même hors d'une app Laravel complète.

---

## Table des matières

1. [Prérequis](#prérequis)
2. [Installation](#installation)
3. [Démarrage rapide](#démarrage-rapide)
4. [Concepts clés](#concepts-clés)
5. [Configuration](#configuration)
6. [Types de connexion](#types-de-connexion)
7. [Impression directe (`DirectPrinter`)](#impression-directe-directprinter)
8. [Imprimer un fichier](#imprimer-un-fichier)
9. [Tickets / reçus (`ThermalPrinter`)](#tickets--reçus-thermalprinter)
10. [Découverte des imprimantes du poste](#découverte-des-imprimantes-du-poste)
11. [Gestion des imprimantes (`PrinterRegistry`)](#gestion-des-imprimantes-printerregistry)
12. [Imprimante par défaut : machine + session](#imprimante-par-défaut--machine--session)
13. [Modèles Eloquent](#modèles-eloquent)
14. [Référence de la façade `Laraprint`](#référence-de-la-façade-laraprint)
15. [Tailles de papier (`PaperSize`)](#tailles-de-papier-papersize)
16. [Événements & logs](#événements--logs)
17. [Recettes & exemples](#recettes--exemples)
18. [Dépannage](#dépannage)
19. [Tests & qualité](#tests--qualité)
20. [Structure du SDK](#structure-du-sdk)
21. [Licence](#licence)

---

## Prérequis

| Composant | Version |
| --- | --- |
| PHP | **8.3+** |
| Laravel | **11, 12 ou 13** |
| [`mike42/escpos-php`](https://github.com/mike42/escpos-php) | `^2.2` (installé automatiquement) |

Selon les imprimantes utilisées :

- **Réseau (port 9100)** : aucune dépendance OS, fonctionne partout.
- **Windows** : l'app PHP doit tourner **sous Windows** (utilise PowerShell `Get-Printer`, `Set-Printer`, `Start-Process -Verb Print`).
- **CUPS (Linux/macOS)** : binaires `lp` / `lpstat` disponibles.
- **SMB** : configuré via une file **CUPS** pointant vers le partage (pas de wrapper SMB natif en PHP).

---

## Installation

```bash
composer require neocode/laraprint
```

Le `LaraprintServiceProvider` est **enregistré automatiquement** (package auto-discovery). Il :

- fusionne la configuration par défaut (`config('laraprint')`) ;
- enregistre le singleton `PrinterRegistry` dans le conteneur ;
- expose les tags de publication.

### Publier la configuration

```bash
php artisan vendor:publish --tag=laraprint-config
```

→ crée `config/laraprint.php`.

### Publier les migrations (optionnel)

Nécessaire **uniquement** si vous voulez **persister vos imprimantes en base** (gestion, imprimante par défaut, identifiants chiffrés) via le `PrinterRegistry` :

```bash
php artisan vendor:publish --tag=laraprint-migrations
php artisan migrate
```

→ crée les tables `workstations`, `printers`, `printer_credentials`.

> 💡 Si vous fournissez vos imprimantes par **tableau de configuration** (ou depuis votre propre modèle), les migrations sont **facultatives**.

---

## Démarrage rapide

```php
use Neocode\Laraprint\Laraprint;

// 1) Décrire l'imprimante cible
$config = [
    'connection_type' => 'network',
    'settings' => ['ip' => '192.168.1.20', 'port' => 9100],
];

// 2) Imprimer du texte
Laraprint::printer($config)
    ->printText("Bonjour !\n")
    ->feed(2)
    ->cut()
    ->close();

// 3) Imprimer un ticket de caisse formaté
Laraprint::thermalPrinter($config, config('laraprint.receipt'))
    ->printReceipt([
        'sale_number' => 'SALE-001',
        'sold_at'     => now(),
        'items'       => [['item_name' => 'Café', 'quantity' => 1, 'unit_price' => 1000, 'total_amount' => 1000]],
        'subtotal'    => 1000,
        'total_amount'=> 1000,
        'payments'    => [['type' => 'cash', 'amount' => 1000]],
    ]);
```

---

## Concepts clés

### `connection_type` — *comment* joindre l'imprimante

C'est le **canal physique** : `network`, `windows`, `cups`, `smb`, `file` / `usb`. Il détermine le **connecteur** créé.

### `printer_type` — *quelle stratégie* d'impression

C'est le **type logique** (enum `Neocode\Laraprint\Support\PrinterType`), qui détermine **comment** un fichier est envoyé :

| Valeur | Constante | Usage |
| --- | --- | --- |
| `thermal_escpos_raw` | `PrinterType::ThermalEscposRaw` | Envoi **brut** d'octets ESC/POS (imprimantes thermiques, port 9100, périphérique). |
| `windows_spool_document` | `PrinterType::WindowsSpoolDocument` | Délègue au **spouleur Windows** via pilote (PDF/Word/Excel). |
| `cups_spool_document` | `PrinterType::CupsSpoolDocument` | Délègue au **spouleur CUPS** (`lp`), conversion selon pilotes. |

Si `printer_type` n'est pas fourni, il est **déduit** du `connection_type` :
`windows → windows_spool_document`, `cups`/`smb` → `cups_spool_document`, sinon `thermal_escpos_raw`.

### `settings` — paramètres du canal

Tableau dépendant du `connection_type` (IP/port, nom d'imprimante Windows, nom CUPS, chemin de fichier…). Voir [Types de connexion](#types-de-connexion).

### Le tableau de configuration (`$config`)

La quasi-totalité de l'API consomme **le même tableau** :

```php
$config = [
    'connection_type' => 'network',          // requis (alias accepté : 'type')
    'settings'        => ['ip' => '...', 'port' => 9100],
    'name'            => 'Caisse 1',          // optionnel (libellé)
    'printer_type'    => 'thermal_escpos_raw',// optionnel (string ou PrinterType)
    'is_active'       => true,                // optionnel (défaut true)
];
```

> Une imprimante avec `is_active = false` lève une exception à la connexion : pratique pour désactiver sans supprimer.

---

## Configuration

Fichier `config/laraprint.php` (après publication). Toutes les valeurs sont pilotables par variables d'environnement.

```php
return [
    // Type de connexion par défaut (network, windows, cups, smb, file, usb)
    'connection_type' => env('LARAPRINT_CONNECTION_TYPE', 'network'),

    // Poste (ordinateur) courant — voir « Imprimante par défaut : machine + session »
    'workstation' => [
        'identifier' => env('LARAPRINT_WORKSTATION', null), // défaut : nom d'hôte système
    ],

    // Paramètres de connexion par type
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

    // Configuration du ticket de caisse (ThermalPrinter)
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
            'header_size'      => 2,   // taille du titre (1-8)
            'item_name_size'   => 1,   // taille du nom d'article
            'total_size'       => 2,   // taille du total
            'separator_char'   => '-', // caractère de séparation
            'separator_length' => 32,  // largeur (32 ≈ 58mm, 48 ≈ 80mm)
        ],
        'currency' => [
            'symbol'              => env('LARAPRINT_CURRENCY_SYMBOL', 'FCFA'),
            'position'            => 'after', // 'before' ou 'after'
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

### Variables d'environnement

| Variable | Rôle | Défaut |
| --- | --- | --- |
| `LARAPRINT_CONNECTION_TYPE` | Type de connexion par défaut | `network` |
| `LARAPRINT_WORKSTATION` | Identifiant machine forcé (sinon nom d'hôte) | `null` |
| `LARAPRINT_NETWORK_IP` / `_PORT` / `_TIMEOUT` | Réseau | `192.168.1.11` / `9100` / `5` |
| `LARAPRINT_WINDOWS_PRINTER` | Nom d'imprimante Windows | `EPSON TM-T20II Receipt` |
| `LARAPRINT_CUPS_NAME` | Nom de file CUPS | `POS-Printer` |
| `LARAPRINT_FILE_PATH` | Chemin pour le connecteur fichier | `storage/app/receipts/receipt.txt` |
| `LARAPRINT_COMPANY_*` | Entête entreprise du ticket | — |
| `LARAPRINT_CURRENCY_SYMBOL` | Symbole de devise | `FCFA` |

---

## Types de connexion

| `connection_type` | `settings` requis | Notes |
| --- | --- | --- |
| `network` | `ip` *(requis)*, `port` (défaut `9100`), `timeout` (défaut `5`) | TCP brut. Fonctionne sur tous les OS. |
| `windows` | `printer_name` *(requis)* | **Windows uniquement.** Lève une exception sur les autres OS. |
| `cups` | `cups_name` *(requis)* | Linux/macOS. |
| `smb` | `cups_name` *(requis)* | Passe par une file CUPS (configurez le partage SMB côté CUPS). |
| `file` / `usb` | `path` ou `device_path` *(requis)* | Écrit vers un fichier ou un périphérique (`/dev/usb/lp0`, `COM3`, etc.). |

Créer un connecteur ESC/POS bas niveau (rarement nécessaire — préférez `DirectPrinter`/`ThermalPrinter`) :

```php
use Neocode\Laraprint\Connector\ConnectorFactory;
use Neocode\Laraprint\Connector\PrinterConnectionConfig;

$connector = ConnectorFactory::fromArray($config);

// Ou via le DTO immuable
$dto = PrinterConnectionConfig::fromArray($config);
$connector = ConnectorFactory::fromConfig($dto);
```

---

## Impression directe (`DirectPrinter`)

Pour envoyer texte, octets bruts ou commandes ESC/POS vers **n'importe quelle** imprimante.

```php
use Neocode\Laraprint\DirectPrinter; // ou Laraprint::printer($config)

$printer = DirectPrinter::forPrinter($config);

$printer
    ->printText("Document #12345\n")
    ->printText('Date : '.date('d/m/Y H:i')."\n")
    ->feed(2)
    ->cut()
    ->close();
```

### API de `DirectPrinter`

| Méthode | Description |
| --- | --- |
| `DirectPrinter::forPrinter(array $config): self` | Crée une instance pour l'imprimante ciblée. |
| `printText(string $text): self` | Envoie du texte (UTF-8). |
| `printRaw(string $data): self` | Envoie des octets bruts (commandes ESC/POS, protocoles spécifiques). |
| `printFile(string $path, bool $asText = false, ?PrinterType $type = null): self` | Envoie un fichier (voir ci-dessous). |
| `printFileAndClose(string $path, bool $asText = false): bool` | Envoie un fichier puis ferme. |
| `printTextAndClose(string $text): bool` | Envoie du texte puis ferme. |
| `feed(int $lines = 1): self` | Saut de ligne. |
| `cut(int $mode = CUT_FULL, int $lines = 3): self` | Coupe le papier (imprimantes thermiques). |
| `getEscposPrinter(): \Mike42\Escpos\Printer` | Accès complet à l'API ESC/POS (code-barres, images, QR…). |
| `testConnection(): bool` | Teste l'ouverture/fermeture sans imprimer. |
| `close(): void` | Ferme la connexion (idempotent ; appelé aussi au `__destruct`). |

> ⚠️ Une instance `DirectPrinter` est **à usage unique** : après `close()`, créez-en une nouvelle pour réimprimer.

### Contrôle avancé via ESC/POS

```php
$p = DirectPrinter::forPrinter($config);
$escpos = $p->getEscposPrinter();

$escpos->setJustification(\Mike42\Escpos\Printer::JUSTIFY_CENTER);
$escpos->setEmphasis(true);
$escpos->text("MON ENTÊTE\n");
$escpos->setEmphasis(false);
$escpos->barcode('123456789');
$escpos->qrCode('https://exemple.com');

$p->cut()->close();
```

---

## Imprimer un fichier

Trois approches, du plus simple au plus bas niveau.

### a) `Laraprint::printFile()` — choix automatique de la stratégie *(recommandé)*

```php
use Neocode\Laraprint\Laraprint;
use Neocode\Laraprint\Support\PrinterType;

// Ticket / dump ESC/POS (texte UTF-8)
Laraprint::printFile($chemin, $config, asText: true, printerType: PrinterType::ThermalEscposRaw);

// PDF / Word / Excel via pilote Windows
Laraprint::printFile('C:\\docs\\facture.pdf', [
    'connection_type' => 'windows',
    'settings' => ['printer_name' => 'HP LaserJet'],
], printerType: PrinterType::WindowsSpoolDocument);
```

Si `printerType` est omis, il est déduit du `connection_type` (et de `printer_type` du tableau s'il est présent).

### b) `DirectPrinter::printFile()` — pour le flux ESC/POS

```php
// Fichier binaire ESC/POS (.bin, dump brut) — lu par blocs de 64 Ko
DirectPrinter::forPrinter($config)->printFile(storage_path('app/tickets/job.bin'))->feed(2)->cut()->close();

// Fichier texte interprété comme ticket
DirectPrinter::forPrinter($config)->printFile('/path/document.txt', asText: true)->close();

// Raccourci envoi + fermeture
DirectPrinter::forPrinter($config)->printFileAndClose($chemin);
```

> 🚫 En mode ESC/POS brut, tenter d'imprimer un fichier **bureautique** (`pdf`, `png`, `docx`, `xlsx`…) lève une exception explicite : utilisez une imprimante `windows`/`cups` (spouleur).

### c) `Laraprint::spoolFile()` / `SpooledFilePrint::submit()` — spouleur OS

Pour déléguer la conversion aux pilotes (PDF, images, documents) :

```php
use Neocode\Laraprint\Laraprint;
use Neocode\Laraprint\Printing\SpooledFilePrint;

// CUPS (Linux/macOS) — lp -d <file> <fichier>
Laraprint::spoolFile('/path/ticket.pdf', [
    'connection_type' => 'cups',
    'settings' => ['cups_name' => 'EPSON_TM-T20'],
]);

// Windows — bascule temporairement l'imprimante par défaut, puis Start-Process -Verb Print
SpooledFilePrint::submit('C:\\path\\ticket.pdf', [
    'connection_type' => 'windows',
    'settings' => ['printer_name' => 'Nom de votre imprimante Windows'],
]);
```

---

## Tickets / reçus (`ThermalPrinter`)

`ThermalPrinter` met en forme un **ticket de caisse** complet : entête entreprise, infos vente, articles, totaux, taxes, paiements, pied de page et **QR code**.

### Créer l'imprimante

```php
use Neocode\Laraprint\Thermal\ThermalPrinter;

$printer = ThermalPrinter::fromConnectionConfig($config, config('laraprint.receipt'));
// config('laraprint.receipt') peut être remplacé par un tableau ou un ReceiptConfig
```

### Imprimer un ticket

```php
$printer->printReceipt([
    'sale_number'        => 'SALE202501001',
    'sold_at'            => now(),               // DateTimeInterface ou string
    'cashier_name'       => 'Jean Dupont',
    'cash_register_name' => 'Caisse 1',
    'patient_name'       => 'Marie Martin',      // champs « patient » optionnels (contexte santé)
    'patient_phone'      => '+225 0700000000',
    'items' => [
        [
            'item_name'        => 'Paracétamol 500mg',
            'item_code'        => 'PAR500',      // optionnel
            'item_description' => '',            // optionnel
            'quantity'         => 2,
            'unit_price'       => 500,
            'discount_amount'  => 0,             // optionnel
            'tax_amount'       => 0,             // optionnel
            'total_amount'     => 1000,
        ],
    ],
    'subtotal'        => 1000,
    'discount_amount' => 0,
    'tax_amount'      => 0,
    'total_amount'    => 1000,
    'payments' => [
        ['type' => 'cash', 'type_label' => 'Espèces', 'amount' => 1000,
         'cash_received' => 2000, 'change_amount' => 1000, 'reference' => null],
    ],
    'taxes_grouped' => [
        // ['name' => 'TVA', 'rate' => 18, 'amount' => 180],
    ],
]);
```

Libellés de paiement reconnus automatiquement si `type_label` est absent : `cash`, `card`, `mobile_money`, `orange_money`, `wave`, `mtn_money`, `moov_money`, `bank_transfer`, `tpe`, `insurance`, `check`, `mixed`.

### Ticket de test & test de connexion

```php
$printer->printTestReceipt(); // imprime un petit ticket de vérification
$ok = (new ThermalPrinter(...))->testConnection(); // true/false sans imprimer
```

### Personnaliser la mise en forme (`ReceiptConfig`)

```php
use Neocode\Laraprint\Support\ReceiptConfig;

$cfg = ReceiptConfig::fromArray([
    'company'  => ['name' => 'MA PHARMACIE', 'phone' => '+225 0700000000'],
    'layout'   => ['separator_length' => 48], // 80mm
    'currency' => ['symbol' => '€', 'position' => 'before', 'decimals' => 2],
    'qr_code'  => ['enabled' => true, 'size' => 4],
]);

$cfg->formatCurrency(1234.5); // "€ 1 234,50"
$printer = ThermalPrinter::fromConnectionConfig($config, $cfg);
```

---

## Découverte des imprimantes du poste

Liste les imprimantes **installées sur la machine** où tourne l'application.

```php
use Neocode\Laraprint\Laraprint; // ou Neocode\Laraprint\Discovery\SystemPrinters

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

- **Windows** : via PowerShell `Get-Printer` (repli `wmic`).
- **Linux/macOS** : via CUPS `lpstat -a` (repli `lpstat -p`).
- Le `printer_type` est **deviné** d'après le nom (heuristique : `receipt`, `pos`, `ticket`, `TM…` → thermique).

### Découverte USB / connexion locale

Détecte les imprimantes physiquement reliées à la machine :

```php
$usb = Laraprint::listUsbPrinters();
// Windows : imprimantes sur ports USB*/DOT4* (connecteur windows)
// Linux/macOS : /dev/usb/lp*, /dev/lp* (connecteur file) + périphériques usb:// de CUPS
```

### Découverte réseau (scan)

Sonde le réseau local à la recherche d'imprimantes écoutant sur un port d'impression (9100 par défaut) :

```php
// Détecte le /24 local et scanne le port 9100
$found = Laraprint::scanNetworkPrinters();

// Plage et ports explicites
$found = Laraprint::scanNetworkPrinters('192.168.1.0/24', ports: [9100, 515], timeout: 0.3);
$found = Laraprint::scanNetworkPrinters('192.168.1.10-50');
```

Les plages acceptent le **CIDR** (`192.168.1.0/24`), un **intervalle** (`192.168.1.10-50` ou complet
`…-192.168.1.50`), ou une **IP unique**. Les connexions sont tentées en parallèle (sockets non
bloquantes) : un `/24` se scanne en quelques secondes. Les plages de plus de 4096 adresses sont
refusées pour éviter les scans massifs accidentels.

### Découverte combinée & import

```php
$all = Laraprint::discoverPrinters(network: true);   // système + USB + réseau

// Enregistre les imprimantes nouvellement découvertes (dédupliquées par nom)
$registry = Laraprint::printers();
$registry->importSystemPrinters();
$registry->importUsbPrinters();
$registry->importNetworkPrinters('192.168.1.0/24');
```

Depuis la CLI :

```bash
php artisan laraprint:printers scan                 # affiche les imprimantes système + USB
php artisan laraprint:printers scan --network       # scanne aussi le réseau local
php artisan laraprint:printers import --usb         # enregistre les imprimantes USB
php artisan laraprint:printers import --network --range=192.168.1.0/24
```

---

## Gestion des imprimantes (`PrinterRegistry`)

Quand vos imprimantes sont **persistées en base** (migrations publiées), le `PrinterRegistry` couvre tout le cycle : **lister → choisir → ajouter → importer → définir le défaut → imprimer**.

```php
use Neocode\Laraprint\Laraprint;

$registry = Laraprint::printers(); // ou app(\Neocode\Laraprint\Printers\PrinterRegistry::class)
```

### Ajouter une imprimante

```php
$caisse = $registry->register([
    'name'            => 'Caisse 1',
    'connection_type' => 'network',
    'printer_type'    => 'thermal_escpos_raw',
    'settings'        => ['ip' => '192.168.1.20', 'port' => 9100],
    'is_default'      => true, // optionnel : devient le défaut dès la création
]);

// Avec identifiants (mot de passe chiffré automatiquement via Crypt)
$registry->register([
    'name'            => 'HP LaserJet Bureau',
    'connection_type' => 'windows',
    'printer_type'    => 'windows_spool_document',
    'settings'        => ['printer_name' => 'HP LaserJet'],
], credentials: ['username' => 'poste', 'password' => 'secret', 'domain' => 'WORKGROUP']);
```

### Importer automatiquement les imprimantes du poste

```php
$ajoutees = $registry->importSystemPrinters();        // global
$ajoutees = $registry->importSystemPrinters($posteId); // rattachées à un poste
// N'ajoute que celles dont le nom n'existe pas encore.
```

### Lister & choisir

```php
$registry->all();              // toutes (triées par nom)
$registry->active();           // uniquement actives
$registry->find($id);          // par id (ou null)
$registry->findByName('Caisse 1');

foreach ($registry->active() as $p) {
    echo "{$p->id} — {$p->name}".($p->is_default ? ' (défaut)' : '').PHP_EOL;
}
```

### Choisir l'imprimante **avant** d'imprimer

`printer()` / `thermalPrinter()` acceptent une **instance**, un **id**, un **nom**, ou `null` (= défaut contextuel) :

```php
// DirectPrinter prêt à l'emploi
$registry->printer($caisse->id)->printText("Bonjour\n")->cut()->close();
$registry->printer('Caisse 1')->printTextAndClose("Par nom\n");
$registry->printer()->printTextAndClose("Sur le défaut\n");

// Ticket de caisse sur l'imprimante choisie
$registry->thermalPrinter($caisse->id, config('laraprint.receipt'))->printReceipt($data);

// Juste la config (pour la passer ailleurs)
$config = $registry->connectionConfig($caisse->id);
```

### Définir / supprimer

```php
$registry->setDefault($caisse->id); // unicité garantie dans le même périmètre
$registry->default();               // imprimante par défaut active (ou null)
$registry->forget($id);             // supprime l'enregistrement
```

### Raccourcis sur la façade

`Laraprint::registerPrinter()`, `Laraprint::setDefaultPrinter()`, `Laraprint::defaultPrinter()`, `Laraprint::usePrinter()`.

### Commande Artisan

Gérez les imprimantes depuis le terminal (nécessite les migrations publiées) :

```bash
php artisan laraprint:printers list           # liste les imprimantes enregistrées
php artisan laraprint:printers add \
    --name="Caisse 1" --type=network \
    --setting=ip=192.168.1.20 --setting=port=9100 \
    --printer-type=thermal_escpos_raw --default
php artisan laraprint:printers default 1 --machine  # défaut pour la machine courante
php artisan laraprint:printers import         # importe les imprimantes du poste
php artisan laraprint:printers test 1         # imprime un ticket de test (id, nom, ou défaut)
php artisan laraprint:printers remove 1
```

---

## Imprimante par défaut : machine + session

L'imprimante par défaut est **liée à une machine donnée** (poste / ordinateur) : chaque poste peut avoir **sa propre** imprimante par défaut. La machine courante est identifiée par :

1. l'identifiant **forcé** (`config('laraprint.workstation.identifier')` / `LARAPRINT_WORKSTATION`) ;
2. sinon le **nom d'hôte** système (`gethostname()`) ;
3. un poste peut aussi être **posé en session** (multi-postes derrière un même serveur).

```php
$registry = Laraprint::printers();

// Imprimante par défaut DE CET ORDINATEUR (repli sur le défaut global si le poste n'en a pas)
$registry->defaultForCurrent();              // ou Laraprint::defaultPrinter()

// Définir le défaut POUR CET ORDINATEUR :
// l'imprimante est rattachée au poste courant (créé à partir du nom d'hôte si besoin), puis marquée défaut.
$registry->setDefaultForCurrent($caisse->id); // ou Laraprint::setMachineDefaultPrinter($id)

// Poste courant résolu
$registry->currentWorkstation();              // ou Laraprint::currentWorkstation()

// Choisir une imprimante le temps de la SESSION (surcharge le défaut machine)
$registry->selectForSession($autre->id);      // ou Laraprint::selectPrinterForSession($id)
$registry->clearSessionSelection();           // revenir au défaut machine

// Imprime « sur le défaut courant »
$registry->printer()->printTextAndClose("Ticket\n");
```

**Ordre de résolution** quand aucune imprimante n'est passée explicitement à `printer()` / `resolve()` :

1. imprimante choisie pour la **session** courante ;
2. imprimante par **défaut de la machine** (poste) courante ;
3. imprimante par **défaut globale**.

`default($workstationId)` cible explicitement un poste, et `setDefault()` ne retire l'ancien défaut **que dans le même périmètre** (même `workstation_id`). Ainsi, changer le défaut du poste A n'affecte jamais le poste B.

> 🧭 **Cas typique** — Un serveur Laravel sert plusieurs caisses : chaque caisse pose son poste en session (`useWorkstation`) à la connexion, puis `printer()` imprime toujours sur la bonne imprimante locale sans paramètre.

---

## Modèles Eloquent

Disponibles après publication des migrations. Ils restent **optionnels** : le SDK fonctionne aussi par tableau.

### `Workstation` (poste / ordinateur)

| Champ | Type |
| --- | --- |
| `name` | string |
| `hostname` | string, unique, nullable (identité machine) |
| `ip_address` | string, unique |
| `location`, `is_active` | string nullable / bool |

Relations & helpers : `printers()`, `defaultPrinter()`, `getActivePrinters()`, `getDefaultPrinter()`, `getByIp($ip)`, scopes `active()`, `byIp()`, `byHostname()`.

### `Printer`

| Champ | Type |
| --- | --- |
| `workstation_id` | FK nullable |
| `name` | string |
| `connection_type` | `network`/`windows`/`cups`/`smb`/`usb`/`file` |
| `printer_type` | string nullable |
| `model`, `is_default`, `is_active`, `settings` | string / bool / bool / json |

Méthodes : `getConnectionConfig()` (renvoie une config **prête pour le SDK**), `makeDefault()` (défaut exclusif par périmètre), scopes `active()`, `byType()`, `default()`, `forWorkstation()`, relations `workstation()`, `credentials()`.

### `PrinterCredential`

`username`, `password` (**chiffré** via `Crypt`, masqué dans la sérialisation), `domain`. Relation `printer()`.

```php
use Neocode\Laraprint\Models\Printer;

$config = Printer::query()->active()->default()->first()?->getConnectionConfig();
Laraprint::printer($config)->printTextAndClose("OK\n");
```

---

## Référence de la façade `Laraprint`

| Méthode | Retour | Rôle |
| --- | --- | --- |
| `printer(array $config)` | `DirectPrinter` | Impression directe. |
| `thermalPrinter(array $config, array\|ReceiptConfig $receipt)` | `ThermalPrinter` | Ticket de caisse. |
| `connector(array $config)` | connecteur ESC/POS | Connecteur bas niveau. |
| `connectionConfig(array $data)` | `PrinterConnectionConfig` | DTO de connexion. |
| `receiptConfig(array $data)` | `ReceiptConfig` | DTO config ticket. |
| `receiptData(array $data)` | `ReceiptData` | DTO données ticket. |
| `listLocalPrinters()` | `array` | Imprimantes du poste (OS). |
| `listUsbPrinters()` | `array` | Imprimantes USB / locales. |
| `scanNetworkPrinters(?string $range, array $ports, float $timeout)` | `array` | Scan réseau d'imprimantes. |
| `discoverPrinters(bool $network = false, ?string $range = null)` | `array` | Système + USB (+ réseau). |
| `spoolFile(string $path, array $config)` | `void` | Dépôt via spouleur OS. |
| `printFile(string $path, array $config, bool $asText = false, ?PrinterType $type = null)` | `void` | Impression fichier (stratégie auto). |
| `printers()` | `PrinterRegistry` | Registre des imprimantes en base. |
| `registerPrinter(array $attrs, ?array $credentials = null)` | `Printer` | Ajout d'imprimante. |
| `setDefaultPrinter(Printer\|int $p)` | `Printer` | Défaut (générique). |
| `defaultPrinter(?int $workstationId = null)` | `?Printer` | Défaut machine (ou poste donné). |
| `setMachineDefaultPrinter(Printer\|int $p)` | `Printer` | Défaut **de la machine courante**. |
| `currentWorkstation()` | `?Workstation` | Poste courant. |
| `selectPrinterForSession(Printer\|int\|string $p)` | `Printer` | Sélection de session. |
| `usePrinter(Printer\|int\|string\|null $p = null)` | `DirectPrinter` | Imprimante enregistrée choisie. |

---

## Tailles de papier (`PaperSize`)

Utilitaire pour vos générations PDF/aperçus (hors impression directe) :

```php
use Neocode\Laraprint\Support\PaperSize;

$size = PaperSize::Size58mm;
$size->getWidthInMm();      // 58.0
$size->getHeightInMm();     // 500.0 (rouleau continu)
$size->getWidthInPoints();  // 164.41
$size->isThermalSize();     // true
$size->getLabel();          // "58mm (Ticket thermique standard)"
```

Cas disponibles : `Size40mm`, `Size44mm`, `Size48mm`, `Size58mm`, `Size76mm`, `Size80mm`, `A4`, `A5`, `Letter`.

---

## Événements & logs

Le SDK émet des **événements Laravel** et écrit des **logs** autour de chaque job (ticket thermique, fichier direct, spouleur). **Optionnel** : hors application Laravel (conteneur non démarré, services `events`/`log` non liés), ces appels deviennent des **no-op silencieux**.

Événements — namespace `Neocode\Laraprint\Events` :

| Événement | Émis | Propriétés |
| --- | --- | --- |
| `PrintJobStarted` | avant l'envoi | `$channel`, `$connectionConfig`, `$context` |
| `PrintJobCompleted` | après succès | `$channel`, `$connectionConfig`, `$context` |
| `PrintJobFailed` | en cas d'erreur | `$channel`, `$exception`, `$connectionConfig`, `$context` |

`$channel` vaut par ex. `thermal.receipt`, `thermal.test`, `direct.file`, `direct.text`, `spool.file`.

```php
use Illuminate\Support\Facades\Event;
use Neocode\Laraprint\Events\PrintJobFailed;

Event::listen(PrintJobFailed::class, function (PrintJobFailed $event) {
    report($event->exception);
    logger()->warning('Impression échouée', [
        'channel' => $event->channel,
        'context' => $event->context,
    ]);
});
```

Les logs sont préfixés `[laraprint]` et passent par le canal de log par défaut de l'application.

---

## Recettes & exemples

### Contrôleur : lister puis imprimer sur l'imprimante choisie

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
        $printer = $request->input('printer_id'); // id, nom, ou null = défaut

        Laraprint::printers()
            ->printer($printer)
            ->printText($request->input('content'))
            ->feed(2)->cut()->close();

        return response()->noContent();
    }
}
```

### Multi-postes derrière un même serveur : fixer le poste en session

Quand un même serveur sert plusieurs caisses, posez le poste **en session** à la connexion ;
ensuite, `printer()` cible automatiquement la bonne imprimante locale, sans paramètre.

```php
use Neocode\Laraprint\Printers\CurrentWorkstation;
use Neocode\Laraprint\Laraprint;

// À la connexion de la caisse (le poste a été choisi par l'utilisateur, ex. $posteId)
(new CurrentWorkstation())->useWorkstation($posteId);

// Plus tard, n'importe où dans la requête de cette caisse :
Laraprint::printers()->printer()->printTextAndClose("Ticket caisse\n"); // défaut machine/session

// L'utilisateur peut aussi forcer une imprimante pour sa session :
Laraprint::selectPrinterForSession($imprimanteId);
```

### Intégration POS : depuis votre modèle `Sale`

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

// Imprime sur l'imprimante par défaut de la caisse courante
Laraprint::printers()
    ->thermalPrinter(null, config('laraprint.receipt'))
    ->printReceipt($receiptData);
```

---

## Dépannage

| Symptôme | Piste |
| --- | --- |
| *« Les imprimantes Windows ne sont disponibles que sur les systèmes Windows »* | L'app PHP tourne sous Linux/macOS : utilisez `network` (port 9100) ou `cups`. |
| *« Adresse IP manquante… »* | `settings.ip` absent pour une imprimante `network`. |
| *« Nom CUPS manquant »* (y compris en SMB) | Renseignez `settings.cups_name` ; pour SMB, créez d'abord la file CUPS du partage. |
| Charabia à l'impression d'un PDF | Vous l'envoyez en **ESC/POS brut**. Utilisez une imprimante `windows`/`cups` (spouleur) ou `PrinterType::WindowsSpoolDocument`/`CupsSpoolDocument`. |
| *« Fichier bureautique (…) non imprimable directement en mode ESC/POS »* | Idem : passez par le spouleur. |
| *« La connexion à l'imprimante est déjà fermée »* | Instance `DirectPrinter` réutilisée après `close()`. Créez-en une nouvelle. |
| Le ticket ne coupe pas / déborde en largeur | Ajustez `layout.separator_length` (32 ≈ 58mm, 48 ≈ 80mm) et la taille papier de l'imprimante. |
| Deux imprimantes « par défaut » | Utilisez **toujours** `setDefault()` / `setDefaultForCurrent()` (qui garantissent l'unicité) plutôt que d'écrire `is_default` à la main. |

---

## Tests & qualité

Suite **PHPUnit** (`tests/Unit` + `tests/Feature`, base SQLite en mémoire) :

```bash
composer install
vendor/bin/phpunit
```

Style de code via **Laravel Pint** :

```bash
vendor/bin/pint          # corrige
vendor/bin/pint --test   # vérifie sans modifier
```

---

## Structure du SDK

| Espace de noms | Contenu |
| --- | --- |
| `Neocode\Laraprint\Laraprint` | Façade / point d'entrée principal. |
| `…\DirectPrinter` | Impression directe (texte, brut, ESC/POS, fichier). |
| `…\Connector\*` | `ConnectorFactory`, `PrinterConnectionConfig` — création des connecteurs. |
| `…\Discovery\*` | `SystemPrinters` (files OS), `LocalPrinters` (USB), `NetworkScanner` (scan réseau). |
| `…\Console\PrintersCommand` | Commande Artisan `laraprint:printers`. |
| `…\Printing\SpooledFilePrint` | Dépôt de fichiers via le spouleur OS. |
| `…\Thermal\*` | `ThermalPrinter`, `ReceiptData` — tickets de caisse. |
| `…\Printers\*` | `PrinterRegistry`, `CurrentWorkstation` — gestion & défaut machine/session. |
| `…\Models\*` | `Workstation`, `Printer`, `PrinterCredential` — persistance (optionnelle). |
| `…\Support\*` | `PaperSize`, `ReceiptConfig`, `PrinterType`, `Telemetry`. |
| `…\Events\*` | `PrintJobStarted`, `PrintJobCompleted`, `PrintJobFailed`. |

> Le document **docs/RECAP_IMPRESSION.md** recense tout ce qui touche à l'impression dans le projet MedSoft d'origine (modèles, migrations, services, contrôleurs, routes, vues, config, tests) — référence pour faire évoluer Laraprint ou migrer une app vers le SDK.

Aucune vue : le SDK cible **toute imprimante** configurable (pas seulement POS). Vous choisissez l'imprimante par sa config et imprimez le contenu voulu.

---

## Licence

Distribué sous licence **[MIT](LICENSE)**.
