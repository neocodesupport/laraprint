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
}
