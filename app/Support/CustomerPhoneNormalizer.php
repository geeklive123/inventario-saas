<?php

namespace App\Support;

use Illuminate\Support\Str;

final class CustomerPhoneNormalizer
{
    public function normalize(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $digits = Str::of($value)->replaceMatches('/\D+/', '')->toString();

        if (Str::length($digits) === 13 && Str::startsWith($digits, '00591')) {
            $digits = Str::substr($digits, 5);
        } elseif (Str::length($digits) === 11 && Str::startsWith($digits, '591')) {
            $digits = Str::substr($digits, 3);
        }

        return preg_match('/^[67]\d{7}$/', $digits) === 1 ? $digits : null;
    }
}
