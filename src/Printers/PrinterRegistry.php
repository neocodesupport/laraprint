<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Printers;

use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Neocode\Laraprint\DirectPrinter;
use Neocode\Laraprint\Discovery\LocalPrinters;
use Neocode\Laraprint\Discovery\MdnsScanner;
use Neocode\Laraprint\Discovery\NetworkScanner;
use Neocode\Laraprint\Discovery\SystemPrinters;
use Neocode\Laraprint\Events\PrinterDiscovered;
use Neocode\Laraprint\Models\Printer;
use Neocode\Laraprint\Models\Workstation;
use Neocode\Laraprint\Support\ConnectionType;
use Neocode\Laraprint\Support\PrinterType;
use Neocode\Laraprint\Support\ReceiptConfig;
use Neocode\Laraprint\Thermal\ThermalPrinter;
use RuntimeException;

/**
 * Gestion des imprimantes enregistrées (persistées en base) :
 * lister, choisir, ajouter, importer depuis le système, définir l'imprimante par défaut,
 * et obtenir une imprimante prête à imprimer.
 *
 * Couche optionnelle : nécessite les migrations du SDK (workstations, printers,
 * printer_credentials). Pour un usage sans base de données, utilisez directement
 * {@see DirectPrinter} / {@see ThermalPrinter} avec un tableau de configuration.
 */
final class PrinterRegistry
{
    private CurrentWorkstation $machine;

    public function __construct(?CurrentWorkstation $machine = null)
    {
        $this->machine = $machine ?? new CurrentWorkstation;
    }

    /* -----------------------------------------------------------------
     |  Lister / choisir
     | -----------------------------------------------------------------
     */

    /**
     * Toutes les imprimantes enregistrées, triées par nom.
     *
     * @return Collection<int, Printer>
     */
    public function all(): Collection
    {
        return Printer::query()->orderBy('name')->get();
    }

    /**
     * Uniquement les imprimantes actives.
     *
     * @return Collection<int, Printer>
     */
    public function active(): Collection
    {
        return Printer::query()->active()->orderBy('name')->get();
    }

    public function find(int $id): ?Printer
    {
        return Printer::query()->find($id);
    }

    public function findByName(string $name): ?Printer
    {
        return Printer::query()->where('name', $name)->first();
    }

    /**
     * Imprimante par défaut (active). Si $workstationId est fourni, la recherche
     * est limitée à ce poste ; sinon on prend la première imprimante par défaut.
     */
    public function default(?int $workstationId = null): ?Printer
    {
        $query = Printer::query()->default()->active();

        if ($workstationId !== null) {
            $query->forWorkstation($workstationId);
        }

        return $query->first();
    }

    /* -----------------------------------------------------------------
     |  Ajouter
     | -----------------------------------------------------------------
     */

    /**
     * Enregistre une nouvelle imprimante.
     *
     * @param  array{name?: string, connection_type?: string, type?: string, printer_type?: string|PrinterType|null, settings?: array<string, mixed>, model?: string, workstation_id?: int|null, is_active?: bool, is_default?: bool}  $attributes
     * @param  array{username?: string, password?: string, domain?: string}|null  $credentials
     */
    public function register(array $attributes, ?array $credentials = null): Printer
    {
        $name = $attributes['name'] ?? null;
        if ($name === null || $name === '') {
            throw new InvalidArgumentException("Le nom de l'imprimante est requis.");
        }

        $makeDefault = (bool) ($attributes['is_default'] ?? false);
        $connectionType = $this->normalizeConnectionType($attributes['connection_type'] ?? $attributes['type'] ?? 'network');

        $printer = new Printer;
        $printer->fill([
            'workstation_id' => $attributes['workstation_id'] ?? null,
            'name' => $name,
            'connection_type' => $connectionType,
            'printer_type' => $this->normalizePrinterType($attributes['printer_type'] ?? null),
            'model' => $attributes['model'] ?? null,
            'is_active' => (bool) ($attributes['is_active'] ?? true),
            'is_default' => false, // défini via makeDefault() pour garantir l'unicité
            'settings' => $attributes['settings'] ?? [],
        ]);
        $printer->save();

        if ($credentials !== null && $credentials !== []) {
            $printer->credentials()->create($credentials);
        }

        if ($makeDefault) {
            $printer->makeDefault();
        }

        return $printer->refresh();
    }

    /**
     * Découvre les imprimantes du système d'exploitation (files configurées) et enregistre
     * celles qui ne sont pas déjà connues (par nom). Retourne les imprimantes ajoutées.
     *
     * @return Collection<int, Printer>
     */
    public function importSystemPrinters(?int $workstationId = null): Collection
    {
        return $this->importConfigs(SystemPrinters::listPrinters(), $workstationId, 'system');
    }

    /**
     * Découvre les imprimantes **connectées localement** (USB / port parallèle) et enregistre
     * celles qui ne sont pas déjà connues.
     *
     * @return Collection<int, Printer>
     */
    public function importUsbPrinters(?int $workstationId = null): Collection
    {
        return $this->importConfigs(LocalPrinters::listUsb(), $workstationId, 'usb');
    }

    /**
     * Scanne le **réseau** local et enregistre les imprimantes détectées non encore connues.
     *
     * @param  string|null  $range  Plage à scanner (CIDR/intervalle/IP), ou null pour le(s) sous-réseau(x) local/locaux.
     * @param  list<int>  $ports  Ports à tester (défaut : {@see NetworkScanner::DEFAULT_PORTS}).
     * @return Collection<int, Printer>
     */
    public function importNetworkPrinters(
        ?string $range = null,
        ?int $workstationId = null,
        array $ports = NetworkScanner::DEFAULT_PORTS,
        float $timeout = 1.0,
    ): Collection {
        return $this->importConfigs((new NetworkScanner)->scan($range, $ports, $timeout), $workstationId, 'network');
    }

    /**
     * Découvre les imprimantes via mDNS / Bonjour (AirPrint) et enregistre les nouvelles.
     *
     * @return Collection<int, Printer>
     */
    public function importAirPrintPrinters(?int $workstationId = null, float $timeout = 2.0): Collection
    {
        return $this->importConfigs((new MdnsScanner)->discover($timeout), $workstationId, 'mdns');
    }

    /**
     * Enregistre les configurations découvertes qui ne sont pas déjà connues (par nom).
     *
     * @param  iterable<array{connection_type?: string, settings?: array<string, mixed>, name?: string, printer_type?: string}>  $configs
     * @return Collection<int, Printer>
     */
    private function importConfigs(iterable $configs, ?int $workstationId, string $source = 'discovery'): Collection
    {
        /** @var Collection<int, Printer> $imported */
        $imported = new Collection;

        foreach ($configs as $config) {
            $name = $config['name'] ?? null;
            if ($name === null || $name === '' || $this->findByName($name) !== null) {
                continue;
            }

            $printer = $this->register([
                'workstation_id' => $workstationId,
                'name' => $name,
                'connection_type' => $config['connection_type'] ?? 'network',
                'printer_type' => $config['printer_type'] ?? null,
                'settings' => $config['settings'] ?? [],
            ]);

            event(new PrinterDiscovered($printer, $source));
            $imported->push($printer);
        }

        return $imported;
    }

    /* -----------------------------------------------------------------
     |  Imprimante par défaut / suppression
     | -----------------------------------------------------------------
     */

    /**
     * Définit l'imprimante par défaut (en retirant ce statut aux autres du même périmètre).
     */
    public function setDefault(Printer|int $printer): Printer
    {
        $model = $printer instanceof Printer ? $printer : $this->find($printer);
        if ($model === null) {
            throw new InvalidArgumentException('Imprimante introuvable : '.$printer);
        }

        return $model->makeDefault();
    }

    public function forget(Printer|int $printer): void
    {
        $model = $printer instanceof Printer ? $printer : $this->find($printer);
        $model?->delete();
    }

    /* -----------------------------------------------------------------
     |  Machine (poste) courante & session
     | -----------------------------------------------------------------
     */

    /**
     * Le poste (ordinateur) courant, identifié par nom d'hôte / session / config.
     */
    public function currentWorkstation(): ?Workstation
    {
        return $this->machine->resolve();
    }

    /**
     * Imprimante par défaut de la machine courante.
     * Repli sur le défaut global si le poste n'en a pas.
     */
    public function defaultForCurrent(): ?Printer
    {
        $workstationId = $this->machine->id();

        return ($workstationId !== null ? $this->default($workstationId) : null)
            ?? $this->default();
    }

    /**
     * Définit l'imprimante par défaut **pour la machine courante** : l'imprimante
     * est rattachée au poste courant (créé si besoin) puis marquée par défaut.
     */
    public function setDefaultForCurrent(Printer|int $printer): Printer
    {
        $model = $printer instanceof Printer ? $printer : $this->find($printer);
        if ($model === null) {
            throw new InvalidArgumentException('Imprimante introuvable : '.$printer);
        }

        $workstation = $this->machine->resolveOrCreate();
        $model->forceFill(['workstation_id' => $workstation->getKey()])->save();

        return $model->makeDefault();
    }

    /**
     * Choisit une imprimante pour la **session** courante (surcharge le défaut machine
     * jusqu'à la fin de la session). Renvoie l'imprimante choisie.
     */
    public function selectForSession(Printer|int|string $printer): Printer
    {
        $model = $this->resolve($printer);
        $this->machine->selectPrinter((int) $model->getKey());

        return $model;
    }

    /**
     * Oublie la sélection de session (on repart sur le défaut machine).
     */
    public function clearSessionSelection(): void
    {
        $this->machine->forgetSelectedPrinter();
    }

    /* -----------------------------------------------------------------
     |  Pont vers l'impression (choisir avant d'imprimer)
     | -----------------------------------------------------------------
     */

    /**
     * Configuration de connexion d'une imprimante choisie (ou de la valeur par défaut si null).
     *
     * @param  Printer|int|string|null  $printer  Instance, identifiant, nom, ou null pour le défaut.
     * @return array<string, mixed>
     */
    public function connectionConfig(Printer|int|string|null $printer = null): array
    {
        return $this->resolve($printer)->getConnectionConfig();
    }

    /**
     * Retourne un {@see DirectPrinter} prêt à l'emploi pour l'imprimante choisie.
     */
    public function printer(Printer|int|string|null $printer = null): DirectPrinter
    {
        return DirectPrinter::forPrinter($this->connectionConfig($printer));
    }

    /**
     * Retourne un {@see ThermalPrinter} prêt à l'emploi pour l'imprimante choisie.
     */
    public function thermalPrinter(
        Printer|int|string|null $printer,
        array|ReceiptConfig $receiptConfig,
    ): ThermalPrinter {
        return ThermalPrinter::fromConnectionConfig($this->connectionConfig($printer), $receiptConfig);
    }

    /**
     * Résout une imprimante à partir d'une instance, d'un id, d'un nom ou du défaut.
     */
    public function resolve(Printer|int|string|null $printer = null): Printer
    {
        $model = match (true) {
            $printer instanceof Printer => $printer,
            is_int($printer) => $this->find($printer),
            is_string($printer) => $this->findByName($printer),
            default => $this->resolveDefault(),
        };

        if ($model === null) {
            throw new InvalidArgumentException(
                $printer === null
                    ? 'Aucune imprimante par défaut définie pour ce poste.'
                    : 'Imprimante introuvable : '.(is_scalar($printer) ? (string) $printer : 'inconnue')
            );
        }

        if (! $model->is_active) {
            throw new RuntimeException("L'imprimante « {$model->name} » est désactivée.");
        }

        return $model;
    }

    /**
     * Résolution de l'imprimante par défaut « contextuelle » :
     * 1. imprimante choisie pour la session courante ;
     * 2. imprimante par défaut de la machine courante ;
     * 3. imprimante par défaut globale.
     */
    private function resolveDefault(): ?Printer
    {
        $selectedId = $this->machine->selectedPrinterId();
        if ($selectedId !== null) {
            $selected = $this->find($selectedId);
            if ($selected !== null) {
                return $selected;
            }
        }

        $workstationId = $this->machine->id();
        if ($workstationId !== null) {
            $machineDefault = $this->default($workstationId);
            if ($machineDefault !== null) {
                return $machineDefault;
            }
        }

        return $this->default();
    }

    private function normalizeConnectionType(string $type): string
    {
        $normalized = strtolower(trim($type));
        if (ConnectionType::tryFrom($normalized) === null) {
            throw new InvalidArgumentException(sprintf(
                'connection_type invalide : « %s ». Valeurs autorisées : %s.',
                $type,
                implode(', ', ConnectionType::values()),
            ));
        }

        return $normalized;
    }

    private function normalizePrinterType(string|PrinterType|null $type): ?string
    {
        if ($type === null) {
            return null;
        }
        if ($type instanceof PrinterType) {
            return $type->value;
        }
        if (PrinterType::tryFrom($type) === null) {
            throw new InvalidArgumentException(sprintf('printer_type invalide : %s', $type));
        }

        return $type;
    }
}
