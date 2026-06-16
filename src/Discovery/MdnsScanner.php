<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Discovery;

use Neocode\Laraprint\Support\PrinterType;

/**
 * Découverte d'imprimantes via **mDNS / Bonjour** (AirPrint).
 *
 * Émet une requête multicast pour les services d'impression usuels
 * (`_pdl-datastream._tcp` port 9100, `_printer._tcp` LPD, `_ipp._tcp`) et corrèle
 * les enregistrements PTR/SRV/A reçus pour produire des configurations prêtes pour le SDK.
 *
 * Best-effort : nécessite l'extension `sockets`. Sans elle (ou sans réponse), renvoie `[]`.
 * Les fonctions de construction/lecture de paquets DNS sont pures et testables.
 */
final class MdnsScanner
{
    private const MDNS_ADDR = '224.0.0.251';

    private const MDNS_PORT = 5353;

    /** @var list<string> Services d'impression interrogés par défaut. */
    public const SERVICES = [
        '_pdl-datastream._tcp.local',
        '_printer._tcp.local',
        '_ipp._tcp.local',
    ];

    /**
     * Découvre les imprimantes annoncées sur le réseau local via mDNS.
     *
     * @param  list<string>  $services
     * @return list<array{connection_type: string, settings: array<string, mixed>, name: string, printer_type: ?string}>
     */
    public function discover(float $timeout = 2.0, array $services = self::SERVICES): array
    {
        if (! function_exists('socket_create')) {
            return [];
        }

        $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sock === false) {
            return [];
        }

        @socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1);
        @socket_bind($sock, '0.0.0.0', 0);

        $query = self::buildQuery($services);
        @socket_sendto($sock, $query, strlen($query), 0, self::MDNS_ADDR, self::MDNS_PORT);

        $records = [];
        $deadline = $this->now() + $timeout;
        while ($this->now() < $deadline) {
            $read = [$sock];
            $write = null;
            $except = null;
            $ready = @socket_select($read, $write, $except, 0, 250000);
            if ($ready === false) {
                break;
            }
            if ($ready > 0) {
                $buffer = '';
                $from = '';
                $port = 0;
                if (@socket_recvfrom($sock, $buffer, 4096, 0, $from, $port) !== false && $buffer !== '') {
                    foreach (self::parseMessage($buffer) as $record) {
                        $records[] = $record;
                    }
                }
            }
        }

        socket_close($sock);

        return self::extractPrinters($records);
    }

    /**
     * Construit une requête mDNS (PTR) pour les services donnés, avec bit QU
     * (réponse unicast) afin de recevoir sans rejoindre le groupe multicast.
     *
     * @param  list<string>  $services
     */
    public static function buildQuery(array $services): string
    {
        $header = pack('n6', 0, 0, count($services), 0, 0, 0);
        $body = '';
        foreach ($services as $service) {
            // QTYPE = PTR (12), QCLASS = IN (1) | bit QU (0x8000) => 0x8001
            $body .= self::encodeName($service).pack('n2', 12, 0x8001);
        }

        return $header.$body;
    }

    private static function encodeName(string $name): string
    {
        $out = '';
        foreach (explode('.', trim($name, '.')) as $label) {
            if ($label === '') {
                continue;
            }
            $out .= chr(strlen($label)).$label;
        }

        return $out."\0";
    }

    /**
     * Parse un message DNS/mDNS en liste d'enregistrements de ressources.
     *
     * @return list<array{name: string, type: int, data: mixed}>
     */
    public static function parseMessage(string $message): array
    {
        if (strlen($message) < 12) {
            return [];
        }

        $header = unpack('nid/nflags/nqd/nan/nns/nar', substr($message, 0, 12));
        $offset = 12;

        $questions = (int) $header['qd'];
        for ($i = 0; $i < $questions; $i++) {
            self::readName($message, $offset);
            $offset += 4; // QTYPE + QCLASS
        }

        $records = [];
        $total = (int) $header['an'] + (int) $header['ns'] + (int) $header['ar'];
        for ($i = 0; $i < $total; $i++) {
            $name = self::readName($message, $offset);
            if ($offset + 10 > strlen($message)) {
                break;
            }
            $rr = unpack('ntype/nclass/Nttl/nrdlength', substr($message, $offset, 10));
            $offset += 10;
            $rdataOffset = $offset;
            $data = self::decodeRdata($message, $rdataOffset, (int) $rr['type'], (int) $rr['rdlength']);
            $offset += $rr['rdlength'];

            $records[] = ['name' => $name, 'type' => (int) $rr['type'], 'data' => $data];
        }

        return $records;
    }

    /**
     * Corrèle les enregistrements (SRV + A) en configurations d'imprimantes.
     *
     * @param  list<array{name: string, type: int, data: mixed}>  $records
     * @return list<array{connection_type: string, settings: array<string, mixed>, name: string, printer_type: ?string}>
     */
    public static function extractPrinters(array $records): array
    {
        $srv = [];
        $a = [];
        foreach ($records as $record) {
            if ($record['type'] === 33 && is_array($record['data'])) {
                $srv[] = ['instance' => (string) $record['name'], 'data' => $record['data']];
            } elseif ($record['type'] === 1 && is_string($record['data'])) {
                $a[strtolower($record['name'])] = $record['data'];
            }
        }

        $printers = [];
        $seen = [];
        foreach ($srv as $entry) {
            $instance = $entry['instance'];
            $data = $entry['data'];
            $target = strtolower((string) ($data['target'] ?? ''));
            $port = (int) ($data['port'] ?? 0);
            $ip = $a[$target] ?? null;
            if ($ip === null || $port === 0) {
                continue;
            }

            $key = $ip.':'.$port;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $isRaw = $port === 9100 || str_contains(strtolower($instance), '_pdl-datastream');
            $printers[] = [
                'connection_type' => 'network',
                'settings' => ['ip' => $ip, 'port' => $port],
                'name' => self::instanceLabel($instance).' ('.$ip.')',
                'printer_type' => $isRaw ? PrinterType::ThermalEscposRaw->value : null,
            ];
        }

        return $printers;
    }

    private static function instanceLabel(string $instance): string
    {
        $label = preg_replace('/\._(pdl-datastream|printer|ipp|ipps)\._tcp\.local\.?$/i', '', $instance);

        return $label !== null && $label !== '' ? $label : 'AirPrint';
    }

    private static function decodeRdata(string $message, int $offset, int $type, int $length): mixed
    {
        return match ($type) {
            1 => $length === 4 ? inet_ntop(substr($message, $offset, 4)) : null,   // A
            12 => self::readNameAt($message, $offset),                              // PTR
            16 => self::decodeTxt(substr($message, $offset, $length)),              // TXT
            33 => self::decodeSrv($message, $offset),                               // SRV
            default => null,
        };
    }

    private static function readNameAt(string $message, int $offset): string
    {
        $local = $offset;

        return self::readName($message, $local);
    }

    /**
     * @return array{priority: int, weight: int, port: int, target: string}
     */
    private static function decodeSrv(string $message, int $offset): array
    {
        $head = unpack('npriority/nweight/nport', substr($message, $offset, 6));
        $local = $offset + 6;
        $target = self::readName($message, $local);

        return [
            'priority' => (int) $head['priority'],
            'weight' => (int) $head['weight'],
            'port' => (int) $head['port'],
            'target' => $target,
        ];
    }

    /**
     * @return list<string>
     */
    private static function decodeTxt(string $rdata): array
    {
        $out = [];
        $i = 0;
        $n = strlen($rdata);
        while ($i < $n) {
            $len = ord($rdata[$i]);
            $i++;
            if ($len > 0) {
                $out[] = substr($rdata, $i, $len);
                $i += $len;
            }
        }

        return $out;
    }

    /**
     * Lit un nom DNS à partir de $offset (gère la compression par pointeurs) et
     * avance $offset au-delà du nom (au premier saut si compression).
     */
    private static function readName(string $message, int &$offset): string
    {
        $labels = [];
        $jumped = false;
        $safety = 0;
        $pos = $offset;
        $length = strlen($message);

        while ($pos < $length && $safety++ < 128) {
            $len = ord($message[$pos]);

            if ($len === 0) {
                $pos++;
                if (! $jumped) {
                    $offset = $pos;
                }
                break;
            }

            if (($len & 0xC0) === 0xC0) {
                if ($pos + 1 >= $length) {
                    break;
                }
                $pointer = (($len & 0x3F) << 8) | ord($message[$pos + 1]);
                if (! $jumped) {
                    $offset = $pos + 2;
                }
                $jumped = true;
                $pos = $pointer;

                continue;
            }

            $labels[] = substr($message, $pos + 1, $len);
            $pos += 1 + $len;
        }

        return implode('.', $labels);
    }

    private function now(): float
    {
        return (float) hrtime(true) / 1_000_000_000;
    }
}
