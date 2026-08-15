<?php

namespace App\Support;

use DomainException;

class Decimal
{
    /** @return numeric-string */
    public static function normalize(int|float|string $value, int $scale): string
    {
        if (! is_numeric($value)) {
            throw new DomainException('A valid decimal value is required.');
        }

        return bcadd((string) $value, '0', $scale);
    }
}
