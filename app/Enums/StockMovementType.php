<?php

namespace App\Enums;

enum StockMovementType: string
{
    case Opening = 'opening';
    case AdjustmentIn = 'adjustment_in';
    case AdjustmentOut = 'adjustment_out';
    case Waste = 'waste';
    case Sale = 'sale';
    case Reversal = 'reversal';
}
