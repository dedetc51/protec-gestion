<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Les événements d’audit sont immuables.'));
        static::deleting(fn () => throw new \LogicException('Les événements d’audit sont immuables.'));
    }
}
