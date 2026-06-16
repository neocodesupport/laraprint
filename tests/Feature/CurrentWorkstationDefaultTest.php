<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Tests\Feature;

use Illuminate\Container\Container;
use Neocode\Laraprint\Models\Workstation;
use Neocode\Laraprint\Printers\CurrentWorkstation;
use Neocode\Laraprint\Printers\PrinterRegistry;
use Neocode\Laraprint\Tests\DatabaseTestCase;

/**
 * Vérifie que l'imprimante par défaut est liée à la machine (poste) courante.
 *
 * Le poste courant est simulé via une sous-classe de CurrentWorkstation qui force
 * l'identifiant machine (au lieu de dépendre du vrai nom d'hôte).
 */
final class CurrentWorkstationDefaultTest extends DatabaseTestCase
{
    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    private function registryForMachine(string $identifier): PrinterRegistry
    {
        return new PrinterRegistry(new CurrentWorkstation($identifier));
    }

    public function test_each_machine_has_its_own_default_printer(): void
    {
        // Deux postes physiques distincts.
        $posteA = Workstation::query()->create(['name' => 'POSTE-A', 'hostname' => 'POSTE-A', 'ip_address' => '10.0.0.1']);
        $posteB = Workstation::query()->create(['name' => 'POSTE-B', 'hostname' => 'POSTE-B', 'ip_address' => '10.0.0.2']);

        $regA = $this->registryForMachine('POSTE-A');
        $regB = $this->registryForMachine('POSTE-B');

        $printerA = $regA->register(['name' => 'Caisse A', 'workstation_id' => $posteA->id, 'settings' => ['ip' => '10.0.0.11'], 'is_default' => true]);
        $printerB = $regB->register(['name' => 'Caisse B', 'workstation_id' => $posteB->id, 'settings' => ['ip' => '10.0.0.12'], 'is_default' => true]);

        // Chaque machine résout SON propre défaut.
        $this->assertSame($printerA->id, $regA->defaultForCurrent()?->id);
        $this->assertSame($printerB->id, $regB->defaultForCurrent()?->id);

        // resolve(null) suit la machine courante.
        $this->assertSame('Caisse A', $regA->resolve(null)->name);
        $this->assertSame('Caisse B', $regB->resolve(null)->name);
    }

    public function test_set_default_for_current_attaches_printer_to_current_machine(): void
    {
        $reg = $this->registryForMachine('POSTE-X');

        // Imprimante sans poste au départ.
        $printer = $reg->register(['name' => 'Imprimante libre', 'settings' => ['ip' => '10.0.0.50']]);
        $this->assertNull($printer->workstation_id);

        $reg->setDefaultForCurrent($printer);

        // Le poste POSTE-X a été créé et l'imprimante y est rattachée + par défaut.
        $current = $reg->currentWorkstation();
        $this->assertNotNull($current);
        $this->assertSame('POSTE-X', $current->hostname);
        $this->assertSame($current->id, $printer->fresh()->workstation_id);
        $this->assertTrue($printer->fresh()->is_default);
        $this->assertSame($printer->id, $reg->defaultForCurrent()?->id);
    }

    public function test_setting_default_on_one_machine_does_not_affect_another(): void
    {
        $posteA = Workstation::query()->create(['name' => 'A', 'hostname' => 'A', 'ip_address' => '10.0.0.1']);
        $posteB = Workstation::query()->create(['name' => 'B', 'hostname' => 'B', 'ip_address' => '10.0.0.2']);

        $regA = $this->registryForMachine('A');
        $regB = $this->registryForMachine('B');

        $a = $regA->register(['name' => 'PA', 'workstation_id' => $posteA->id, 'settings' => ['ip' => '10.0.0.11'], 'is_default' => true]);
        $b = $regB->register(['name' => 'PB', 'workstation_id' => $posteB->id, 'settings' => ['ip' => '10.0.0.12'], 'is_default' => true]);

        // Redéfinir le défaut du poste A ne touche pas le poste B.
        $a2 = $regA->register(['name' => 'PA2', 'workstation_id' => $posteA->id, 'settings' => ['ip' => '10.0.0.13']]);
        $regA->setDefault($a2);

        $this->assertFalse($a->fresh()->is_default);
        $this->assertTrue($a2->fresh()->is_default);
        $this->assertTrue($b->fresh()->is_default, 'Le défaut du poste B doit rester intact.');
    }

    public function test_falls_back_to_global_default_when_machine_has_none(): void
    {
        // Défaut global sans poste.
        $reg = $this->registryForMachine('UNKNOWN-HOST');
        $global = $reg->register(['name' => 'Globale', 'settings' => ['ip' => '10.0.0.99'], 'is_default' => true]);

        // La machine courante n'a pas de poste enregistré -> repli sur le défaut global.
        $this->assertSame($global->id, $reg->defaultForCurrent()?->id);
    }

    public function test_session_selection_overrides_machine_default(): void
    {
        // Faux service "session" lié au conteneur (in-memory).
        $session = new class
        {
            private array $store = [];

            public function get(string $key): mixed
            {
                return $this->store[$key] ?? null;
            }

            public function put(string $key, mixed $value): void
            {
                $this->store[$key] = $value;
            }

            public function forget(string $key): void
            {
                unset($this->store[$key]);
            }
        };

        $container = new Container;
        $container->instance('session', $session);
        Container::setInstance($container);

        $poste = Workstation::query()->create(['name' => 'POSTE-S', 'hostname' => 'POSTE-S', 'ip_address' => '10.0.0.1']);
        $reg = $this->registryForMachine('POSTE-S');

        $defautMachine = $reg->register(['name' => 'Défaut machine', 'workstation_id' => $poste->id, 'settings' => ['ip' => '10.0.0.11'], 'is_default' => true]);
        $autre = $reg->register(['name' => 'Autre', 'workstation_id' => $poste->id, 'settings' => ['ip' => '10.0.0.12']]);

        // Sans sélection de session : défaut machine.
        $this->assertSame($defautMachine->id, $reg->resolve(null)->id);

        // Avec sélection de session : surcharge.
        $reg->selectForSession($autre->id);
        $this->assertSame($autre->id, $reg->resolve(null)->id);

        // Après nettoyage : retour au défaut machine.
        $reg->clearSessionSelection();
        $this->assertSame($defautMachine->id, $reg->resolve(null)->id);
    }
}
