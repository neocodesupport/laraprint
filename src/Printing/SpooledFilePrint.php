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

        self::printWindowsViaShell($absolutePath, $printerName);
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
