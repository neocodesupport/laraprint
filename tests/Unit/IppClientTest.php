<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Tests\Unit;

use Neocode\Laraprint\Printing\IppClient;
use PHPUnit\Framework\TestCase;

final class IppClientTest extends TestCase
{
    public function test_request_header_version_and_operation(): void
    {
        $req = IppClient::buildPrintJobRequest('ipp://1.2.3.4:631/ipp/print', 'job', 'application/pdf', 7);

        // Version 1.1
        $this->assertSame(0x01, ord($req[0]));
        $this->assertSame(0x01, ord($req[1]));
        // Operation Print-Job = 0x0002
        $this->assertSame(0x00, ord($req[2]));
        $this->assertSame(0x02, ord($req[3]));
        // request-id = 7
        $this->assertSame(7, (ord($req[4]) << 24) | (ord($req[5]) << 16) | (ord($req[6]) << 8) | ord($req[7]));
        // operation-attributes-tag puis end-of-attributes-tag
        $this->assertSame(0x01, ord($req[8]));
        $this->assertSame(0x03, ord($req[strlen($req) - 1]));
    }

    public function test_request_contains_attributes(): void
    {
        $req = IppClient::buildPrintJobRequest('ipp://printer/ipp/print', 'ticket', 'application/pdf', 1);

        $this->assertStringContainsString('attributes-charset', $req);
        $this->assertStringContainsString('printer-uri', $req);
        $this->assertStringContainsString('ipp://printer/ipp/print', $req);
        $this->assertStringContainsString('document-format', $req);
        $this->assertStringContainsString('application/pdf', $req);
        $this->assertStringContainsString('ticket', $req);
    }

    public function test_uri_to_http_url(): void
    {
        $this->assertSame('http://1.2.3.4:631/ipp/print', IppClient::toHttpUrl('ipp://1.2.3.4:631/ipp/print'));
        $this->assertSame('https://host/ipp/print', IppClient::toHttpUrl('ipps://host/ipp/print'));
        $this->assertSame('http://already', IppClient::toHttpUrl('http://already'));
    }
}
