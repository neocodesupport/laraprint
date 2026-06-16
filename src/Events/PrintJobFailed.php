<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Events;

use Throwable;

/**
 * Émis lorsqu'un job d'impression échoue.
 */
final class PrintJobFailed
{
    /**
     * @param  string  $channel  Canal logique (ex. "thermal.receipt", "direct.file", "spool.file").
     * @param  Throwable  $exception  L'erreur survenue.
     * @param  array<string, mixed>  $connectionConfig  Configuration de connexion utilisée.
     * @param  array<string, mixed>  $context  Métadonnées libres (numéro de vente, chemin du fichier, etc.).
     */
    public function __construct(
        public readonly string $channel,
        public readonly Throwable $exception,
        public readonly array $connectionConfig = [],
        public readonly array $context = [],
    ) {}
}
