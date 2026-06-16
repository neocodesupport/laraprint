<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Testing;

use Mike42\Escpos\PrintConnectors\PrintConnector;

/**
 * Connecteur factice utilisé par `Laraprint::fake()` : capture en mémoire tout ce qui
 * serait envoyé à l'imprimante, et le transmet au {@see PrintRecorder} à la finalisation.
 */
final class CaptureConnector implements PrintConnector
{
    private string $buffer = '';

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private array $config) {}

    public function __destruct()
    {
        // Rien : la capture est faite dans finalize().
    }

    public function finalize(): void
    {
        PrintRecorder::instance()->record($this->config, $this->buffer);
        $this->buffer = '';
    }

    public function read($len)
    {
        return '';
    }

    public function write($data)
    {
        $this->buffer .= $data;
    }
}
