<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Events;

/**
 * Émis juste avant l'envoi d'un job d'impression.
 */
final class PrintJobStarted
{
    /**
     * @param  string  $channel  Canal logique (ex. "thermal.receipt", "direct.file", "spool.file").
     * @param  array<string, mixed>  $connectionConfig  Configuration de connexion utilisée.
     * @param  array<string, mixed>  $context  Métadonnées libres (numéro de vente, chemin du fichier, etc.).
     */
    public function __construct(
        public readonly string $channel,
        public readonly array $connectionConfig = [],
        public readonly array $context = [],
    ) {}
}
