<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Discovery;

use Neocode\Laraprint\Support\PrinterType;

/**
 * Découverte des imprimantes **connectées localement** (USB / port parallèle).
 *
 * - **Windows** : imprimantes dont le port est `USB*` / `DOT4*` (PowerShell `Get-Printer`)
 *   → connecteur `windows` (impression via le pilote/queue).
 * - **Linux/macOS** : périphériques `/dev/usb/lp*` et `/dev/lp*` (impression brute en `file`),
 *   complétés par les périphériques USB déclarés par CUPS (`lpinfo -v`).
 */
final class LocalPrinters
{
    /**
     * @return list<array{connection_type: string, settings: array<string, mixed>, name: string, printer_type: string}>
     */
    public static function listUsb(): array
    {
        return PHP_OS_FAMILY === 'Windows'
            ? self::windowsUsb()
            : self::unixUsb();
    }

    /**
     * @return list<array{connection_type: string, settings: array<string, mixed>, name: string, printer_type: string}>
     */
    private static function windowsUsb(): array
    {
        $script = "Get-Printer | Where-Object { \$_.PortName -like 'USB*' -or \$_.PortName -like 'DOT4*' } | "
            ."ForEach-Object { \$_.Name + '|' + \$_.PortName }";

        $output = self::runPowerShell($script);

        return $output !== null ? self::parseWindowsUsb($output) : [];
    }

    /**
     * Parse les lignes « Nom|Port » renvoyées par PowerShell.
     *
     * @return list<array{connection_type: string, settings: array<string, mixed>, name: string, printer_type: string}>
     */
    public static function parseWindowsUsb(string $output): array
    {
        $result = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            [$name, $port] = array_pad(explode('|', $line, 2), 2, '');
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $result[] = [
                'connection_type' => 'windows',
                'settings' => ['printer_name' => $name, 'port' => trim($port)],
                'name' => $name,
                'printer_type' => PrinterType::ThermalEscposRaw->value,
            ];
        }

        return $result;
    }

    /**
     * @return list<array{connection_type: string, settings: array<string, mixed>, name: string, printer_type: string}>
     */
    private static function unixUsb(): array
    {
        $result = [];

        // Périphériques bruts directement adressables via le connecteur "file".
        foreach (self::globDevices() as $device) {
            $result[] = [
                'connection_type' => 'file',
                'settings' => ['path' => $device],
                'name' => 'USB '.basename($device),
                'printer_type' => PrinterType::ThermalEscposRaw->value,
            ];
        }

        // Périphériques USB connus de CUPS (informatif : nécessite une file CUPS pour imprimer).
        $lpinfo = self::runCommand('lpinfo -v 2>/dev/null');
        if ($lpinfo !== null) {
            foreach (self::parseLpinfo($lpinfo) as $config) {
                $result[] = $config;
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private static function globDevices(): array
    {
        $devices = [];
        foreach (['/dev/usb/lp*', '/dev/lp*', '/dev/usblp*'] as $pattern) {
            $matches = glob($pattern);
            if ($matches !== false) {
                foreach ($matches as $device) {
                    if (! in_array($device, $devices, true)) {
                        $devices[] = $device;
                    }
                }
            }
        }

        return $devices;
    }

    /**
     * Parse la sortie de `lpinfo -v` et extrait les périphériques USB.
     *
     * Lignes du type : `direct usb://EPSON/TM-T20II?serial=...`
     *
     * @return list<array{connection_type: string, settings: array<string, mixed>, name: string, printer_type: string}>
     */
    public static function parseLpinfo(string $output): array
    {
        $result = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ($line === '' || ! str_contains($line, 'usb://')) {
                continue;
            }

            $parts = preg_split('/\s+/', $line, 2);
            $uri = $parts[1] ?? '';
            if ($uri === '') {
                continue;
            }

            // Nom lisible : segment après usb:// avant le « ? ».
            $label = substr($uri, strpos($uri, 'usb://') + 6);
            $label = explode('?', $label, 2)[0];
            $label = trim(str_replace('/', ' ', $label)) ?: $uri;

            $result[] = [
                'connection_type' => 'cups',
                'settings' => ['device_uri' => $uri],
                'name' => 'USB '.$label,
                'printer_type' => PrinterType::ThermalEscposRaw->value,
            ];
        }

        return $result;
    }

    private static function runPowerShell(string $script): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'laraprint_usb_');
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
        $process = @proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => false]);
        if (! is_resource($process)) {
            return null;
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $stdout !== false ? $stdout : null;
    }
}
