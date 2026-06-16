<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Connector;

use Neocode\Laraprint\Support\PrinterType;
use RuntimeException;

/**
 * DTO de configuration de connexion à une imprimante.
 * Utilisable sans modèle Eloquent.
 */
final class PrinterConnectionConfig
{
    public function __construct(
        public readonly string $connectionType,
        public readonly array $settings = [],
        public readonly ?string $name = null,
        public readonly ?PrinterType $printerType = null,
        public readonly bool $isActive = true,
    ) {
        if ($connectionType === '') {
            throw new RuntimeException('Le type de connexion ne peut pas être vide.');
        }
    }

    public static function fromArray(array $data): self
    {
        $printerType = null;
        if (array_key_exists('printer_type', $data) && $data['printer_type'] !== null) {
            $v = $data['printer_type'];
            if ($v instanceof PrinterType) {
                $printerType = $v;
            } elseif (is_string($v)) {
                $printerType = PrinterType::tryFrom($v);
                if ($printerType === null) {
                    throw new RuntimeException(sprintf('printer_type invalide : %s', $v));
                }
            } else {
                throw new RuntimeException('printer_type doit être une chaîne ou PrinterType.');
            }
        }

        return new self(
            connectionType: $data['connection_type'] ?? $data['type'] ?? 'network',
            settings: $data['settings'] ?? $data,
            name: $data['name'] ?? null,
            printerType: $printerType,
            isActive: (bool) ($data['is_active'] ?? true),
        );
    }

    public function toArray(): array
    {
        return [
            'connection_type' => $this->connectionType,
            'settings' => $this->settings,
            'name' => $this->name,
            'printer_type' => $this->printerType?->value,
            'is_active' => $this->isActive,
        ];
    }
}
