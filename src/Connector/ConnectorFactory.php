<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Connector;

use Mike42\Escpos\PrintConnectors\CupsPrintConnector;
use Mike42\Escpos\PrintConnectors\FilePrintConnector;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;
use Mike42\Escpos\PrintConnectors\PrintConnector;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Neocode\Laraprint\Testing\CaptureConnector;
use Neocode\Laraprint\Testing\PrintRecorder;
use RuntimeException;

/**
 * Crée un connector ESC/POS à partir d'une configuration (array ou PrinterConnectionConfig).
 */
class ConnectorFactory
{
    /**
     * Crée un connector à partir d'un tableau de configuration.
     *
     * @param  array{connection_type?: string, type?: string, settings?: array<string, mixed>}  $config
     */
    public static function fromArray(array $config): PrintConnector
    {
        return self::fromConfig(PrinterConnectionConfig::fromArray($config));
    }

    public static function fromConfig(PrinterConnectionConfig $config): PrintConnector
    {
        if (! $config->isActive) {
            throw new RuntimeException("L'imprimante '{$config->name}' est désactivée.");
        }

        // Mode test : on capture le contenu au lieu d'imprimer réellement.
        if (PrintRecorder::isFaking()) {
            return new CaptureConnector($config->toArray());
        }

        $settings = $config->settings;
        if (! is_array($settings)) {
            $settings = [];
        }

        return match ($config->connectionType) {
            'network' => self::createNetworkConnector($settings),
            'windows' => self::createWindowsConnector($settings),
            'cups' => self::createCupsConnector($settings),
            'smb' => self::createSmbConnector($settings),
            'usb', 'file' => self::createFileConnector($settings),
            default => throw new RuntimeException("Type de connexion non supporté: {$config->connectionType}"),
        };
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private static function createNetworkConnector(array $settings): NetworkPrintConnector
    {
        $ip = $settings['ip'] ?? throw new RuntimeException("Adresse IP manquante pour l'imprimante réseau.");
        $port = (int) ($settings['port'] ?? 9100);
        $timeout = (int) ($settings['timeout'] ?? 5);

        return new NetworkPrintConnector($ip, $port, $timeout);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private static function createWindowsConnector(array $settings): WindowsPrintConnector
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new RuntimeException(
                'Les imprimantes Windows ne sont disponibles que sur les systèmes Windows. '
                .'Sur '.PHP_OS_FAMILY.', utilisez le type "cups" ou "network".'
            );
        }

        $printerName = $settings['printer_name'] ?? throw new RuntimeException("Nom d'imprimante Windows manquant.");
        if (empty($printerName) || ! is_string($printerName)) {
            throw new RuntimeException("Le nom d'imprimante Windows doit être une chaîne non vide.");
        }

        try {
            return new WindowsPrintConnector($printerName);
        } catch (\TypeError $e) {
            throw new RuntimeException(
                "Erreur lors de la connexion à l'imprimante Windows '{$printerName}'. "
                .'Vérifiez que l\'imprimante existe et que vous avez les permissions nécessaires.'
            );
        } catch (\Exception $e) {
            throw new RuntimeException(
                "Impossible de se connecter à l'imprimante Windows '{$printerName}'. "
                .'Erreur: '.$e->getMessage()
            );
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private static function createCupsConnector(array $settings): CupsPrintConnector
    {
        $cupsName = $settings['cups_name'] ?? throw new RuntimeException('Nom CUPS manquant.');

        return new CupsPrintConnector($cupsName);
    }

    /**
     * SMB utilise CUPS avec le nom CUPS (PHP n'a pas de wrapper SMB natif).
     *
     * @param  array<string, mixed>  $settings
     */
    private static function createSmbConnector(array $settings): CupsPrintConnector
    {
        $cupsName = $settings['cups_name'] ?? null;
        if (empty($cupsName)) {
            $ip = $settings['ip'] ?? 'N/A';
            $shareName = $settings['share_name'] ?? 'N/A';
            throw new RuntimeException(
                "Le nom CUPS n'est pas configuré pour cette imprimante SMB. "
                ."Configurez l'imprimante dans CUPS avec le partage SMB ({$ip}/{$shareName})."
            );
        }

        return new CupsPrintConnector($cupsName);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private static function createFileConnector(array $settings): FilePrintConnector
    {
        $path = $settings['path'] ?? $settings['device_path'] ?? null;
        if (empty($path)) {
            throw new RuntimeException('Chemin du fichier ou périphérique manquant (path ou device_path).');
        }

        return new FilePrintConnector($path);
    }
}
