<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Neocode\Laraprint\Models\PrintJobRecord;

/**
 * Liste les jobs d'impression récents (table `print_jobs`).
 *
 *   php artisan laraprint:jobs
 *   php artisan laraprint:jobs --status=failed --limit=50
 */
class PrintJobsCommand extends Command
{
    /** @var string */
    protected $signature = 'laraprint:jobs
        {--status= : Filtre par statut (queued|printing|completed|failed)}
        {--limit=20 : Nombre de lignes à afficher}';

    /** @var string */
    protected $description = 'Liste les jobs d\'impression récents (suivi de la file d\'attente).';

    public function handle(): int
    {
        if (! Schema::hasTable('print_jobs')) {
            $this->warn('Table « print_jobs » absente. Publiez et exécutez les migrations du SDK.');

            return self::SUCCESS;
        }

        $query = PrintJobRecord::query()->latest()->limit((int) $this->option('limit'));
        if ($status = $this->option('status')) {
            $query->status((string) $status);
        }

        $jobs = $query->get();
        if ($jobs->isEmpty()) {
            $this->info('Aucun job d\'impression.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'UUID', 'Type', 'Statut', 'Tentatives', 'Erreur', 'Créé le'],
            $jobs->map(fn (PrintJobRecord $job) => [
                $job->id,
                substr($job->uuid, 0, 8),
                $job->kind,
                $job->status,
                $job->attempts,
                $job->error !== null ? mb_strimwidth($job->error, 0, 32, '…') : '',
                (string) $job->created_at,
            ])->all(),
        );

        return self::SUCCESS;
    }
}
