<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Support;

/**
 * État d'une imprimante ESC/POS, décodé depuis les réponses « temps réel » (DLE EOT n).
 *
 * Best-effort : la signification exacte des bits dépend du modèle (décodage type Epson).
 * Une valeur `null` signifie « inconnu » (pas de réponse de l'imprimante).
 */
final class PrinterStatus
{
    /**
     * @param  array{s1: ?int, s2: ?int, s3: ?int, s4: ?int}  $raw
     */
    public function __construct(
        public readonly ?bool $online,
        public readonly ?bool $coverOpen,
        public readonly ?bool $paperOut,
        public readonly ?bool $paperNearEnd,
        public readonly ?bool $drawerOpen,
        public readonly array $raw,
    ) {}

    /**
     * Décode les octets de statut temps réel (DLE EOT 1..4).
     */
    public static function decode(?int $s1, ?int $s2, ?int $s3, ?int $s4): self
    {
        return new self(
            online: $s1 === null ? null : ! (bool) ($s1 & 0x08),
            coverOpen: $s2 === null ? null : (bool) ($s2 & 0x04),
            paperOut: $s4 === null ? null : ($s4 & 0x60) === 0x60,
            paperNearEnd: $s4 === null ? null : ($s4 & 0x0C) === 0x0C,
            drawerOpen: $s1 === null ? null : ! (bool) ($s1 & 0x04),
            raw: ['s1' => $s1, 's2' => $s2, 's3' => $s3, 's4' => $s4],
        );
    }

    /**
     * Vrai si l'imprimante est en ligne, sans erreur connue (papier présent, capot fermé).
     */
    public function isReady(): bool
    {
        return $this->online === true
            && $this->paperOut !== true
            && $this->coverOpen !== true;
    }

    /**
     * @return array{online: ?bool, cover_open: ?bool, paper_out: ?bool, paper_near_end: ?bool, drawer_open: ?bool, raw: array{s1: ?int, s2: ?int, s3: ?int, s4: ?int}}
     */
    public function toArray(): array
    {
        return [
            'online' => $this->online,
            'cover_open' => $this->coverOpen,
            'paper_out' => $this->paperOut,
            'paper_near_end' => $this->paperNearEnd,
            'drawer_open' => $this->drawerOpen,
            'raw' => $this->raw,
        ];
    }
}
