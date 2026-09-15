<?php

namespace App\Enums;

enum SaleInventoryStatus: string
{
    case Complete = 'complete';
    case PendingRegularization = 'pending_regularization';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Complete => 'Completo',
            self::PendingRegularization => 'Pendiente de regularizar',
            self::Cancelled => 'Cancelado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Complete => 'green',
            self::PendingRegularization => 'amber',
            self::Cancelled => 'zinc',
        };
    }
}
