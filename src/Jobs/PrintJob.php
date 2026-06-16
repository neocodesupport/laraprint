<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Neocode\Laraprint\DirectPrinter;
use Neocode\Laraprint\Laraprint;
use Neocode\Laraprint\Models\PrintJobRecord;
use Neocode\Laraprint\Thermal\ThermalPrinter;
use Throwable;

/**
 * Job d'impression **asynchrone** (file d'attente Laravel).
 *
 * Sérialise une configuration de connexion + un contenu, et imprime au moment du
 * traitement par le worker. Idéal pour ne pas bloquer la requête HTTP et pour réessayer
 * automatiquement en cas d'imprimante momentanément indisponible.
 *
 * Exemples :
 *   PrintJob::text($config, "Ticket\n")->onQueue('print');
 *   dispatch(PrintJob::receipt($config, $data, config('laraprint.receipt')));
 *   Laraprint::queueReceipt($config, $data);
 */
class PrintJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Nombre de tentatives avant échec définitif. */
    public int $tries = 3;

    /** Délai (secondes) entre les tentatives. */
    public int $backoff = 5;

    /**
     * @param  array<string, mixed>  $connectionConfig
     * @param  'text'|'raw'|'file'|'receipt'  $kind
     * @param  string|array<string, mixed>|null  $payload
     * @param  array<string, mixed>|null  $receiptConfig
     */
    /** Identifiant de la trace `print_jobs` (suivi optionnel). */
    public ?int $recordId = null;

    public function __construct(
        public array $connectionConfig,
        public string $kind,
        public string|array|null $payload = null,
        public bool $asText = false,
        public bool $cut = true,
        public ?array $receiptConfig = null,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function text(array $config, string $text, bool $cut = true): self
    {
        return new self($config, 'text', $text, cut: $cut);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function raw(array $config, string $data): self
    {
        return new self($config, 'raw', $data);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function file(array $config, string $path, bool $asText = false): self
    {
        return new self($config, 'file', $path, asText: $asText);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $receiptConfig
     */
    public static function receipt(array $config, array $data, ?array $receiptConfig = null): self
    {
        return new self($config, 'receipt', $data, receiptConfig: $receiptConfig);
    }

    public function handle(): void
    {
        $record = $this->trackingRecord();
        $record?->markPrinting();

        try {
            match ($this->kind) {
                'text' => $this->handleText(),
                'raw' => DirectPrinter::forPrinter($this->connectionConfig)
                    ->printRaw((string) $this->payload)
                    ->close(),
                'file' => Laraprint::printFile((string) $this->payload, $this->connectionConfig, $this->asText),
                'receipt' => ThermalPrinter::fromConnectionConfig(
                    $this->connectionConfig,
                    $this->receiptConfig ?? [],
                )->printReceipt((array) $this->payload),
                default => throw new InvalidArgumentException("Type d'impression inconnu : {$this->kind}"),
            };

            $record?->markCompleted();
        } catch (Throwable $e) {
            $record?->markFailed($e->getMessage());
            throw $e;
        }
    }

    private function trackingRecord(): ?PrintJobRecord
    {
        if ($this->recordId === null) {
            return null;
        }

        try {
            if (! Schema::hasTable('print_jobs')) {
                return null;
            }

            return PrintJobRecord::query()->find($this->recordId);
        } catch (Throwable) {
            return null;
        }
    }

    private function handleText(): void
    {
        $printer = DirectPrinter::forPrinter($this->connectionConfig)
            ->printText((string) $this->payload);

        if ($this->cut) {
            $printer->cut();
        }

        $printer->close();
    }
}
