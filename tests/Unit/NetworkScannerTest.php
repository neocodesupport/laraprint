<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Tests\Unit;

use InvalidArgumentException;
use Neocode\Laraprint\Discovery\NetworkScanner;
use PHPUnit\Framework\TestCase;

final class NetworkScannerTest extends TestCase
{
    public function test_single_ip(): void
    {
        $this->assertSame(['192.168.1.50'], NetworkScanner::expandRange('192.168.1.50'));
    }

    public function test_invalid_ip_returns_empty(): void
    {
        $this->assertSame([], NetworkScanner::expandRange('not-an-ip'));
    }

    public function test_cidr_24_excludes_network_and_broadcast(): void
    {
        $ips = NetworkScanner::expandRange('192.168.1.0/24');

        $this->assertCount(254, $ips);
        $this->assertSame('192.168.1.1', $ips[0]);
        $this->assertSame('192.168.1.254', $ips[253]);
        $this->assertNotContains('192.168.1.0', $ips);
        $this->assertNotContains('192.168.1.255', $ips);
    }

    public function test_cidr_30(): void
    {
        $ips = NetworkScanner::expandRange('10.0.0.0/30');

        // /30 -> .0 réseau, .3 diffusion, hôtes .1 et .2
        $this->assertSame(['10.0.0.1', '10.0.0.2'], $ips);
    }

    public function test_cidr_32_is_single_host(): void
    {
        $this->assertSame(['10.0.0.5'], NetworkScanner::expandRange('10.0.0.5/32'));
    }

    public function test_dash_range_last_octet(): void
    {
        $ips = NetworkScanner::expandRange('192.168.1.10-12');

        $this->assertSame(['192.168.1.10', '192.168.1.11', '192.168.1.12'], $ips);
    }

    public function test_dash_range_full_ips(): void
    {
        $ips = NetworkScanner::expandRange('192.168.1.254-192.168.2.1');

        $this->assertSame(['192.168.1.254', '192.168.1.255', '192.168.2.0', '192.168.2.1'], $ips);
    }

    public function test_reversed_dash_range_returns_empty(): void
    {
        $this->assertSame([], NetworkScanner::expandRange('192.168.1.50-10'));
    }

    public function test_too_large_range_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        NetworkScanner::expandRange('10.0.0.0/8');
    }

    public function test_cidr_to_network_normalises_host_to_network(): void
    {
        $this->assertSame('192.168.1.0/24', NetworkScanner::cidrToNetwork('192.168.1.10/24'));
        $this->assertSame('10.10.0.0/16', NetworkScanner::cidrToNetwork('10.10.4.7/16'));
        $this->assertSame('172.16.0.0/12', NetworkScanner::cidrToNetwork('172.16.5.9/12'));
    }

    public function test_cidr_to_network_rejects_garbage(): void
    {
        $this->assertNull(NetworkScanner::cidrToNetwork('not-an-ip/24'));
        $this->assertNull(NetworkScanner::cidrToNetwork('192.168.1.10'));
        $this->assertNull(NetworkScanner::cidrToNetwork('192.168.1.10/40'));
    }

    public function test_parse_windows_addresses_keeps_local_drops_loopback_and_apipa(): void
    {
        $output = "127.0.0.1/8\r\n192.168.1.10/24\r\n169.254.20.5/16\r\n10.0.0.5/8\r\n";

        $this->assertSame(
            ['192.168.1.10/24', '10.0.0.5/8'],
            NetworkScanner::parseWindowsAddresses($output),
        );
    }

    public function test_parse_ip_addr_extracts_inet_cidrs(): void
    {
        $output = <<<'TXT'
        1: lo    inet 127.0.0.1/8 scope host lo
        2: eth0    inet 192.168.1.10/24 brd 192.168.1.255 scope global eth0
        3: wlan0    inet 10.10.4.7/16 brd 10.10.255.255 scope global wlan0
        TXT;

        $this->assertSame(
            ['192.168.1.10/24', '10.10.4.7/16'],
            NetworkScanner::parseIpAddr($output),
        );
    }

    public function test_printer_type_for_port(): void
    {
        $this->assertSame('thermal_escpos_raw', NetworkScanner::printerTypeForPort(9100));
        $this->assertNull(NetworkScanner::printerTypeForPort(631));
        $this->assertNull(NetworkScanner::printerTypeForPort(515));
    }
}
