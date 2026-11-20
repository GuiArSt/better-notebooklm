<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Append-only: a usage event is never changed or deleted. */
class UsageEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['units' => 'array', 'cost_micro_usd' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('usage_events is append-only'));
        static::deleting(fn () => throw new LogicException('usage_events is append-only'));
    }
}
