<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Tests\Feature;

use Illuminate\Support\Facades\Queue;
use Neocode\Laraprint\Jobs\PrintJob;
use Neocode\Laraprint\Laraprint;
use Neocode\Laraprint\Tests\TestbenchTestCase;

final class PrintJobTest extends TestbenchTestCase
{
    /** @return array{0: string, 1: array<string, mixed>} */
    private function fileTarget(): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'laraprint_job_');

        return [$path, ['connection_type' => 'file', 'settings' => ['path' => $path]]];
    }

    public function test_text_job_writes_to_file_connector(): void
    {
        [$path, $config] = $this->fileTarget();

        try {
            PrintJob::text($config, 'HELLO-JOB')->handle();
            $this->assertStringContainsString('HELLO-JOB', (string) file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    public function test_receipt_job_writes_company_name(): void
    {
        [$path, $config] = $this->fileTarget();

        try {
            PrintJob::receipt(
                $config,
                ['sale_number' => 'S1', 'items' => [], 'subtotal' => 0, 'total_amount' => 0, 'payments' => []],
                ['company' => ['name' => 'TESTCO'], 'qr_code' => ['enabled' => false]],
            )->handle();

            $this->assertStringContainsString('TESTCO', (string) file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    public function test_unknown_kind_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PrintJob(['connection_type' => 'file', 'settings' => ['path' => 'x']], 'bogus'))->handle();
    }

    public function test_queue_text_dispatches_job(): void
    {
        Queue::fake();

        Laraprint::queueText(['connection_type' => 'file', 'settings' => ['path' => 'x']], 'hi');

        Queue::assertPushed(PrintJob::class);
    }

    public function test_queue_receipt_dispatches_job(): void
    {
        Queue::fake();

        Laraprint::queueReceipt(['connection_type' => 'file', 'settings' => ['path' => 'x']], ['sale_number' => 'S1']);

        Queue::assertPushed(PrintJob::class, fn (PrintJob $job) => $job->kind === 'receipt');
    }
}
