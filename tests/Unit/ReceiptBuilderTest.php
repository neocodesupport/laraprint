<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Tests\Unit;

use Neocode\Laraprint\Laraprint;
use Neocode\Laraprint\Testing\PrintRecorder;
use Neocode\Laraprint\Thermal\ReceiptBuilder;
use PHPUnit\Framework\TestCase;

final class ReceiptBuilderTest extends TestCase
{
    private array $config = ['connection_type' => 'network', 'settings' => ['ip' => '192.0.2.20']];

    protected function tearDown(): void
    {
        PrintRecorder::instance()->disable();
        parent::tearDown();
    }

    public function test_builder_composes_and_prints(): void
    {
        $recorder = Laraprint::fake();

        Laraprint::build($this->config)
            ->center()->bold()->size(2, 2)->line('MA BOUTIQUE')->bold(false)->size(1, 1)
            ->rule()
            ->left()->line('Article A      1 000')
            ->rule()
            ->qr('https://example.com/t/42')
            ->feed(2)
            ->cut()
            ->print();

        $recorder->assertPrintedTimes(1)
            ->assertPrintedContains('MA BOUTIQUE')
            ->assertPrintedContains('Article A');
    }

    public function test_make_returns_builder(): void
    {
        Laraprint::fake();

        $builder = ReceiptBuilder::make($this->config);
        $this->assertInstanceOf(ReceiptBuilder::class, $builder);
        $builder->line('x')->print();
    }
}
