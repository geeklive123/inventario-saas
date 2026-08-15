<?php

namespace App\Enums;

enum SaleStockMovementKind: string
{
    case Consumption = 'consumption';
    case Reversal = 'reversal';
}
