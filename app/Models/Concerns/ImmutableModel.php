<?php

namespace App\Models\Concerns;

use DomainException;

trait ImmutableModel
{
    protected static function bootImmutableModel(): void
    {
        static::updating(fn () => throw new DomainException('Historical records are immutable.'));
        static::deleting(fn () => throw new DomainException('Historical records cannot be deleted.'));
    }
}
