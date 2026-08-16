<?php

namespace App\Enums;

enum ModuleCode: string
{
    case Core = 'core';
    case Catalog = 'catalog';
    case Inventory = 'inventory';
    case Sales = 'sales';
    case Finance = 'finance';
    case Cash = 'cash';
}
