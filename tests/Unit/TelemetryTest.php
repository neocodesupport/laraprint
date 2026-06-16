<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Tests\Unit;

use Illuminate\Container\Container;
use Neocode\Laraprint\Events\PrintJobStarted;
use Neocode\Laraprint\Support\Telemetry;
use PHPUnit\Framework\TestCase;

final class TelemetryTest extends TestCase
{
    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    public function test_event_is_a_noop_without_bound_dispatcher(): void
    {
        Container::setInstance(new Container);

        // Ne doit lever aucune exception même sans service "events".
        Telemetry::event(new PrintJobStarted('test'));

        $this->assertTrue(true);
    }

    public function test_log_is_a_noop_without_bound_logger(): void
    {
        Container::setInstance(new Container);

        Telemetry::log('info', 'rien ne casse');

        $this->assertTrue(true);
    }

    public function test_event_is_dispatched_when_events_service_is_bound(): void
    {
        $spy = new class
        {
            public array $dispatched = [];

            public function dispatch(object $event): void
            {
                $this->dispatched[] = $event;
            }
        };

        $container = new Container;
        $container->instance('events', $spy);
        Container::setInstance($container);

        $event = new PrintJobStarted('thermal.receipt', ['connection_type' => 'network']);
        Telemetry::event($event);

        $this->assertCount(1, $spy->dispatched);
        $this->assertSame($event, $spy->dispatched[0]);
    }

    public function test_log_is_forwarded_with_prefix_when_logger_is_bound(): void
    {
        $spy = new class
        {
            public array $entries = [];

            public function log(string $level, string $message, array $context = []): void
            {
                $this->entries[] = [$level, $message, $context];
            }
        };

        $container = new Container;
        $container->instance('log', $spy);
        Container::setInstance($container);

        Telemetry::log('error', 'boom', ['sale_number' => 'S1']);

        $this->assertCount(1, $spy->entries);
        $this->assertSame('error', $spy->entries[0][0]);
        $this->assertStringStartsWith('[laraprint] ', $spy->entries[0][1]);
        $this->assertSame(['sale_number' => 'S1'], $spy->entries[0][2]);
    }
}
