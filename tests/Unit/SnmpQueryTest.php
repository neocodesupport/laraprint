<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Tests\Unit;

use Neocode\Laraprint\Discovery\SnmpQuery;
use PHPUnit\Framework\TestCase;

final class SnmpQueryTest extends TestCase
{
    public function test_oids_cover_the_printer_mib_basics(): void
    {
        $oids = SnmpQuery::oids();

        $this->assertArrayHasKey('model', $oids);
        $this->assertArrayHasKey('page_count', $oids);
        $this->assertArrayHasKey('supply_level', $oids);
        $this->assertSame('1.3.6.1.2.1.1.1.0', $oids['model']);
    }

    public function test_percent_computation(): void
    {
        $this->assertSame(50, SnmpQuery::percent(100, 200));
        $this->assertSame(100, SnmpQuery::percent('200', '200'));
        $this->assertSame(0, SnmpQuery::percent(0, 200));
    }

    public function test_percent_returns_null_on_invalid_input(): void
    {
        $this->assertNull(SnmpQuery::percent(null, 200));
        $this->assertNull(SnmpQuery::percent(100, 0));
        $this->assertNull(SnmpQuery::percent('abc', '200'));
    }

    public function test_query_is_empty_without_snmp_extension(): void
    {
        if (function_exists('snmpget')) {
            $this->markTestSkipped('Extension snmp présente : test du repli non applicable.');
        }

        $this->assertSame([], (new SnmpQuery)->query('192.0.2.1'));
    }
}
