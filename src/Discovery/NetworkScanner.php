<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Discovery;

use InvalidArgumentException;
use Neocode\Laraprint\Support\PrinterType;

/**
 * Découverte d'imprimantes sur le réseau local.
 *
 * Sonde une plage d'adresses IP sur les ports d'impression usuels (9100 « RAW/JetDirect »,
 * 631 « IPP », 515 « LPD ») et renvoie, pour chaque hôte qui répond, une configuration
 * prête pour le SDK. Lorsque SNMP est disponible, le nom réel de l'imprimante est récupéré.
 *
 * Les connexions sont tentées en **parallèle, par lots** (sockets non bloquantes +
 * stream_select) pour scanner un /24 en quelques secondes sans saturer la pile réseau.
 */
final class NetworkScanner
{
    /** Nombre maximum d'adresses dans une plage (garde-fou anti-scan massif). */
    private const MAX_HOSTS = 4096;

    /** Nombre de connexions ouvertes simultanément (évite la rafale SYN/ARP qui fait tout expirer). */
    private const BATCH_SIZE = 128;

    /**
     * Ports sondés par défaut, **par ordre de priorité** (un hôte répondant sur plusieurs
     * ports n'est gardé qu'une fois, sur le port le plus prioritaire).
     *
     * @var list<int>
     */
    public const DEFAULT_PORTS = [9100, 631, 515];

    /**
     * Scanne une plage et retourne les imprimantes détectées.
     *
     * @param  string|null  $range  Plage à scanner : CIDR (`192.168.1.0/24`),
     *                              intervalle (`192.168.1.10-50` ou `192.168.1.10-192.168.1.50`)
     *                              ou IP unique. Si null, déduit le(s) sous-réseau(x) local/locaux.
     * @param  list<int>  $ports  Ports à tester, par ordre de priorité (défaut : {@see DEFAULT_PORTS}).
     * @param  float  $timeout  Délai max d'établissement de connexion par lot, en secondes.
     * @param  bool  $identify  Enrichit le nom via SNMP si disponible (best-effort).
     * @return list<array{connection_type: string, settings: array<string, mixed>, name: string, printer_type: ?string}>
     */
    public function scan(
        ?string $range = null,
        array $ports = self::DEFAULT_PORTS,
        float $timeout = 1.0,
        bool $identify = true,
    ): array {
        $ips = $range !== null ? self::expandRange($range) : $this->localIps();
        if ($ips === []) {
            return [];
        }

        // Dédoublonnage par IP : on garde le premier port qui répond (ordre de priorité).
        $byIp = [];
        foreach ($ports as $port) {
            $port = (int) $port;
            foreach ($this->scanPort($ips, $port, $timeout) as $ip) {
                if (! isset($byIp[$ip])) {
                    $byIp[$ip] = $this->toConfig($ip, $port, $identify);
                }
            }
        }

        return array_values($byIp);
    }

    /**
     * Expanse tous les sous-réseaux locaux détectés en une liste d'IP unique.
     *
     * @return list<string>
     */
    private function localIps(): array
    {
        $ips = [];
        foreach ($this->localSubnets() as $subnet) {
            foreach (self::expandRange($subnet) as $ip) {
                $ips[$ip] = true;
            }
        }

        return array_keys($ips);
    }

    /**
     * Sonde un port sur une liste d'IP, par lots ; renvoie les IP qui acceptent la connexion.
     *
     * @param  list<string>  $ips
     * @return list<string>
     */
    private function scanPort(array $ips, int $port, float $timeout): array
    {
        $found = [];
        foreach (array_chunk($ips, self::BATCH_SIZE) as $batch) {
            foreach ($this->probeBatch($batch, $port, $timeout) as $ip) {
                $found[] = $ip;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Sonde un lot d'IP en parallèle. Le délai est appliqué **par lot** (et non globalement),
     * pour laisser à chaque hôte le temps de répondre.
     *
     * @param  list<string>  $ips
     * @return list<string>
     */
    private function probeBatch(array $ips, int $port, float $timeout): array
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
        $deadline = $this->now() + $timeout;

        while ($pending !== [] && $this->now() < $deadline) {
            $read = null;
            $write = array_map(static fn (array $e) => $e['fp'], $pending);
            $except = $write;

            $remaining = $deadline - $this->now();
            $usec = (int) max(0, min(200000, $remaining * 1_000_000));
            if (@stream_select($read, $write, $except, 0, $usec) === false) {
                break;
            }

            // Échecs (connexion refusée / hôte injoignable) : signalés via le set « except » sous Windows.
            foreach ($except as $fp) {
                $id = (int) $fp;
                if (isset($pending[$id])) {
                    @fclose($fp);
                    unset($pending[$id]);
                }
            }

            // Sockets écrivables : connexion établie si le pair est joignable (getpeername).
            foreach ($write as $fp) {
                $id = (int) $fp;
                if (! isset($pending[$id])) {
                    continue; // déjà traité comme échec ci-dessus
                }
                if (@stream_socket_get_name($fp, true) !== false) {
                    $found[] = $pending[$id]['ip'];
                }
                @fclose($fp);
                unset($pending[$id]);
            }
        }

        foreach ($pending as $entry) {
            @fclose($entry['fp']);
        }

        return $found;
    }

    /**
     * @return array{connection_type: string, settings: array<string, mixed>, name: string, printer_type: ?string}
     */
    private function toConfig(string $ip, int $port, bool $identify): array
    {
        $name = "Imprimante réseau {$ip}:{$port}";
        if ($identify) {
            $label = (new SnmpQuery)->name($ip);
            if ($label !== null && $label !== '') {
                $name = "{$label} ({$ip})";
            }
        }

        return [
            'connection_type' => 'network',
            'settings' => ['ip' => $ip, 'port' => $port],
            'name' => $name,
            'printer_type' => self::printerTypeForPort($port),
        ];
    }

    /**
     * Mappe un port d'impression vers le type d'imprimante logique.
     * Seul le RAW/JetDirect (9100) est garanti ESC/POS ; IPP (631) / LPD (515) sont indéterminés.
     */
    public static function printerTypeForPort(int $port): ?string
    {
        return $port === 9100 ? PrinterType::ThermalEscposRaw->value : null;
    }

    /**
     * Déduit le premier sous-réseau local (rétro-compatibilité).
     */
    public function localSubnet(): ?string
    {
        return $this->localSubnets()[0] ?? null;
    }

    /**
     * Déduit le(s) sous-réseau(x) IPv4 local/locaux **sans dépendre d'Internet**,
     * en lisant les adaptateurs réseau (IP + masque réels). Repli sur l'astuce UDP
     * uniquement si l'énumération échoue.
     *
     * @return list<string> CIDR réseau, ex. `192.168.1.0/24`
     */
    public function localSubnets(): array
    {
        $hostCidrs = PHP_OS_FAMILY === 'Windows'
            ? self::parseWindowsAddresses((string) self::powershell(self::WINDOWS_ADDR_SCRIPT))
            : self::parseIpAddr((string) self::runCommand('ip -4 -o addr show 2>/dev/null'));

        $subnets = [];
        foreach ($hostCidrs as $hostCidr) {
            $network = self::cidrToNetwork($hostCidr);
            if ($network !== null) {
                $subnets[$network] = true;
            }
        }

        if ($subnets !== []) {
            return array_keys($subnets);
        }

        // Dernier recours : déduire un /24 via la route par défaut (nécessite une route sortante).
        $fallback = $this->fallbackSubnet();

        return $fallback !== null ? [$fallback] : [];
    }

    /** Script PowerShell listant les adresses IPv4 (hors loopback/APIPA) au format `IP/Prefix`. */
    private const WINDOWS_ADDR_SCRIPT = 'Get-NetIPAddress -AddressFamily IPv4 | '
        ."Where-Object { \$_.IPAddress -notlike '127.*' -and \$_.IPAddress -notlike '169.254.*' } | "
        ."ForEach-Object { \$_.IPAddress + '/' + \$_.PrefixLength }";

    /**
     * Parse la sortie PowerShell `IP/Prefix` (une par ligne) en CIDR d'hôtes valides.
     *
     * @return list<string>
     */
    public static function parseWindowsAddresses(string $output): array
    {
        $result = [];
        foreach (explode("\n", $output) as $line) {
            $line = trim($line);
            if ($line === '' || ! str_contains($line, '/')) {
                continue;
            }
            [$ip, $prefix] = explode('/', $line, 2);
            if (self::isLocalIpv4($ip) && ctype_digit($prefix)) {
                $result[] = "{$ip}/{$prefix}";
            }
        }

        return $result;
    }

    /**
     * Parse la sortie de `ip -4 -o addr show` et extrait les `inet IP/Prefix` non loopback/APIPA.
     *
     * Lignes du type : `2: eth0    inet 192.168.1.10/24 brd 192.168.1.255 scope global eth0`
     *
     * @return list<string>
     */
    public static function parseIpAddr(string $output): array
    {
        $result = [];
        if (preg_match_all('/\binet\s+(\d{1,3}(?:\.\d{1,3}){3}\/\d{1,2})\b/', $output, $matches)) {
            foreach ($matches[1] as $cidr) {
                $ip = explode('/', $cidr, 2)[0];
                if (self::isLocalIpv4($ip)) {
                    $result[] = $cidr;
                }
            }
        }

        return $result;
    }

    /**
     * Convertit un CIDR d'hôte (`192.168.1.10/24`) en CIDR réseau (`192.168.1.0/24`).
     */
    public static function cidrToNetwork(string $hostCidr): ?string
    {
        if (! str_contains($hostCidr, '/')) {
            return null;
        }
        [$ip, $bits] = explode('/', $hostCidr, 2);
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || ! ctype_digit($bits)) {
            return null;
        }
        $bits = (int) $bits;
        if ($bits < 1 || $bits > 32) {
            return null;
        }

        $mask = (~0 << (32 - $bits)) & 0xFFFFFFFF;
        $network = ip2long($ip) & $mask;

        return long2ip($network)."/{$bits}";
    }

    private static function isLocalIpv4(string $ip): bool
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && ! str_starts_with($ip, '127.')
            && ! str_starts_with($ip, '169.254.');
    }

    /**
     * Repli : déduit le /24 à partir de l'adresse locale routée (UDP, sans envoyer de données).
     */
    private function fallbackSubnet(): ?string
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

        return self::isLocalIpv4($ip) ? self::cidrToNetwork("{$ip}/24") : null;
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

    /**
     * Exécute un script PowerShell (Windows) via un fichier temporaire et renvoie sa sortie.
     */
    private static function powershell(string $script): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'laraprint_net_');
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
