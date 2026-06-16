<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Tests\Unit;

use Mike42\Escpos\PrintConnectors\FilePrintConnector;
use Neocode\Laraprint\Connector\ConnectorFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConnectorFactoryTest extends TestCase
{
    public function test_it_rejects_disabled_printer(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('désactivée');

        ConnectorFactory::fromArray([
            'connection_type' => 'network',
            'settings' => ['ip' => '192.168.1.1'],
            'is_active' => false,
        ]);
    }

    public function test_it_rejects_unsupported_connection_type(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non supporté');

        ConnectorFactory::fromArray(['connection_type' => 'carrier-pigeon']);
    }

    public function test_network_requires_ip(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('IP');

        ConnectorFactory::fromArray([
            'connection_type' => 'network',
            'settings' => ['port' => 9100],
        ]);
    }

    public function test_smb_requires_cups_name(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CUPS');

        ConnectorFactory::fromArray([
            'connection_type' => 'smb',
            'settings' => ['ip' => '10.0.0.5', 'share_name' => 'POS'],
        ]);
    }

    public function test_file_requires_path(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Chemin');

        ConnectorFactory::fromArray([
            'connection_type' => 'file',
            'settings' => [],
        ]);
    }

    public function test_file_connector_is_created_for_valid_path(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'laraprint_test_');
        $this->assertNotFalse($path);

        try {
            $connector = ConnectorFactory::fromArray([
                'connection_type' => 'file',
                'settings' => ['path' => $path],
            ]);

            $this->assertInstanceOf(FilePrintConnector::class, $connector);
            $connector->finalize();
        } finally {
            @unlink($path);
        }
    }
}
