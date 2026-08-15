<?php

namespace App\Enums;

enum SaleStatus: string
{
    case Confirmed = 'confirmed';
    case Voided = 'voided';
}
