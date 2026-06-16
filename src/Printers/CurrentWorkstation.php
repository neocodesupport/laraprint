<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Printers;

use Illuminate\Container\Container;
use Neocode\Laraprint\Models\Workstation;
use Throwable;

/**
 * Identifie l'ordinateur (poste) courant et conserve les choix liés à la session.
 *
 * L'imprimante par défaut est ainsi liée à une **machine donnée** : chaque poste
 * (identifié par son nom d'hôte, ou un identifiant configuré/posé en session) peut
 * avoir sa propre imprimante par défaut. La sélection peut aussi être surchargée
 * le temps d'une **session** (utilisateur connecté sur ce poste).
 *
 * Hors application Laravel (pas de conteneur, pas de session), seul le nom d'hôte
 * système est utilisé ; les accès `session`/`config`/`request` deviennent des no-op.
 */
final class CurrentWorkstation
{
    public const SESSION_WORKSTATION_KEY = 'laraprint.workstation_id';

    public const SESSION_PRINTER_KEY = 'laraprint.printer_id';

    /**
     * @param  string|null  $forcedIdentifier  Force l'identifiant machine (sinon config puis hostname).
     */
    public function __construct(private ?string $forcedIdentifier = null) {}

    /**
     * Identifiant de la machine courante : valeur forcée, sinon configurée,
     * sinon le nom d'hôte système.
     */
    public function identifier(): string
    {
        if ($this->forcedIdentifier !== null && $this->forcedIdentifier !== '') {
            return $this->forcedIdentifier;
        }

        $configured = $this->config('laraprint.workstation.identifier');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $host = gethostname();

        return $host !== false && $host !== '' ? $host : 'default';
    }

    /**
     * Résout le poste courant : poste sélectionné en session, sinon poste
     * correspondant à l'identifiant machine (hostname puis name).
     */
    public function resolve(): ?Workstation
    {
        $sessionId = $this->session(self::SESSION_WORKSTATION_KEY);
        if ($sessionId !== null) {
            $workstation = Workstation::query()->find((int) $sessionId);
            if ($workstation !== null) {
                return $workstation;
            }
        }

        $identifier = $this->identifier();

        return Workstation::query()->where('hostname', $identifier)->first()
            ?? Workstation::query()->where('name', $identifier)->first();
    }

    /**
     * Résout le poste courant, en le créant si nécessaire.
     */
    public function resolveOrCreate(): Workstation
    {
        $existing = $this->resolve();
        if ($existing !== null) {
            return $existing;
        }

        $identifier = $this->identifier();

        return Workstation::query()->create([
            'name' => $identifier,
            'hostname' => $identifier,
            'ip_address' => $this->ipAddress() ?? $identifier,
            'is_active' => true,
        ]);
    }

    public function id(): ?int
    {
        return $this->resolve()?->getKey();
    }

    /**
     * Associe le poste courant à la session (pour les requêtes suivantes).
     */
    public function useWorkstation(Workstation|int $workstation): void
    {
        $id = $workstation instanceof Workstation ? $workstation->getKey() : $workstation;
        $this->putSession(self::SESSION_WORKSTATION_KEY, $id);
    }

    public function forgetWorkstation(): void
    {
        $this->forgetSession(self::SESSION_WORKSTATION_KEY);
    }

    /**
     * Imprimante choisie pour la session courante (surcharge du défaut machine).
     */
    public function selectedPrinterId(): ?int
    {
        $value = $this->session(self::SESSION_PRINTER_KEY);

        return $value !== null ? (int) $value : null;
    }

    public function selectPrinter(int $printerId): void
    {
        $this->putSession(self::SESSION_PRINTER_KEY, $printerId);
    }

    public function forgetSelectedPrinter(): void
    {
        $this->forgetSession(self::SESSION_PRINTER_KEY);
    }

    /* -----------------------------------------------------------------
     |  Accès Laravel optionnels (no-op hors application)
     | -----------------------------------------------------------------
     */

    private function ipAddress(): ?string
    {
        $request = $this->make('request');
        try {
            return $request?->ip();
        } catch (Throwable) {
            return null;
        }
    }

    private function config(string $key): mixed
    {
        $config = $this->make('config');
        try {
            return $config?->get($key);
        } catch (Throwable) {
            return null;
        }
    }

    private function session(string $key): mixed
    {
        $session = $this->make('session');
        try {
            return $session?->get($key);
        } catch (Throwable) {
            return null;
        }
    }

    private function putSession(string $key, mixed $value): void
    {
        $session = $this->make('session');
        try {
            $session?->put($key, $value);
        } catch (Throwable) {
            // session indisponible : no-op
        }
    }

    private function forgetSession(string $key): void
    {
        $session = $this->make('session');
        try {
            $session?->forget($key);
        } catch (Throwable) {
            // session indisponible : no-op
        }
    }

    private function make(string $abstract): mixed
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
