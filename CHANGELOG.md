# Changelog

Toutes les modifications notables de ce projet sont documentées dans ce fichier.

Le format s'appuie sur [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/),
et ce projet suit le [versionnage sémantique](https://semver.org/lang/fr/).

## [1.2.0] - 2026-06-16

### Ajouté
- **Découverte mDNS / Bonjour (AirPrint)** (`Discovery\MdnsScanner`) : requête multicast des
  services `_pdl-datastream._tcp` / `_printer._tcp` / `_ipp._tcp` et corrélation PTR/SRV/A.
  Façade `Laraprint::discoverAirPrint()`, `PrinterRegistry::importAirPrintPrinters()`,
  option CLI `--mdns`.
- **Impression asynchrone** via job en file (`Jobs\PrintJob`) : `text`, `raw`, `file`, `receipt`,
  3 tentatives avec backoff. Façade `Laraprint::queueText()`, `queueFile()`, `queueReceipt()`.
- `discoverPrinters()` accepte désormais l'option `airprint`.

### Dépendances
- Ajout de `illuminate/bus` et `illuminate/queue` (jobs en file).

## [1.1.0] - 2026-06-16

### Ajouté
- **Découverte réseau** des imprimantes (`Discovery\NetworkScanner`) : scan parallèle d'une
  plage CIDR / intervalle / IP sur les ports d'impression (9100 par défaut), avec déduction
  automatique du /24 local et garde-fou anti-scan massif (> 4096 adresses).
- **Découverte locale / USB** (`Discovery\LocalPrinters`) : ports `USB*`/`DOT4*` sous Windows,
  périphériques `/dev/usb/lp*` / `/dev/lp*` et périphériques `usb://` de CUPS sous Linux/macOS.
- Façade : `Laraprint::scanNetworkPrinters()`, `listUsbPrinters()`, `discoverPrinters()`.
- `PrinterRegistry::importUsbPrinters()` et `importNetworkPrinters()`.
- Commande Artisan : action `scan` et options `--usb`, `--network`, `--range` pour `import`/`scan`.
- **Tests « application complète »** via `orchestra/testbench` (commande Artisan, ServiceProvider).
- Templates GitHub : rapport de bug, demande de fonctionnalité, pull request.

## [1.0.1] - 2026-06-16

### Ajouté
- **Commande Artisan** `laraprint:printers` (`Console\PrintersCommand`) : `list`, `add`,
  `default` (avec `--machine`), `import`, `remove`, `test`.
- Fichiers `CONTRIBUTING.md` et `PUBLISHING.md` (guide de publication Packagist).

## [1.0.0] - 2026-06-16

Première version stable. Inclut l'ensemble des fonctionnalités ci-dessous.

### Ajouté
- Impression directe `DirectPrinter` (texte, données brutes, ESC/POS, fichiers).
- Tickets de caisse `Thermal\ThermalPrinter` + `Thermal\ReceiptData`.
- Connecteurs `Connector\ConnectorFactory` / `Connector\PrinterConnectionConfig`
  (network, windows, cups, smb, file/usb).
- Découverte des imprimantes du poste `Discovery\SystemPrinters` (Windows PowerShell/WMIC, CUPS).
- Impression via spouleur OS `Printing\SpooledFilePrint` + `Laraprint::printFile()`.
- Utilitaires `Support\PaperSize`, `Support\ReceiptConfig`, `Support\PrinterType`.
- Modèles Eloquent `Workstation`, `Printer`, `PrinterCredential` et migrations publiables.
- Configuration publiable `config/laraprint.php`.
- **Gestion des imprimantes en base** via `Printers\PrinterRegistry` : lister (`all`, `active`),
  rechercher (`find`, `findByName`), ajouter (`register`), importer depuis l'OS
  (`importSystemPrinters`), supprimer (`forget`).
- **Choix de l'imprimante avant impression** : `PrinterRegistry::printer()`,
  `thermalPrinter()` et `connectionConfig()` acceptent une instance, un id, un nom, ou `null`.
- **Imprimante par défaut liée à la machine et à la session** via `Printers\CurrentWorkstation` :
  `defaultForCurrent()`, `setDefaultForCurrent()`, `currentWorkstation()`, `selectForSession()`,
  `clearSessionSelection()`. Ordre de résolution : session → défaut machine → défaut global.
- **Unicité du défaut garantie par périmètre** : `Printer::makeDefault()` et scope `forWorkstation()`.
- **Événements & logs optionnels** autour de chaque job : `Events\PrintJobStarted`,
  `Events\PrintJobCompleted`, `Events\PrintJobFailed`, via le pont `Support\Telemetry`
  (no-op silencieux hors d'une application Laravel).
- **Raccourcis de façade** : `Laraprint::printers()`, `registerPrinter()`, `setDefaultPrinter()`,
  `defaultPrinter()`, `setMachineDefaultPrinter()`, `currentWorkstation()`,
  `selectPrinterForSession()`, `usePrinter()`.
- **Suite de tests** PHPUnit (`tests/Unit` + `tests/Feature`, SQLite en mémoire) et
  configuration `phpunit.xml`.
- Fichiers `LICENSE` (MIT), `CHANGELOG.md` et documentation README étendue (FR + EN).

### Modifié
- `Models\Printer::getConnectionConfig()` renvoie désormais une configuration **prête pour le SDK**
  (`connection_type`, `settings`, `name`, `printer_type`, `is_active`, `credentials`).
- Migration `printers` : `workstation_id` rendu **nullable**, ajout de la colonne `printer_type`,
  ajout de la valeur `file` à l'énumération `connection_type`.
- Migration `workstations` : ajout de la colonne `hostname` (unique, nullable) pour identifier la machine.
- Configuration : ajout du bloc `workstation.identifier` (`LARAPRINT_WORKSTATION`).
- `LaraprintServiceProvider` : enregistrement du singleton `PrinterRegistry`.

### Corrigé
- Alignement du nom de package dans le README (`neocode/laraprint`).
- Suppression d'un fichier artefact accidentel à la racine et nettoyage du `.gitignore`.

[1.2.0]: https://github.com/neocodesupport/laraprint/releases/tag/v1.2.0
[1.1.0]: https://github.com/neocodesupport/laraprint/releases/tag/v1.1.0
[1.0.1]: https://github.com/neocodesupport/laraprint/releases/tag/v1.0.1
[1.0.0]: https://github.com/neocodesupport/laraprint/releases/tag/v1.0.0
