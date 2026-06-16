<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Printer extends Model
{
    use HasFactory;

    protected $table = 'printers';

    protected $fillable = [
        'workstation_id',
        'name',
        'connection_type',
        'printer_type',
        'model',
        'is_default',
        'is_active',
        'settings',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'settings' => 'array',
    ];

    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
    }

    public function credentials(): HasOne
    {
        return $this->hasOne(PrinterCredential::class);
    }

    /**
     * Configuration de connexion prête à être consommée par le SDK
     * (DirectPrinter, ThermalPrinter, ConnectorFactory, SpooledFilePrint).
     *
     * @return array{connection_type: string, settings: array<string, mixed>, name: string, printer_type: ?string, is_active: bool, credentials?: array<string, mixed>}
     */
    public function getConnectionConfig(): array
    {
        $config = [
            'connection_type' => $this->connection_type,
            'settings' => $this->settings ?? [],
            'name' => $this->name,
            'printer_type' => $this->printer_type,
            'is_active' => (bool) $this->is_active,
        ];

        if ($this->credentials) {
            $config['credentials'] = [
                'username' => $this->credentials->username,
                'password' => $this->credentials->password,
                'domain' => $this->credentials->domain,
            ];
        }

        return $config;
    }

    /**
     * Définit cette imprimante comme imprimante par défaut, en retirant
     * le statut « par défaut » des autres imprimantes du même périmètre
     * (même poste, ou périmètre global si aucun poste n'est associé).
     */
    public function makeDefault(): self
    {
        $this->getConnection()->transaction(function () {
            static::query()
                ->where('id', '!=', $this->getKey())
                ->where(function (Builder $query) {
                    $query->where('workstation_id', $this->workstation_id);
                    if ($this->workstation_id === null) {
                        $query->orWhereNull('workstation_id');
                    }
                })
                ->where('is_default', true)
                ->update(['is_default' => false]);

            $this->forceFill(['is_default' => true])->save();
        });

        return $this;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeByType(Builder $query, string $type): Builder
    {
        return $query->where('connection_type', $type);
    }

    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    public function scopeForWorkstation(Builder $query, ?int $workstationId): Builder
    {
        return $query->where('workstation_id', $workstationId);
    }
}
