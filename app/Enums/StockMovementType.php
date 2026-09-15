<?php

namespace App\Enums;

enum StockMovementType: string
{
    case Opening = 'opening';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case Waste = 'waste';
    case Sale = 'sale';
    case SaleRegularization = 'sale_regularization';
    case Reversal = 'reversal';

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Stock inicial',
            self::AdjustmentIn => 'Entrada',
            self::AdjustmentOut => 'Salida',
            self::Waste => 'Merma',
            self::Sale => 'Venta',
            self::SaleRegularization => 'Regularización de venta',
            self::Reversal => 'Reversión',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Opening, self::AdjustmentIn => 'green',
            self::AdjustmentOut, self::Waste, self::Sale, self::SaleRegularization => 'red',
            self::Reversal => 'amber',
        };
    }
}
