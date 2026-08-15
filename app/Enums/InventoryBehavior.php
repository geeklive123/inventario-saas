<?php

namespace App\Enums;

enum InventoryBehavior: string
{
    case None = 'none';
    case Self = 'self';
    case Components = 'components';
}
