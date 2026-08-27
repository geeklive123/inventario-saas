<?php

namespace App\Enums;

enum SaleOrderStatus: string
{
    case Reserved = 'reserved';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Reserved => 'Reservado',
            self::Preparing => 'En preparación',
            self::Ready => 'Listo',
            self::Delivered => 'Entregado',
            self::Cancelled => 'Cancelado',
        };
    }
}
