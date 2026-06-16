<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Support;

use Illuminate\Container\Container;
use Throwable;

/**
 * Pont optionnel vers les événements et les logs de Laravel.
 *
 * Le SDK reste utilisable hors d'une application Laravel : si le conteneur n'est
 * pas démarré (ou que les services `events` / `log` ne sont pas liés), chaque
 * appel devient un no-op silencieux. Aucune dépendance dure n'est introduite.
 */
final class Telemetry
{
    /**
     * Diffuse un événement via le dispatcher Laravel s'il est disponible.
     */
    public static function event(object $event): void
    {
        $events = self::resolve('events');
        if ($events !== null) {
            try {
                $events->dispatch($event);
            } catch (Throwable) {
                // La télémétrie ne doit jamais faire échouer une impression.
            }
        }
    }

    /**
     * Écrit un message de log via le logger Laravel s'il est disponible.
     *
     * @param  array<string, mixed>  $context
     */
    public static function log(string $level, string $message, array $context = []): void
    {
        $logger = self::resolve('log');
        if ($logger !== null) {
            try {
                $logger->log($level, '[laraprint] '.$message, $context);
            } catch (Throwable) {
                // Idem : un échec de log ne doit pas interrompre l'impression.
            }
        }
    }

    /**
     * Récupère un service du conteneur uniquement s'il est réellement lié.
     */
    private static function resolve(string $abstract): mixed
    {
        if (! class_exists(Container::class)) {
            return null;
        }

        $container = Container::getInstance();
        if (! $container->bound($abstract)) {
            return null;
        }

        try {
            return $container->make($abstract);
        } catch (Throwable) {
            return null;
        }
    }
}
