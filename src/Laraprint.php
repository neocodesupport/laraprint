<?php

declare(strict_types=1);

namespace Neocode\Laraprint;

use Neocode\Laraprint\Connector\ConnectorFactory;
use Neocode\Laraprint\Connector\PrinterConnectionConfig;
use Neocode\Laraprint\Discovery\LocalPrinters;
use Neocode\Laraprint\Discovery\NetworkScanner;
use Neocode\Laraprint\Discovery\SystemPrinters;
use Neocode\Laraprint\Models\Printer;
use Neocode\Laraprint\Models\Workstation;
use Neocode\Laraprint\Printers\PrinterRegistry;
use Neocode\Laraprint\Printing\SpooledFilePrint;
use Neocode\Laraprint\Support\PrinterType;
use Neocode\Laraprint\Support\ReceiptConfig;
use Neocode\Laraprint\Thermal\ReceiptData;
use Neocode\Laraprint\Thermal\ThermalPrinter;

/**
 * Point d'entrée principal du SDK Laraprint.
 *
 * Le SDK permet d'imprimer sur tout type d'imprimante (réseau, Windows, CUPS, SMB, USB, fichier)
 * en ciblant l'imprimante de votre choix — pas seulement en contexte POS.
 */
final class Laraprint
{
    /**
     * Impression directe sur l'imprimante choisie (texte, brut, commandes ESC/POS).
     */
    public static function printer(array $connectionConfig): DirectPrinter
    {
        return DirectPrinter::forPrinter($connectionConfig);
    }

    /**
     * Crée un connector à partir d'un tableau de configuration.
     */
    public static function connector(array $connectionConfig): mixed
    {
        return ConnectorFactory::fromArray($connectionConfig);
    }

    /**
     * Crée une config de connexion à partir d'un tableau.
     */
    public static function connectionConfig(array $data): PrinterConnectionConfig
    {
        return PrinterConnectionConfig::fromArray($data);
    }

    /**
     * Crée une config ticket à partir d'un tableau.
     */
    public static function receiptConfig(array $data): ReceiptConfig
    {
        return ReceiptConfig::fromArray($data);
    }

    /**
     * Crée des données de ticket à partir d'un tableau.
     */
    public static function receiptData(array $data): ReceiptData
    {
        return ReceiptData::fromArray($data);
    }

    /**
     * Crée une instance ThermalPrinter à partir des configs de connexion et ticket.
     */
    public static function thermalPrinter(
        array $connectionConfig,
        array|ReceiptConfig $receiptConfig,
    ): ThermalPrinter {
        return ThermalPrinter::fromConnectionConfig($connectionConfig, $receiptConfig);
    }

    /**
     * Retourne toutes les imprimantes configurées sur le poste (Windows ou CUPS).
     *
     * Chaque élément est un tableau de configuration utilisable avec printer(), connector()
     * ou ThermalPrinter::fromConnectionConfig() :
     * ['connection_type' => 'windows'|'cups', 'settings' => [...], 'name' => '...']
     *
     * @return list<array{connection_type: string, settings: array<string, mixed>, name: string}>
     */
    public static function listLocalPrinters(): array
    {
        return SystemPrinters::listPrinters();
    }

    /**
     * Découvre les imprimantes **connectées localement** (USB / port parallèle).
     *
     * @return list<array{connection_type: string, settings: array<string, mixed>, name: string, printer_type: string}>
     */
    public static function listUsbPrinters(): array
    {
        return LocalPrinters::listUsb();
    }

    /**
     * Scanne le **réseau** local pour détecter des imprimantes (port 9100 par défaut).
     *
     * @param  string|null  $range  Plage CIDR/intervalle/IP, ou null pour le /24 local.
     * @param  list<int>  $ports  Ports à tester.
     * @return list<array{connection_type: string, settings: array<string, mixed>, name: string, printer_type: string}>
     */
    public static function scanNetworkPrinters(?string $range = null, array $ports = [9100], float $timeout = 0.3): array
    {
        return (new NetworkScanner)->scan($range, $ports, $timeout);
    }

    /**
     * Découverte combinée : imprimantes du système + USB locales (+ réseau si demandé).
     *
     * @return list<array{connection_type: string, settings: array<string, mixed>, name: string, printer_type?: string}>
     */
    public static function discoverPrinters(bool $network = false, ?string $range = null): array
    {
        return array_merge(
            SystemPrinters::listPrinters(),
            LocalPrinters::listUsb(),
            $network ? (new NetworkScanner)->scan($range) : [],
        );
    }

    /**
     * Registre des imprimantes enregistrées (persistées en base) :
     * lister, choisir, ajouter, importer, définir le défaut, imprimer.
     *
     * Nécessite les migrations du SDK. Exemples :
     *   Laraprint::printers()->register([...]);
     *   Laraprint::printers()->setDefault($id);
     *   Laraprint::printers()->printer($idOuNomOuNull)->printText('...')->cut()->close();
     */
    public static function printers(): PrinterRegistry
    {
        return new PrinterRegistry;
    }

    /**
     * Enregistre une nouvelle imprimante (raccourci de {@see PrinterRegistry::register()}).
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|null  $credentials
     */
    public static function registerPrinter(array $attributes, ?array $credentials = null): Printer
    {
        return self::printers()->register($attributes, $credentials);
    }

    /**
     * Définit l'imprimante par défaut (raccourci de {@see PrinterRegistry::setDefault()}).
     */
    public static function setDefaultPrinter(Printer|int $printer): Printer
    {
        return self::printers()->setDefault($printer);
    }

    /**
     * Imprimante par défaut de la **machine courante** (repli sur le défaut global).
     * Si $workstationId est fourni, renvoie le défaut de ce poste précis.
     */
    public static function defaultPrinter(?int $workstationId = null): ?Printer
    {
        return $workstationId !== null
            ? self::printers()->default($workstationId)
            : self::printers()->defaultForCurrent();
    }

    /**
     * Définit l'imprimante par défaut pour la **machine courante**
     * (l'imprimante est rattachée au poste courant, créé si besoin).
     */
    public static function setMachineDefaultPrinter(Printer|int $printer): Printer
    {
        return self::printers()->setDefaultForCurrent($printer);
    }

    /**
     * Le poste (ordinateur) courant, identifié par nom d'hôte / session / config.
     */
    public static function currentWorkstation(): ?Workstation
    {
        return self::printers()->currentWorkstation();
    }

    /**
     * Choisit une imprimante pour la **session** courante (surcharge le défaut machine).
     */
    public static function selectPrinterForSession(Printer|int|string $printer): Printer
    {
        return self::printers()->selectForSession($printer);
    }

    /**
     * Retourne un DirectPrinter pour l'imprimante enregistrée choisie.
     * Si $printer est null : imprimante de session, sinon défaut machine, sinon défaut global.
     */
    public static function usePrinter(Printer|int|string|null $printer = null): DirectPrinter
    {
        return self::printers()->printer($printer);
    }

    /**
     * Envoie un fichier vers l’imprimante via le spouleur système (CUPS ou Windows pilote).
     *
     * Utile pour PDF, images, etc. sous CUPS. Pour réseau 9100 ou fichier brut ESC/POS,
     * utilisez printer($config)->printFile($chemin).
     *
     * @param  array{connection_type?: string, type?: string, settings?: array<string, mixed>, name?: string, is_active?: bool}  $connectionConfig
     */
    public static function spoolFile(string $path, array $connectionConfig): void
    {
        SpooledFilePrint::submit($path, $connectionConfig);
    }

    /**
     * Imprime un fichier en choisissant automatiquement la stratégie :
     * - `PrinterType::ThermalEscposRaw` : envoi brut ESC/POS via {@see DirectPrinter::printFile()}
     * - `PrinterType::WindowsSpoolDocument` / `PrinterType::CupsSpoolDocument` : via le spouleur OS (pilotes)
     *
     * @param  bool  $asText  Interprète le fichier comme du texte UTF-8 (utile pour `.txt` / tickets texte).
     */
    public static function printFile(
        string $path,
        array $connectionConfig,
        bool $asText = false,
        ?PrinterType $printerType = null,
    ): void {
        $cfg = PrinterConnectionConfig::fromArray($connectionConfig);

        $effectiveType = $printerType ?? $cfg->printerType;
        if ($effectiveType === null) {
            $effectiveType = match ($cfg->connectionType) {
                'windows' => PrinterType::WindowsSpoolDocument,
                'cups', 'smb' => PrinterType::CupsSpoolDocument,
                default => PrinterType::ThermalEscposRaw,
            };
        }

        match ($effectiveType) {
            PrinterType::ThermalEscposRaw => DirectPrinter::forPrinter($connectionConfig)
                ->printFileAndClose($path, $asText),
            PrinterType::WindowsSpoolDocument,
            PrinterType::CupsSpoolDocument => self::spoolFile($path, $connectionConfig),
        };
    }
}
