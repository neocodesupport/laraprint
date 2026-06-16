<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Tests\Unit;

use Neocode\Laraprint\Discovery\MdnsScanner;
use PHPUnit\Framework\TestCase;

final class MdnsScannerTest extends TestCase
{
    private function encodeName(string $name): string
    {
        $out = '';
        foreach (explode('.', trim($name, '.')) as $label) {
            $out .= chr(strlen($label)).$label;
        }

        return $out."\0";
    }

    private function rr(string $name, int $type, string $rdata): string
    {
        return $this->encodeName($name).pack('nnNn', $type, 1, 120, strlen($rdata)).$rdata;
    }

    private function sampleResponse(): string
    {
        $ptrName = '_pdl-datastream._tcp.local';
        $instance = 'EPSON TM-T20II._pdl-datastream._tcp.local';
        $host = 'epson.local';

        $ptr = $this->rr($ptrName, 12, $this->encodeName($instance));
        $srv = $this->rr($instance, 33, pack('nnn', 0, 0, 9100).$this->encodeName($host));
        $a = $this->rr($host, 1, (string) inet_pton('192.168.1.50'));

        // Header: flags=0x8400 (réponse), qd=0, an=3
        return pack('nnnnnn', 0, 0x8400, 0, 3, 0, 0).$ptr.$srv.$a;
    }

    public function test_build_query_has_one_question_per_service(): void
    {
        $query = MdnsScanner::buildQuery(['_ipp._tcp.local', '_printer._tcp.local']);

        $header = unpack('nid/nflags/nqd/nan/nns/nar', substr($query, 0, 12));
        $this->assertSame(2, $header['qd']);
        $this->assertStringContainsString("\x04_ipp\x04_tcp\x05local", $query);
    }

    public function test_parse_message_reads_all_records(): void
    {
        $records = MdnsScanner::parseMessage($this->sampleResponse());

        $this->assertCount(3, $records);
        $types = array_column($records, 'type');
        $this->assertContains(12, $types); // PTR
        $this->assertContains(33, $types); // SRV
        $this->assertContains(1, $types);  // A
    }

    public function test_parse_message_decodes_srv_and_a(): void
    {
        $records = MdnsScanner::parseMessage($this->sampleResponse());

        $srv = null;
        $a = null;
        foreach ($records as $r) {
            if ($r['type'] === 33) {
                $srv = $r['data'];
            }
            if ($r['type'] === 1) {
                $a = $r['data'];
            }
        }

        $this->assertSame(9100, $srv['port']);
        $this->assertSame('epson.local', strtolower($srv['target']));
        $this->assertSame('192.168.1.50', $a);
    }

    public function test_extract_printers_builds_network_config(): void
    {
        $records = MdnsScanner::parseMessage($this->sampleResponse());
        $printers = MdnsScanner::extractPrinters($records);

        $this->assertCount(1, $printers);
        $this->assertSame('network', $printers[0]['connection_type']);
        $this->assertSame('192.168.1.50', $printers[0]['settings']['ip']);
        $this->assertSame(9100, $printers[0]['settings']['port']);
        $this->assertSame('thermal_escpos_raw', $printers[0]['printer_type']);
        $this->assertStringContainsString('EPSON', $printers[0]['name']);
    }

    public function test_extract_printers_ignores_srv_without_a_record(): void
    {
        $records = [
            ['name' => 'x._ipp._tcp.local', 'type' => 33, 'data' => ['priority' => 0, 'weight' => 0, 'port' => 631, 'target' => 'ghost.local']],
        ];

        $this->assertSame([], MdnsScanner::extractPrinters($records));
    }

    public function test_parse_short_message_returns_empty(): void
    {
        $this->assertSame([], MdnsScanner::parseMessage('abc'));
    }
}
