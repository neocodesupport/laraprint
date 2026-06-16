<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Neocode\Laraprint\Jobs\PrintJob;
use Neocode\Laraprint\Laraprint;
use Neocode\Laraprint\Models\PrintJobRecord;
use Neocode\Laraprint\Tests\TestbenchTestCase;

final class PrintJobTrackingTest extends TestbenchTestCase
{
    private function fileConfig(string &$path): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'laraprint_track_');

        return ['connection_type' => 'file', 'settings' => ['path' => $path]];
    }

    public function test_record_is_marked_completed(): void
    {
        $path = '';
        $config = $this->fileConfig($path);
        $record = PrintJobRecord::query()->create(['uuid' => (string) Str::uuid(), 'kind' => 'text', 'status' => 'queued']);

        try {
            $job = PrintJob::text($config, 'HI');
            $job->recordId = (int) $record->id;
            $job->handle();

            $record->refresh();
            $this->assertSame(PrintJobRecord::STATUS_COMPLETED, $record->status);
            $this->assertSame(1, $record->attempts);
            $this->assertNotNull($record->finished_at);
        } finally {
            @unlink($path);
        }
    }

    public function test_record_is_marked_failed_on_error(): void
    {
        // Config réseau sans IP => exception à la connexion.
        $record = PrintJobRecord::query()->create(['uuid' => (string) Str::uuid(), 'kind' => 'text', 'status' => 'queued']);

        $job = PrintJob::text(['connection_type' => 'network', 'settings' => []], 'HI');
        $job->recordId = (int) $record->id;

        try {
            $job->handle();
        } catch (\Throwable) {
            // attendu
        }

        $record->refresh();
        $this->assertSame(PrintJobRecord::STATUS_FAILED, $record->status);
        $this->assertNotNull($record->error);
    }

    public function test_queue_helper_creates_queued_record(): void
    {
        Queue::fake();

        Laraprint::queueReceipt(
            ['connection_type' => 'file', 'settings' => ['path' => 'x']],
            ['sale_number' => 'SALE-9'],
        );

        $this->assertDatabaseHas('print_jobs', [
            'kind' => 'receipt',
            'status' => 'queued',
        ]);

        $record = PrintJobRecord::query()->first();
        $this->assertSame(['sale_number' => 'SALE-9'], $record->context);
    }

    public function test_job_runs_without_tracking_when_no_record(): void
    {
        $path = '';
        $config = $this->fileConfig($path);

        try {
            // recordId null => pas de suivi, mais l'impression doit fonctionner.
            PrintJob::text($config, 'NO-TRACK')->handle();
            $this->assertStringContainsString('NO-TRACK', (string) file_get_contents($path));
            $this->assertSame(0, PrintJobRecord::query()->count());
        } finally {
            @unlink($path);
        }
    }
}
