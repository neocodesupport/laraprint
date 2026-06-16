<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Testing;

use PHPUnit\Framework\Assert;

/**
 * Enregistreur d'impressions pour les tests (`Laraprint::fake()`).
 *
 * Quand le mode « fake » est actif, les connecteurs réels sont remplacés par un
 * {@see CaptureConnector} qui capture le contenu au lieu de l'envoyer à une imprimante.
 * Le recorder conserve ces impressions et fournit des assertions à la `Mail::fake()`.
 *
 * @phpstan-type Record array{channel: string, config: array<string, mixed>, content: string}
 */
final class PrintRecorder
{
    private static ?self $instance = null;

    private bool $faking = false;

    /** @var list<array{channel: string, config: array<string, mixed>, content: string}> */
    private array $records = [];

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    public static function isFaking(): bool
    {
        return self::$instance !== null && self::$instance->faking;
    }

    public function enable(): self
    {
        $this->faking = true;
        $this->records = [];

        return $this;
    }

    public function disable(): void
    {
        $this->faking = false;
        $this->records = [];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function record(array $config, string $content, string $channel = 'print'): void
    {
        $this->records[] = ['channel' => $channel, 'config' => $config, 'content' => $content];
    }

    /**
     * @return list<array{channel: string, config: array<string, mixed>, content: string}>
     */
    public function recorded(): array
    {
        return $this->records;
    }

    public function count(): int
    {
        return count($this->records);
    }

    /* ----------------------------------------------------------------- Assertions */

    /**
     * @param  (callable(array{channel: string, config: array<string, mixed>, content: string}): bool)|null  $callback
     */
    public function assertPrinted(?callable $callback = null): self
    {
        if ($callback === null) {
            Assert::assertNotEmpty($this->records, 'Aucune impression enregistrée.');

            return $this;
        }

        $matched = false;
        foreach ($this->records as $record) {
            if ($callback($record)) {
                $matched = true;
                break;
            }
        }

        Assert::assertTrue($matched, 'Aucune impression ne correspond aux critères attendus.');

        return $this;
    }

    public function assertNothingPrinted(): self
    {
        Assert::assertSame(0, $this->count(), 'Des impressions ont été enregistrées alors qu\'aucune n\'était attendue.');

        return $this;
    }

    public function assertPrintedTimes(int $expected): self
    {
        Assert::assertSame($expected, $this->count(), sprintf(
            '%d impression(s) attendue(s), %d enregistrée(s).',
            $expected,
            $this->count(),
        ));

        return $this;
    }

    public function assertPrintedContains(string $needle): self
    {
        $matched = false;
        foreach ($this->records as $record) {
            if (str_contains($record['content'], $needle)) {
                $matched = true;
                break;
            }
        }

        Assert::assertTrue($matched, sprintf('Aucune impression ne contient « %s ».', $needle));

        return $this;
    }
}
