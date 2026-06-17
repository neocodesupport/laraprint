<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Discovery;

use Throwable;

/**
 * Interrogation SNMP d'une imprimante réseau (modèle, statut, compteur de pages,
 * niveau de consommable) via le Printer MIB standard.
 *
 * Best-effort : nécessite l'extension `snmp`. Sans elle, renvoie `[]`.
 */
final class SnmpQuery
{
    /** @var array<string, string> OID standard (Printer MIB / Host Resources MIB). */
    public const OIDS = [
        'model' => '1.3.6.1.2.1.1.1.0',                 // sysDescr
        'name' => '1.3.6.1.2.1.1.5.0',                  // sysName
        'status' => '1.3.6.1.2.1.25.3.5.1.1.1',         // hrPrinterStatus
        'page_count' => '1.3.6.1.2.1.43.10.2.1.4.1.1',  // prtMarkerLifeCount
        'supply_level' => '1.3.6.1.2.1.43.11.1.1.9.1.1', // prtMarkerSuppliesLevel
        'supply_max' => '1.3.6.1.2.1.43.11.1.1.8.1.1',   // prtMarkerSuppliesMaxCapacity
    ];

    /**
     * @return array<string, string>
     */
    public static function oids(): array
    {
        return self::OIDS;
    }

    /**
     * Interroge l'imprimante et renvoie les valeurs disponibles (clés de {@see OIDS}
     * + `supply_percent`). Les valeurs inconnues valent `null`.
     *
     * @return array<string, int|string|null>
     */
    public function query(string $host, string $community = 'public', float $timeout = 1.0): array
    {
        if (! function_exists('snmpget')) {
            return [];
        }

        if (function_exists('snmp_set_valueretrieval') && defined('SNMP_VALUE_PLAIN')) {
            snmp_set_valueretrieval(SNMP_VALUE_PLAIN);
        }

        $timeoutUs = (int) ($timeout * 1_000_000);
        $result = [];
        foreach (self::OIDS as $key => $oid) {
            $result[$key] = $this->get($host, $community, $oid, $timeoutUs);
        }

        $result['supply_percent'] = self::percent($result['supply_level'] ?? null, $result['supply_max'] ?? null);

        return $result;
    }

    /**
     * Identifie rapidement une imprimante : renvoie son nom (sysName) ou, à défaut,
     * sa description (sysDescr). Best-effort : `null` si SNMP indisponible ou muet.
     *
     * Léger : une à deux requêtes seulement (contrairement à {@see query()}), pour
     * être appelé pendant un scan réseau sans le ralentir.
     */
    public function name(string $host, string $community = 'public', float $timeout = 0.5): ?string
    {
        if (! function_exists('snmpget')) {
            return null;
        }

        if (function_exists('snmp_set_valueretrieval') && defined('SNMP_VALUE_PLAIN')) {
            snmp_set_valueretrieval(SNMP_VALUE_PLAIN);
        }

        $timeoutUs = (int) ($timeout * 1_000_000);
        $name = $this->get($host, $community, self::OIDS['name'], $timeoutUs);
        if ($name !== null && $name !== '') {
            return $name;
        }

        $descr = $this->get($host, $community, self::OIDS['model'], $timeoutUs);

        return $descr !== null && $descr !== '' ? $descr : null;
    }

    private function get(string $host, string $community, string $oid, int $timeoutUs): ?string
    {
        try {
            $value = @snmpget($host, $community, $oid, $timeoutUs, 1);
        } catch (Throwable) {
            return null;
        }

        return $value === false ? null : trim((string) $value, ' "');
    }

    /**
     * Calcule un pourcentage de consommable à partir du niveau et de la capacité max.
     */
    public static function percent(int|string|null $level, int|string|null $max): ?int
    {
        if (! is_numeric($level) || ! is_numeric($max) || (int) $max <= 0) {
            return null;
        }

        return (int) round(((int) $level / (int) $max) * 100);
    }
}
