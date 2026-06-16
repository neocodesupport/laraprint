<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Neocode\Laraprint\Jobs\PrintJob;

/**
 * Trace d'un job d'impression (file d'attente) : statut, tentatives, erreur.
 *
 * Optionnel : nécessite la migration `print_jobs`. {@see PrintJob}
 * met à jour ces enregistrements lorsqu'ils sont présents.
 *
 * @property int $id
 * @property string $uuid
 * @property ?int $printer_id
 * @property string $kind
 * @property string $status
 * @property int $attempts
 * @property ?string $error
 */
class PrintJobRecord extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_PRINTING = 'printing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $table = 'print_jobs';

    protected $fillable = [
        'uuid',
        'printer_id',
        'kind',
        'status',
        'attempts',
        'error',
        'context',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'context' => 'array',
        'attempts' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    public function markPrinting(): void
    {
        $this->forceFill([
            'status' => self::STATUS_PRINTING,
            'attempts' => $this->attempts + 1,
            'started_at' => $this->freshTimestamp(),
            'error' => null,
        ])->save();
    }

    public function markCompleted(): void
    {
        $this->forceFill([
            'status' => self::STATUS_COMPLETED,
            'finished_at' => $this->freshTimestamp(),
        ])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'error' => $error,
            'finished_at' => $this->freshTimestamp(),
        ])->save();
    }

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }
}
