<?php

namespace App\Enums;

enum ExpenseType: string
{
    case Direct = 'direct';
    case Indirect = 'indirect';

    public function label(): string
    {
        return match ($this) {
            self::Direct => 'Directo',
            self::Indirect => 'Indirecto',
        };
    }
}
