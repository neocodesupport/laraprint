# Changelog

Toutes les modifications notables de ce projet sont documentées dans ce fichier.

Le format s'appuie sur [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/),
et ce projet suit le [versionnage sémantique](https://semver.org/lang/fr/).

## [Non publié]

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

[1.0.0]: https://github.com/neocodesupport/laraprint/releases/tag/v1.0.0
