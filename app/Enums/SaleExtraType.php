<?php

namespace App\Enums;

enum SaleExtraType: string
{
    case Service = 'service';
    case Product = 'product';

    public function label(): string
    {
        return match ($this) {
            self::Service => 'Servicio',
            self::Product => 'Producto',
        };
    }
}
