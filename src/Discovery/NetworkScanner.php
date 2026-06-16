<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Discovery;

use InvalidArgumentException;
use Neocode\Laraprint\Support\PrinterType;

/**
 * Découverte d'imprimantes sur le réseau local.
 *
 * Sonde une plage d'adresses IP sur les ports d'impression usuels (9100 « RAW/JetDirect »
 * par défaut, éventuellement 515 « LPD », 631 « IPP ») et renvoie, pour chaque hôte qui
 * répond, une configuration prête pour le SDK.
 *
 * Les connexions sont tentées en **parallèle** (sockets non bloquantes + stream_select)
 * pour scanner un /24 en quelques secondes.
 */
final class NetworkScanner
{
    /** Nombre maximum d'adresses dans une plage (garde-fou anti-scan massif). */
    private const MAX_HOSTS = 4096;

    /**
     * Scanne une plage et retourne les imprimantes détectées.
     *
     * @param  string|null  $range  Plage à scanner : CIDR (`192.168.1.0/24`),
     *                              intervalle (`192.168.1.10-50` ou `192.168.1.10-192.168.1.50`)
     *                              ou IP unique. Si null, déduit le /24 du réseau local.
     * @param  list<int>  $ports  Ports à tester (défaut : `[9100]`).
     * @param  float  $timeout  Délai max par hôte, en secondes.
     * @return list<array{connection_type: string, settings: array<string, mixed>, name: string, printer_type: string}>
     */
    public function scan(?string $range = null, array $ports = [9100], float $timeout = 0.3): array
    {
        $range ??= $this->localSubnet();
        if ($range === null) {
            return [];
        }

        $ips = self::expandRange($range);
        if ($ips === []) {
            return [];
        }

        $found = [];
        foreach ($ports as $port) {
            foreach ($this->scanPort($ips, (int) $port, $timeout) as $ip) {
                $found[] = $this->toConfig($ip, (int) $port);
            }
        }

        return $found;
    }

    /**
     * Sonde un port sur une liste d'IP en parallèle ; renvoie les IP qui acceptent la connexion.
     *
     * @param  list<string>  $ips
     * @return list<string>
     */
    private function scanPort(array $ips, int $port, float $timeout): array
    {
        $pending = [];
        foreach ($ips as $ip) {
            $fp = @stream_socket_client(
                "tcp://{$ip}:{$port}",
                $errno,
                $errstr,
                $timeout,
                STREAM_CLIENT_ASYNC_CONNECT
            );
            if ($fp !== false) {
                stream_set_blocking($fp, false);
                $pending[(int) $fp] = ['fp' => $fp, 'ip' => $ip];
            }
        }

        $found = [];
        $deadline = $this->now() + $timeout + 0.2;

        while ($pending !== [] && $this->now() < $deadline) {
            $read = null;
            $write = [];
            $except = [];
            foreach ($pending as $entry) {
                $write[] = $entry['fp'];
                $except[] = $entry['fp'];
            }

            if (@stream_select($read, $write, $except, 0, 150000) === false) {
                break;
            }

            foreach ($write as $fp) {
                $id = (int) $fp;
                // Socket écrivable + pair joignable => connexion établie.
                if (stream_socket_get_name($fp, true) !== false) {
                    $found[] = $pending[$id]['ip'];
                }
                fclose($fp);
                unset($pending[$id]);
            }

            foreach ($except as $fp) {
                $id = (int) $fp;
                if (isset($pending[$id])) {
                    fclose($fp);
                    unset($pending[$id]);
                }
            }
        }

        foreach ($pending as $entry) {
            @fclose($entry['fp']);
        }

        return array_values(array_unique($found));
    }

    /**
     * @return array{connection_type: string, settings: array<string, mixed>, name: string, printer_type: string}
     */
    private function toConfig(string $ip, int $port): array
    {
        return [
            'connection_type' => 'network',
            'settings' => ['ip' => $ip, 'port' => $port],
            'name' => "Imprimante réseau {$ip}:{$port}",
            'printer_type' => PrinterType::ThermalEscposRaw->value,
        ];
    }

    /**
     * Déduit le sous-réseau /24 à partir de l'adresse IPv4 locale (sans envoyer de données).
     */
    public function localSubnet(): ?string
    {
        $sock = @stream_socket_client('udp://8.8.8.8:53', $errno, $errstr, 1);
        if ($sock === false) {
            return null;
        }

        $name = @stream_socket_get_name($sock, false);
        fclose($sock);

        if (! is_string($name) || ! str_contains($name, ':')) {
            return null;
        }

        $ip = substr($name, 0, (int) strrpos($name, ':'));
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }

        $parts = explode('.', $ip);

        return "{$parts[0]}.{$parts[1]}.{$parts[2]}.0/24";
    }

    /**
     * Étend une plage (CIDR, intervalle, ou IP unique) en liste d'adresses IPv4.
     *
     * @return list<string>
     */
    public static function expandRange(string $range): array
    {
        $range = trim($range);

        if (str_contains($range, '/')) {
            return self::expandCidr($range);
        }
        if (str_contains($range, '-')) {
            return self::expandDashRange($range);
        }

        return filter_var($range, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? [$range] : [];
    }

    /**
     * @return list<string>
     */
    private static function expandCidr(string $cidr): array
    {
        [$base, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;

        if (! filter_var($base, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $bits < 0 || $bits > 32) {
            return [];
        }

        $baseLong = ip2long($base);
        $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
        $network = $baseLong & $mask;
        $broadcast = $network | (~$mask & 0xFFFFFFFF);

        // Pour /24 et plus larges, on exclut l'adresse réseau et de diffusion.
        $start = $bits >= 31 ? $network : $network + 1;
        $end = $bits >= 31 ? $broadcast : $broadcast - 1;

        self::guardSize($end - $start + 1);

        $ips = [];
        for ($i = $start; $i <= $end; $i++) {
            $ips[] = long2ip($i);
        }

        return $ips;
    }

    /**
     * @return list<string>
     */
    private static function expandDashRange(string $range): array
    {
        [$start, $end] = array_map('trim', explode('-', $range, 2));

        if (! filter_var($start, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return [];
        }

        // « 192.168.1.10-50 » : le côté droit est le dernier octet.
        if (! str_contains($end, '.')) {
            $parts = explode('.', $start);
            $parts[3] = $end;
            $end = implode('.', $parts);
        }

        if (! filter_var($end, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return [];
        }

        $startLong = ip2long($start);
        $endLong = ip2long($end);
        if ($endLong < $startLong) {
            return [];
        }

        self::guardSize($endLong - $startLong + 1);

        $ips = [];
        for ($i = $startLong; $i <= $endLong; $i++) {
            $ips[] = long2ip($i);
        }

        return $ips;
    }

    private static function guardSize(int $count): void
    {
        if ($count > self::MAX_HOSTS) {
            throw new InvalidArgumentException(sprintf(
                'Plage trop large (%d adresses, max %d). Restreignez la plage à scanner.',
                $count,
                self::MAX_HOSTS,
            ));
        }
    }

    private function now(): float
    {
        return (float) hrtime(true) / 1_000_000_000;
    }
}
