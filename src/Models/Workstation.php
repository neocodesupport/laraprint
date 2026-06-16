<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Workstation extends Model
{
    use HasFactory;

    protected $table = 'workstations';

    protected $fillable = [
        'name',
        'hostname',
        'ip_address',
        'location',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function printers(): HasMany
    {
        return $this->hasMany(Printer::class);
    }

    public function defaultPrinter(): HasOne
    {
        return $this->hasOne(Printer::class)->where('is_default', true);
    }

    public function getActivePrinters()
    {
        return $this->printers()->where('is_active', true)->get();
    }

    public function getDefaultPrinter(): ?Printer
    {
        return $this->defaultPrinter()->first();
    }

    public static function getByIp(string $ipAddress): ?self
    {
        return self::query()->byIp($ipAddress)->first();
    }

    public function scopeByIp($query, string $ip)
    {
        return $query->where('ip_address', $ip);
    }

    public function scopeByHostname($query, string $hostname)
    {
        return $query->where('hostname', $hostname);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
