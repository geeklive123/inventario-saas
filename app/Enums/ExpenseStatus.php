<?php

namespace App\Enums;

enum ExpenseStatus: string
{
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
}
