<?php

declare(strict_types=1);

namespace Neocode\Laraprint;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Neocode\Laraprint\Connector\ConnectorFactory;
use Neocode\Laraprint\Connector\PrinterConnectionConfig;
use Neocode\Laraprint\Discovery\LocalPrinters;
use Neocode\Laraprint\Discovery\MdnsScanner;
use Neocode\Laraprint\Discovery\NetworkScanner;
use Neocode\Laraprint\Discovery\SnmpQuery;
use Neocode\Laraprint\Discovery\SystemPrinters;
use Neocode\Laraprint\Jobs\PrintJob;
use Neocode\Laraprint\Models\Printer;
use Neocode\Laraprint\Models\PrintJobRecord;
use Neocode\Laraprint\Models\Workstation;
use Neocode\Laraprint\Printers\PrinterRegistry;
use Neocode\Laraprint\Printing\IppClient;
use Neocode\Laraprint\Printing\SpooledFilePrint;
use Neocode\Laraprint\Support\PrinterStatus;
use Neocode\Laraprint\Support\PrinterType;
use Neocode\Laraprint\Support\ReceiptConfig;
use Neocode\Laraprint\Testing\PrintRecorder;
use Neocode\Laraprint\Thermal\ReceiptBuilder;
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
     * Builder fluide de ticket (logo, code-barres, QR, alignement…).
     *
     * @param  array<string, mixed>  $connectionConfig
     */
    public static function build(array $connectionConfig): ReceiptBuilder
    {
        return ReceiptBuilder::make($connectionConfig);
    }

    /**
     * Envoie des octets bruts à l'imprimante **sans** initialisation ESC/POS.
     * Utile pour les protocoles non-ESC/POS (ex. ZPL/EPL Zebra).
     *
     * @param  array<string, mixed>  $connectionConfig
     */
    public static function sendRaw(array $connectionConfig, string $data): void
    {
        $connector = ConnectorFactory::fromArray($connectionConfig);
        $connector->write($data);
        $connector->finalize();
    }

    /**
     * Active le mode test : les impressions sont capturées au lieu d'être envoyées.
     * Renvoie l'enregistreur pour les assertions (`assertPrinted`, `assertPrintedContains`…).
     */
    public static function fake(): PrintRecorder
    {
        return PrintRecorder::instance()->enable();
    }

    /**
     * Ouvre le tiroir-caisse relié à l'imprimante.
     *
     * @param  array<string, mixed>  $connectionConfig
     */
    public static function openCashDrawer(array $connectionConfig, int $pin = 0): void
    {
        DirectPrinter::forPrinter($connectionConfig)->openCashDrawer($pin)->close();
    }

    /**
     * Interroge l'état temps réel d'une imprimante (best-effort, réseau/périphérique).
     *
     * @param  array<string, mixed>  $connectionConfig
     */
    public static function printerStatus(array $connectionConfig): PrinterStatus
    {
        $printer = DirectPrinter::forPrinter($connectionConfig);
        $status = $printer->queryStatus();
        $printer->close();

        return $status;
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
     * Découverte mDNS / Bonjour (AirPrint) des imprimantes du réseau local.
     *
     * @return list<array{connection_type: string, settings: array<string, mixed>, name: string, printer_type: ?string}>
     */
    public static function discoverAirPrint(float $timeout = 2.0): array
    {
        return (new MdnsScanner)->discover($timeout);
    }

    /**
     * Interroge une imprimante réseau via SNMP (modèle, statut, compteur, consommables).
     *
     * @return array<string, int|string|null>
     */
    public static function snmp(string $host, string $community = 'public', float $timeout = 1.0): array
    {
        return (new SnmpQuery)->query($host, $community, $timeout);
    }

    /**
     * Imprime un document via IPP (imprimantes AirPrint / IPP, port 631).
     *
     * @param  string  $uri  Ex. `ipp://192.168.1.50:631/ipp/print`.
     */
    public static function printIpp(string $uri, string $data, string $documentFormat = 'application/octet-stream'): bool
    {
        return (new IppClient)->printJob($uri, $data, $documentFormat);
    }

    /**
     * Découverte combinée : imprimantes du système + USB locales (+ réseau / + AirPrint si demandé).
     *
     * @return list<array{connection_type: string, settings: array<string, mixed>, name: string, printer_type?: string}>
     */
    public static function discoverPrinters(bool $network = false, ?string $range = null, bool $airprint = false): array
    {
        return array_merge(
            SystemPrinters::listPrinters(),
            LocalPrinters::listUsb(),
            $network ? (new NetworkScanner)->scan($range) : [],
            $airprint ? (new MdnsScanner)->discover() : [],
        );
    }

    /**
     * Met en file d'attente une impression de texte.
     *
     * @param  array<string, mixed>  $config
     */
    public static function queueText(array $config, string $text, bool $cut = true): mixed
    {
        return self::dispatchPrintJob(PrintJob::text($config, $text, $cut));
    }

    /**
     * Met en file d'attente l'impression d'un fichier.
     *
     * @param  array<string, mixed>  $config
     */
    public static function queueFile(array $config, string $path, bool $asText = false): mixed
    {
        return self::dispatchPrintJob(PrintJob::file($config, $path, $asText), ['path' => $path]);
    }

    /**
     * Met en file d'attente l'impression d'un ticket de caisse.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $receiptConfig
     */
    public static function queueReceipt(array $config, array $data, ?array $receiptConfig = null): mixed
    {
        $context = isset($data['sale_number']) ? ['sale_number' => $data['sale_number']] : [];

        return self::dispatchPrintJob(PrintJob::receipt($config, $data, $receiptConfig), $context);
    }

    /**
     * Crée une trace `print_jobs` (si la table existe) puis dispatche le job.
     *
     * @param  array<string, mixed>  $context
     */
    private static function dispatchPrintJob(PrintJob $job, array $context = []): mixed
    {
        $job->recordId = self::trackPrintJob($job, $context);

        return dispatch($job);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function trackPrintJob(PrintJob $job, array $context): ?int
    {
        try {
            if (! Schema::hasTable('print_jobs')) {
                return null;
            }

            $record = PrintJobRecord::query()->create([
                'uuid' => (string) Str::uuid(),
                'printer_id' => $job->connectionConfig['id'] ?? null,
                'kind' => $job->kind,
                'status' => PrintJobRecord::STATUS_QUEUED,
                'context' => $context,
            ]);

            return (int) $record->id;
        } catch (\Throwable) {
            return null;
        }
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
