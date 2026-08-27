<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Partial = 'partial';
    case Paid = 'paid';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Partial => 'Parcial',
            self::Paid => 'Pagado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Partial => 'blue',
            self::Paid => 'green',
        };
    }
}
