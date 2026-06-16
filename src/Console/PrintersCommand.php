<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Console;

use Illuminate\Console\Command;
use Neocode\Laraprint\Models\Printer;
use Neocode\Laraprint\Printers\PrinterRegistry;
use Throwable;

/**
 * Gestion des imprimantes Laraprint depuis le terminal.
 *
 * Exemples :
 *   php artisan laraprint:printers list
 *   php artisan laraprint:printers add --name="Caisse 1" --type=network \
 *       --setting=ip=192.168.1.20 --setting=port=9100 --printer-type=thermal_escpos_raw --default
 *   php artisan laraprint:printers default 1 --machine
 *   php artisan laraprint:printers import
 *   php artisan laraprint:printers test          # défaut machine/session
 *   php artisan laraprint:printers remove 1
 */
class PrintersCommand extends Command
{
    /** @var string */
    protected $signature = 'laraprint:printers
        {action=list : list|add|default|import|remove|test}
        {target? : id ou nom de l\'imprimante (default|remove|test)}
        {--name= : Nom de l\'imprimante (add)}
        {--type=network : Type de connexion network|windows|cups|smb|file|usb (add)}
        {--printer-type= : thermal_escpos_raw|windows_spool_document|cups_spool_document (add)}
        {--setting=* : Paramètre clé=valeur, répétable (add), ex. --setting=ip=192.168.1.20}
        {--default : Marque l\'imprimante comme défaut (add)}
        {--inactive : Crée l\'imprimante désactivée (add)}
        {--machine : Cible/définit le défaut pour la machine courante (default)}';

    /** @var string */
    protected $description = 'Gère les imprimantes Laraprint (liste, ajout, défaut, import, suppression, test).';

    public function handle(PrinterRegistry $registry): int
    {
        $action = (string) $this->argument('action');

        try {
            return match ($action) {
                'list' => $this->listPrinters($registry),
                'add' => $this->addPrinter($registry),
                'default' => $this->setDefault($registry),
                'import' => $this->importPrinters($registry),
                'remove' => $this->removePrinter($registry),
                'test' => $this->testPrinter($registry),
                default => $this->invalidAction($action),
            };
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function listPrinters(PrinterRegistry $registry): int
    {
        $printers = $registry->all();

        if ($printers->isEmpty()) {
            $this->warn('Aucune imprimante enregistrée. Ajoutez-en une avec « laraprint:printers add ».');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Nom', 'Connexion', 'Type', 'Défaut', 'Active', 'Poste'],
            $printers->map(fn (Printer $p) => [
                $p->id,
                $p->name,
                $p->connection_type,
                $p->printer_type ?? '—',
                $p->is_default ? '✓' : '',
                $p->is_active ? '✓' : '✗',
                $p->workstation_id ?? '—',
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function addPrinter(PrinterRegistry $registry): int
    {
        $name = $this->option('name') ?: $this->ask('Nom de l\'imprimante');
        if (! is_string($name) || $name === '') {
            $this->error('Le nom de l\'imprimante est requis.');

            return self::INVALID;
        }

        $settings = [];
        foreach ((array) $this->option('setting') as $pair) {
            [$key, $value] = array_pad(explode('=', (string) $pair, 2), 2, null);
            if ($key !== null && $key !== '' && $value !== null) {
                $settings[$key] = is_numeric($value) ? $value + 0 : $value;
            }
        }

        $printer = $registry->register([
            'name' => $name,
            'connection_type' => (string) $this->option('type'),
            'printer_type' => $this->option('printer-type') ?: null,
            'settings' => $settings,
            'is_active' => ! (bool) $this->option('inactive'),
            'is_default' => (bool) $this->option('default'),
        ]);

        $this->info("Imprimante #{$printer->id} « {$printer->name} » enregistrée.");

        return self::SUCCESS;
    }

    private function setDefault(PrinterRegistry $registry): int
    {
        $target = $this->argument('target');
        if ($target === null) {
            $this->error('Indiquez l\'id ou le nom de l\'imprimante : laraprint:printers default <id|nom>.');

            return self::INVALID;
        }

        $printer = $registry->resolve($this->normalizeTarget($target));

        if ($this->option('machine')) {
            $registry->setDefaultForCurrent($printer);
            $this->info("« {$printer->name} » est désormais le défaut de la machine courante.");
        } else {
            $registry->setDefault($printer);
            $this->info("« {$printer->name} » est désormais l'imprimante par défaut.");
        }

        return self::SUCCESS;
    }

    private function importPrinters(PrinterRegistry $registry): int
    {
        $added = $registry->importSystemPrinters();

        if ($added->isEmpty()) {
            $this->warn('Aucune nouvelle imprimante détectée sur le poste.');

            return self::SUCCESS;
        }

        $this->info("{$added->count()} imprimante(s) importée(s) :");
        foreach ($added as $printer) {
            $this->line("  #{$printer->id}  {$printer->name}  ({$printer->connection_type})");
        }

        return self::SUCCESS;
    }

    private function removePrinter(PrinterRegistry $registry): int
    {
        $target = $this->argument('target');
        if ($target === null) {
            $this->error('Indiquez l\'id ou le nom de l\'imprimante : laraprint:printers remove <id|nom>.');

            return self::INVALID;
        }

        $printer = is_numeric($target)
            ? $registry->find((int) $target)
            : $registry->findByName((string) $target);

        if ($printer === null) {
            $this->error("Imprimante introuvable : {$target}.");

            return self::FAILURE;
        }

        if (! $this->confirm("Supprimer l'imprimante « {$printer->name} » ?", true)) {
            $this->line('Annulé.');

            return self::SUCCESS;
        }

        $registry->forget($printer);
        $this->info("Imprimante « {$printer->name} » supprimée.");

        return self::SUCCESS;
    }

    private function testPrinter(PrinterRegistry $registry): int
    {
        $target = $this->argument('target');
        $selector = $target !== null ? $this->normalizeTarget($target) : null;

        $printer = $registry->resolve($selector);
        $this->info("Impression d'un ticket de test sur « {$printer->name} »…");

        $registry
            ->thermalPrinter($printer, (array) config('laraprint.receipt', []))
            ->printTestReceipt();

        $this->info('Ticket de test envoyé.');

        return self::SUCCESS;
    }

    private function invalidAction(string $action): int
    {
        $this->error("Action inconnue : « {$action} ». Utilisez list|add|default|import|remove|test.");

        return self::INVALID;
    }

    private function normalizeTarget(int|string $target): int|string
    {
        return is_numeric($target) ? (int) $target : (string) $target;
    }
}
