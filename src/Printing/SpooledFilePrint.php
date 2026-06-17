<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Printing;

use InvalidArgumentException;
use Neocode\Laraprint\Connector\PrinterConnectionConfig;
use Neocode\Laraprint\DirectPrinter;
use Neocode\Laraprint\Events\PrintJobCompleted;
use Neocode\Laraprint\Events\PrintJobFailed;
use Neocode\Laraprint\Events\PrintJobStarted;
use Neocode\Laraprint\Support\Telemetry;
use Neocode\Laraprint\Testing\PrintRecorder;
use RuntimeException;

/**
 * Envoie un fichier vers une imprimante via le spouleur du système d'exploitation.
 *
 * - **CUPS** (Linux/macOS, type `cups` ou `smb` avec `cups_name`) : `lp -d queue fichier`
 *   → CUPS peut convertir PDF, images, etc. selon les pilotes installés.
 * - **Windows** (type `windows`) : impression déléguée au pilote Windows via PowerShell
 *   (`Start-Process -Verb Print`) en basculant temporairement sur l’imprimante
 *   par défaut correspondante.
 *   → adapté aux fichiers bureautiques (PDF/Word/Excel) *si* les pilotes/handlers
 *   Windows sont installés et savent traiter le format.
 *
 * Pour envoyer un fichier **directement** sur le flux ESC/POS (réseau 9100, fichier
 * périphérique, etc.), utilisez {@see DirectPrinter::printFile()}.
 */
final class SpooledFilePrint
{
    /**
     * Soumet le fichier à la file d'impression système pour l'imprimante indiquée.
     *
     * @param  array{connection_type?: string, type?: string, settings?: array<string, mixed>, name?: string, is_active?: bool}  $connectionConfig
     */
    public static function submit(string $path, array $connectionConfig): void
    {
        // Mode test : on enregistre l'intention au lieu d'appeler le spouleur.
        if (PrintRecorder::isFaking()) {
            PrintRecorder::instance()->record($connectionConfig, $path, 'spool.file');

            return;
        }

        $real = realpath($path);
        if ($real === false || ! is_file($real) || ! is_readable($real)) {
            throw new RuntimeException(sprintf('Fichier introuvable ou illisible : %s', $path));
        }

        $config = PrinterConnectionConfig::fromArray($connectionConfig);
        if (! $config->isActive) {
            $label = $config->name ?? $config->connectionType;
            throw new RuntimeException(sprintf("L'imprimante « %s » est désactivée.", $label));
        }

        $settings = $config->settings;
        if (! is_array($settings)) {
            $settings = [];
        }

        $context = ['path' => $real];
        Telemetry::event(new PrintJobStarted('spool.file', $connectionConfig, $context));
        Telemetry::log('info', 'Envoi du fichier au spouleur : '.$real, $context);

        try {
            match ($config->connectionType) {
                'cups', 'smb' => self::submitCups($real, $settings),
                'windows' => self::submitWindows($real, $settings),
                default => throw new InvalidArgumentException(
                    sprintf(
                        'Le type de connexion « %s » ne prend pas en charge l’envoi via le spouleur système. '
                        .'Utilisez DirectPrinter::forPrinter($config)->printFile($chemin) pour réseau, fichier/USB, etc.',
                        $config->connectionType
                    )
                ),
            };
        } catch (\Throwable $e) {
            Telemetry::event(new PrintJobFailed('spool.file', $e, $connectionConfig, $context));
            Telemetry::log('error', 'Échec spouleur : '.$e->getMessage(), $context);
            throw $e;
        }

        Telemetry::event(new PrintJobCompleted('spool.file', $connectionConfig, $context));
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private static function submitCups(string $absolutePath, array $settings): void
    {
        $cupsName = $settings['cups_name'] ?? null;
        if ($cupsName === null || $cupsName === '') {
            throw new RuntimeException(
                'Nom CUPS (settings.cups_name) manquant. Pour SMB, configurez la file CUPS correspondante.'
            );
        }
        $cmd = sprintf(
            'lp -d %s %s',
            escapeshellarg((string) $cupsName),
            escapeshellarg($absolutePath)
        );
        self::runCommand($cmd);
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private static function submitWindows(string $absolutePath, array $settings): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new RuntimeException(
                'L’impression Windows (PowerShell) n’est disponible que sous Windows.'
            );
        }
        $printerName = $settings['printer_name'] ?? null;
        if ($printerName === null || $printerName === '' || ! is_string($printerName)) {
            throw new RuntimeException('Nom d’imprimante Windows (settings.printer_name) manquant.');
        }

        // Les PDF n'ont pas de verbe shell « Print » fiable sous Windows
        // (Chrome/Edge ne l'exposent pas) : on passe par un utilitaire dédié.
        if (strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION)) === 'pdf') {
            self::printWindowsPdf($absolutePath, $printerName);

            return;
        }

        self::printWindowsViaShell($absolutePath, $printerName);
    }

    /**
     * Imprime un PDF sous Windows via un utilitaire silencieux (SumatraPDF,
     * PDFtoPrinter, ou commande personnalisée). À défaut, tente le verbe shell
     * « Print » et, en cas d'échec, lève une erreur explicite et actionnable.
     */
    private static function printWindowsPdf(string $absolutePath, string $printerName): void
    {
        $command = self::resolveWindowsPdfCommand($absolutePath, $printerName);

        if ($command !== null) {
            self::runCommand($command);

            return;
        }

        try {
            self::printWindowsViaShell($absolutePath, $printerName);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "Impossible d'imprimer le PDF via le spouleur Windows : aucun gestionnaire « Imprimer » "
                ."n'est associé aux fichiers .pdf (Chrome/Edge n'en fournissent pas). Installez un utilitaire "
                ."d'impression PDF silencieux (SumatraPDF ou PDFtoPrinter) puis renseignez "
                .'`config laraprint.connection.windows.pdf_print_bin` (LARAPRINT_WINDOWS_PDF_BIN), '
                .'ou utilisez une imprimante réseau (IPP/CUPS). Détail : '.$e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * Construit la commande d'impression PDF Windows à partir de la configuration
     * (commande personnalisée > binaire fourni > auto-détection). Null si aucun
     * utilitaire n'est disponible.
     */
    private static function resolveWindowsPdfCommand(string $file, string $printer): ?string
    {
        $config = function_exists('config') ? (array) config('laraprint.connection.windows', []) : [];

        // 1. Gabarit de commande explicite, jetons {printer} et {file}.
        $template = $config['pdf_print_command'] ?? null;
        if (is_string($template) && $template !== '') {
            return strtr($template, [
                '{printer}' => escapeshellarg($printer),
                '{file}' => escapeshellarg($file),
            ]);
        }

        // 2. Binaire explicite, sinon auto-détection (SumatraPDF / PDFtoPrinter).
        $bin = $config['pdf_print_bin'] ?? null;
        if (! is_string($bin) || $bin === '') {
            $bin = self::detectWindowsPdfBin();
        }

        if ($bin === null || $bin === '') {
            return null;
        }

        return self::buildPdfBinCommand($bin, $file, $printer);
    }

    /**
     * Recherche SumatraPDF / PDFtoPrinter dans les emplacements usuels et le PATH.
     */
    private static function detectWindowsPdfBin(): ?string
    {
        foreach (['ProgramFiles', 'ProgramFiles(x86)', 'LOCALAPPDATA'] as $envVar) {
            $base = getenv($envVar);
            if ($base === false || $base === '') {
                continue;
            }

            foreach (['\\SumatraPDF\\SumatraPDF.exe', '\\SumatraPDF.exe'] as $suffix) {
                $candidate = $base.$suffix;
                if (is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        foreach (['SumatraPDF.exe', 'PDFtoPrinter.exe'] as $exe) {
            $found = self::whichWindows($exe);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Forge la ligne de commande selon l'utilitaire détecté (interface SumatraPDF
     * par défaut pour un binaire inconnu).
     */
    private static function buildPdfBinCommand(string $bin, string $file, string $printer): string
    {
        $name = strtolower(basename($bin));

        // PDFtoPrinter.exe "<fichier>" "<imprimante>"
        if (str_contains($name, 'pdftoprinter')) {
            return sprintf('%s %s %s', escapeshellarg($bin), escapeshellarg($file), escapeshellarg($printer));
        }

        // SumatraPDF.exe -print-to "<imprimante>" -silent -exit-when-done "<fichier>"
        return sprintf(
            '%s -print-to %s -silent -exit-when-done %s',
            escapeshellarg($bin),
            escapeshellarg($printer),
            escapeshellarg($file),
        );
    }

    private static function whichWindows(string $exe): ?string
    {
        $output = [];
        $exit = 0;
        @exec('where '.escapeshellarg($exe).' 2>NUL', $output, $exit);

        if ($exit === 0 && isset($output[0]) && is_file(trim($output[0]))) {
            return trim($output[0]);
        }

        return null;
    }

    private static function printWindowsViaShell(string $absolutePath, string $printerName): void
    {
        $ps = <<<'PS1'
param(
  [Parameter(Mandatory=$true)][string]$PrinterName,
  [Parameter(Mandatory=$true)][string]$FilePath
)

$ErrorActionPreference = 'Stop'

# Récupère l'imprimante par défaut (si possible)
$oldDefault = $null
try {
  $oldDefault = (Get-CimInstance -ClassName Win32_Printer | Where-Object { $_.Default -eq $true } | Select-Object -First 1).Name
} catch {
  $oldDefault = $null
}

# Met l'imprimante demandée en "défaut"
$setOk = $false
try {
  Import-Module PrintManagement -ErrorAction SilentlyContinue | Out-Null
  if (Get-Command Set-Printer -ErrorAction SilentlyContinue) {
    Set-Printer -Name $PrinterName -ErrorAction Stop | Out-Null
    $setOk = $true
  }
} catch {
  $setOk = $false
}

if (-not $setOk) {
  # fallback sans PrintManagement
  cmd /c "rundll32 printui.dll,PrintUIEntry /y /n `"$PrinterName`"" | Out-Null
}

# Délègue l'impression à Windows (PDF/Word/Excel selon handlers installés)
Start-Process -FilePath $FilePath -Verb Print | Out-Null
Start-Sleep -Seconds 3

# Restaure l'ancienne imprimante par défaut
if ($oldDefault -and ($oldDefault -ne $PrinterName)) {
  try {
    Import-Module PrintManagement -ErrorAction SilentlyContinue | Out-Null
    if (Get-Command Set-Printer -ErrorAction SilentlyContinue) {
      Set-Printer -Name $oldDefault | Out-Null
    } else {
      cmd /c "rundll32 printui.dll,PrintUIEntry /y /n `"$oldDefault`"" | Out-Null
    }
  } catch {
    # on ignore les erreurs de restauration
  }
}
PS1;

        $tmp = tempnam(sys_get_temp_dir(), 'laraprint_print_');
        if ($tmp === false) {
            throw new RuntimeException('Impossible de créer un fichier temporaire.');
        }
        $ps1Path = $tmp.'.ps1';
        @unlink($tmp); // tempnam crée un fichier, on le remplace par le .ps1

        file_put_contents($ps1Path, $ps);
        try {
            $cmd = sprintf(
                'powershell -NoProfile -ExecutionPolicy Bypass -File %s -PrinterName %s -FilePath %s',
                escapeshellarg($ps1Path),
                escapeshellarg($printerName),
                escapeshellarg($absolutePath),
            );
            self::runCommand($cmd);
        } finally {
            @unlink($ps1Path);
        }
    }

    private static function runCommand(string $command): void
    {
        $output = [];
        $exit = 0;
        exec($command.' 2>&1', $output, $exit);
        if ($exit !== 0) {
            throw new RuntimeException(
                'Échec de la commande d’impression : '.implode("\n", $output)
            );
        }
    }
}
