<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Discovery;

use Neocode\Laraprint\Support\PrinterType;

/**
 * Découverte des imprimantes configurées sur le poste (système d'exploitation).
 *
 * - Windows : liste des imprimantes via PowerShell (Get-Printer).
 * - Linux / macOS : liste des imprimantes CUPS (lpstat).
 *
 * Chaque entrée retournée peut être utilisée avec ConnectorFactory ou DirectPrinter.
 */
final class SystemPrinters
{
    private static function inferPrinterTypeFromName(string $printerName, PrinterType $defaultType): PrinterType
    {
        $n = strtolower($printerName);

        // Heuristique simple : la plupart des imprimantes thermiques ESC/POS ont un nom
        // qui contient "TM", "receipt", "pos", "ticket", "escpos", etc.
        if (
            str_contains($n, 'receipt')
            || str_contains($n, 'ticket')
            || str_contains($n, 'escpos')
            || str_contains($n, 'esc-pos')
            || str_contains($n, 'pos')
            || str_contains($n, 'kitchen')
            || preg_match('/(^|[^a-z0-9])tm[^a-z0-9]/i', $printerName) === 1
        ) {
            return PrinterType::ThermalEscposRaw;
        }

        return $defaultType;
    }

    /**
     * Retourne toutes les imprimantes configurées sur le poste.
     *
     * Chaque élément est un tableau prêt pour une config de connexion, par ex. :
     * [
     *   'connection_type' => 'windows',
     *   'settings' => ['printer_name' => 'EPSON TM-T20'],
     *   'name' => 'EPSON TM-T20',
     * ]
     *
     * @return list<array{connection_type: string, settings: array<string, mixed>, name: string}>
     */
    public static function listPrinters(): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return self::listWindowsPrinters();
        }

        return self::listCupsPrinters();
    }

    /**
     * Liste des imprimantes Windows (PowerShell Get-Printer, ou WMIC en secours).
     *
     * @return list<array{connection_type: string, settings: array<string, mixed>, name: string}>
     */
    private static function listWindowsPrinters(): array
    {
        $names = self::getWindowsPrinterNames();
        $result = [];
        foreach ($names as $name) {
            $type = self::inferPrinterTypeFromName($name, PrinterType::WindowsSpoolDocument);
            $result[] = [
                'connection_type' => 'windows',
                'settings' => ['printer_name' => $name],
                'name' => $name,
                'printer_type' => $type->value,
            ];
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private static function getWindowsPrinterNames(): array
    {
        $output = self::runPowerShell('Get-Printer | Select-Object -ExpandProperty Name');
        if ($output !== null && trim($output) !== '') {
            return self::parseLinesToNames($output);
        }
        $output = self::runCommand('wmic printer get name 2>nul');
        if ($output !== null && trim($output) !== '') {
            return self::parseWmicPrinters($output);
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private static function parseLinesToNames(string $output): array
    {
        $lines = array_filter(array_map('trim', explode("\n", $output)));
        $result = [];
        foreach ($lines as $name) {
            $name = trim($name, " \t\r\n\x00");
            if ($name !== '') {
                $result[] = $name;
            }
        }

        return $result;
    }

    /**
     * Parse "Name\nprinter1\nprinter2\n" (WMIC).
     *
     * @return list<string>
     */
    private static function parseWmicPrinters(string $output): array
    {
        $lines = explode("\n", trim($output));
        $result = [];
        $skipHeader = true;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if ($skipHeader && strcasecmp($line, 'Name') === 0) {
                $skipHeader = false;

                continue;
            }
            $skipHeader = false;
            $name = trim($line);
            if ($name !== '') {
                $result[] = $name;
            }
        }

        return $result;
    }

    /**
     * Liste des imprimantes CUPS (lpstat -a ou lpstat -p).
     *
     * @return list<array{connection_type: string, settings: array<string, mixed>, name: string}>
     */
    private static function listCupsPrinters(): array
    {
        $output = self::runCommand('lpstat -a 2>/dev/null');
        if ($output === null || trim($output) === '') {
            $output = self::runCommand('lpstat -p 2>/dev/null');
            if ($output === null || trim($output) === '') {
                return [];
            }

            return self::parseLpstatP($output);
        }

        return self::parseLpstatA($output);
    }

    /**
     * Parse "printer_name accepting requests since ..."
     */
    private static function parseLpstatA(string $output): array
    {
        $result = [];
        foreach (explode("\n", trim($output)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $name = (explode(' ', $line, 2))[0] ?? '';
            if ($name !== '') {
                $type = self::inferPrinterTypeFromName($name, PrinterType::CupsSpoolDocument);
                $result[] = [
                    'connection_type' => 'cups',
                    'settings' => ['cups_name' => $name],
                    'name' => $name,
                    'printer_type' => $type->value,
                ];
            }
        }

        return $result;
    }

    /**
     * Parse "printer printer_name is idle. enabled since ..."
     */
    private static function parseLpstatP(string $output): array
    {
        $result = [];
        foreach (explode("\n", trim($output)) as $line) {
            $line = trim($line);
            if ($line === '' || stripos($line, 'printer ') !== 0) {
                continue;
            }
            $parts = preg_split('/\s+/', $line, 4);
            if (isset($parts[1]) && $parts[1] !== '') {
                $name = $parts[1];
                $type = self::inferPrinterTypeFromName($name, PrinterType::CupsSpoolDocument);
                $result[] = [
                    'connection_type' => 'cups',
                    'settings' => ['cups_name' => $name],
                    'name' => $name,
                    'printer_type' => $type->value,
                ];
            }
        }

        return $result;
    }

    private static function runPowerShell(string $script): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'laraprint_');
        if ($tmp === false) {
            return null;
        }
        $ps1 = $tmp.'.ps1';
        if (! rename($tmp, $ps1)) {
            @unlink($tmp);

            return null;
        }
        file_put_contents($ps1, $script);
        $output = self::runCommand('powershell -NoProfile -ExecutionPolicy Bypass -File '.escapeshellarg($ps1));
        @unlink($ps1);

        return $output;
    }

    private static function runCommand(string $command): ?string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open(
            $command,
            $descriptors,
            $pipes,
            null,
            null,
            ['bypass_shell' => false]
        );
        if (! is_resource($process)) {
            return null;
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($process);

        return $stdout !== false ? $stdout : null;
    }
}
