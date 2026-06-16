<?php

declare(strict_types=1);

namespace Neocode\Laraprint;

use Mike42\Escpos\PrintConnectors\PrintConnector;
use Mike42\Escpos\Printer as EscposPrinter;
use Neocode\Laraprint\Connector\ConnectorFactory;
use Neocode\Laraprint\Connector\PrinterConnectionConfig;
use Neocode\Laraprint\Events\PrintJobCompleted;
use Neocode\Laraprint\Events\PrintJobFailed;
use Neocode\Laraprint\Events\PrintJobStarted;
use Neocode\Laraprint\Printing\SpooledFilePrint;
use Neocode\Laraprint\Support\ConnectionType;
use Neocode\Laraprint\Support\PrinterStatus;
use Neocode\Laraprint\Support\PrinterType;
use Neocode\Laraprint\Support\Telemetry;

/**
 * Impression directe sur l'imprimante de votre choix.
 *
 * Permet d'envoyer du texte, des commandes ESC/POS ou tout contenu brut vers
 * n'importe quelle imprimante configurée (réseau, Windows, CUPS, SMB, USB, fichier).
 * Utilisable pour tickets, étiquettes, reçus, rapports, etc. — indépendant du POS.
 */
class DirectPrinter
{
    private EscposPrinter $printer;

    private PrintConnector $connector;

    private bool $closed = false;

    /** @var array{connection_type?: string, type?: string, settings?: array<string, mixed>, name?: string, is_active?: bool, printer_type?: string} */
    private array $connectionConfig;

    /**
     * Crée une instance pour l'imprimante ciblée par la configuration.
     *
     * @param  array{connection_type?: string, type?: string, settings?: array<string, mixed>, name?: string, is_active?: bool}  $connectionConfig
     */
    public static function forPrinter(array $connectionConfig): self
    {
        $connector = ConnectorFactory::fromArray($connectionConfig);

        return new self($connector, $connectionConfig);
    }

    public function __construct(
        PrintConnector $connector,
        array $connectionConfig = [],
    ) {
        $this->connector = $connector;
        $this->printer = new EscposPrinter($connector);
        $this->connectionConfig = $connectionConfig;
    }

    /**
     * Envoie du texte brut vers l'imprimante (encodage UTF-8, compatible ESC/POS).
     */
    public function printText(string $text): self
    {
        $this->ensureOpen();
        $this->printer->text($text);

        return $this;
    }

    /**
     * Envoie des données brutes (octets) vers l'imprimante.
     * Utile pour commandes ESC/POS personnalisées ou protocoles spécifiques.
     */
    public function printRaw(string $data): self
    {
        $this->ensureOpen();
        $this->connector->write($data);

        return $this;
    }

    /**
     * Lit un fichier et l'envoie vers l'imprimante.
     *
     * - **$asText = false (défaut)** : envoi octet par octet (fichier .bin ESC/POS,
     *   capture brute, etc.) par blocs pour limiter la mémoire.
     * - **$asText = true** : contenu interprété comme texte UTF-8 via {@see printText()}
     *   (fichiers .txt, ticket généré en texte).
     *
     * Si la config correspond à une imprimante bureautique (PDF/Word/Excel, etc.),
     * {@see PrinterType::WindowsSpoolDocument}/{@see PrinterType::CupsSpoolDocument},
     * l'impression est déléguée au spouleur OS.
     *
     * Pour une imprimante thermique ESC/POS (ou l'envoi brut ESC/POS), le fichier est
     * envoyé en texte/binaire directement sur le flux.
     */
    public function printFile(string $path, bool $asText = false, ?PrinterType $printerType = null): self
    {
        $this->ensurePathIsReadableFile($path);

        // Si on est sur une imprimante "document" (PDF/Word/Excel), on doit laisser Windows/CUPS
        // convertir via pilotes, sinon l'envoi ESC/POS brut produit du charabia.
        $cfg = PrinterConnectionConfig::fromArray($this->connectionConfig);
        $effectiveType = $printerType
            ?? $cfg->printerType
            ?? ConnectionType::inferPrinterType($cfg->connectionType);

        if ($effectiveType === PrinterType::WindowsSpoolDocument || $effectiveType === PrinterType::CupsSpoolDocument) {
            SpooledFilePrint::submit($path, $this->connectionConfig);

            // On évite de finaliser un job ESC/POS "vide" côté escpos-php.
            $this->closed = true;

            return $this;
        }

        // Stratégie thermique : texte UTF-8 ou octets ESC/POS.
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $docLikeExt = [
            'pdf', 'png', 'jpg', 'jpeg', 'gif', 'bmp', 'tif', 'tiff',
            'doc', 'docx', 'rtf',
            'xls', 'xlsx',
        ];
        if (! $asText && in_array($ext, $docLikeExt, true)) {
            throw new \RuntimeException(
                sprintf(
                    'Fichier bureautique (%s) non imprimable directement en mode ESC/POS. '.
                    'Utilisez une imprimante avec `printer_type` Windows/CUPS spouleur (ou Laraprint::printFile).',
                    $ext
                )
            );
        }

        if ($asText) {
            $content = file_get_contents($path);
            if ($content === false) {
                throw new \RuntimeException(sprintf('Impossible de lire le fichier : %s', $path));
            }

            return $this->printText($content);
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Impossible d’ouvrir le fichier : %s', $path));
        }
        try {
            while (! feof($handle)) {
                $chunk = fread($handle, 65536);
                if ($chunk === false) {
                    break;
                }
                if ($chunk !== '') {
                    $this->printRaw($chunk);
                }
            }
        } finally {
            fclose($handle);
        }

        return $this;
    }

    /**
     * Envoie un fichier puis ferme la connexion.
     */
    public function printFileAndClose(string $path, bool $asText = false): bool
    {
        $context = ['path' => $path, 'as_text' => $asText];
        Telemetry::event(new PrintJobStarted('direct.file', $this->connectionConfig, $context));

        try {
            $this->printFile($path, $asText);
            if (! $this->closed) {
                $this->close();
            }

            Telemetry::event(new PrintJobCompleted('direct.file', $this->connectionConfig, $context));

            return true;
        } catch (\Throwable $e) {
            $this->close();
            Telemetry::event(new PrintJobFailed('direct.file', $e, $this->connectionConfig, $context));
            Telemetry::log('error', 'Échec impression fichier '.$path.' : '.$e->getMessage(), $context);
            throw $e;
        }
    }

    /**
     * Saut de ligne.
     */
    public function feed(int $lines = 1): self
    {
        $this->ensureOpen();
        $this->printer->feed($lines);

        return $this;
    }

    /**
     * Coupe le papier (imprimantes thermiques).
     */
    public function cut(int $mode = EscposPrinter::CUT_FULL, int $lines = 3): self
    {
        $this->ensureOpen();
        $this->printer->cut($mode, $lines);

        return $this;
    }

    /**
     * Ouvre le tiroir-caisse (impulsion ESC/POS sur la broche indiquée).
     */
    public function openCashDrawer(int $pin = 0, int $onMs = 120, int $offMs = 240): self
    {
        $this->ensureOpen();
        $this->printer->pulse($pin, $onMs, $offMs);

        return $this;
    }

    /**
     * Interroge l'état temps réel de l'imprimante (DLE EOT 1..4).
     *
     * Best-effort : nécessite un connecteur capable de lire (réseau, fichier périphérique).
     * Les champs inconnus valent `null`.
     */
    public function queryStatus(): PrinterStatus
    {
        $this->ensureOpen();

        return PrinterStatus::decode(
            $this->realtimeStatus(1),
            $this->realtimeStatus(2),
            $this->realtimeStatus(3),
            $this->realtimeStatus(4),
        );
    }

    private function realtimeStatus(int $n): ?int
    {
        $this->connector->write("\x10\x04".chr($n));
        $response = $this->connector->read(1);

        if (! is_string($response) || $response === '') {
            return null;
        }

        return ord($response[0]);
    }

    /**
     * Ferme la connexion à l'imprimante (à appeler en fin d'impression).
     */
    public function close(): void
    {
        if (! $this->closed) {
            $this->printer->close();
            $this->closed = true;
        }
    }

    /**
     * Retourne l'instance ESC/POS pour un contrôle complet (graphiques, code-barres, etc.).
     */
    public function getEscposPrinter(): EscposPrinter
    {
        return $this->printer;
    }

    /**
     * Imprime le texte puis ferme proprement la connexion.
     */
    public function printTextAndClose(string $text): bool
    {
        Telemetry::event(new PrintJobStarted('direct.text', $this->connectionConfig));

        try {
            $this->printText($text);
            $this->close();

            Telemetry::event(new PrintJobCompleted('direct.text', $this->connectionConfig));

            return true;
        } catch (\Throwable $e) {
            $this->close();
            Telemetry::event(new PrintJobFailed('direct.text', $e, $this->connectionConfig));
            throw $e;
        }
    }

    /**
     * Teste la connexion (ouvre et ferme sans imprimer).
     */
    public function testConnection(): bool
    {
        try {
            $this->close();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function ensureOpen(): void
    {
        if ($this->closed) {
            throw new \RuntimeException('La connexion à l\'imprimante est déjà fermée. Créez une nouvelle instance pour imprimer.');
        }
    }

    private function ensurePathIsReadableFile(string $path): void
    {
        if (! is_file($path)) {
            throw new \InvalidArgumentException(sprintf('Le chemin n’est pas un fichier : %s', $path));
        }
        if (! is_readable($path)) {
            throw new \RuntimeException(sprintf('Fichier illisible : %s', $path));
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
