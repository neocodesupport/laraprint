<?php

declare(strict_types=1);

namespace Neocode\Laraprint\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class PrinterCredential extends Model
{
    use HasFactory;

    protected $table = 'printer_credentials';

    protected $fillable = [
        'printer_id',
        'username',
        'password',
        'domain',
    ];

    protected $hidden = [
        'password',
    ];

    public function printer(): BelongsTo
    {
        return $this->belongsTo(Printer::class);
    }

    public function getPasswordAttribute($value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function setPasswordAttribute($value): void
    {
        if ($value !== null && $value !== '') {
            $this->attributes['password'] = Crypt::encryptString($value);
        } else {
            $this->attributes['password'] = null;
        }
    }
}
