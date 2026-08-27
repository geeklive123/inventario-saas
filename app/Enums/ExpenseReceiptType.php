<?php

namespace App\Enums;

enum ExpenseReceiptType: string
{
    case WithInvoice = 'with_invoice';
    case WithoutInvoice = 'without_invoice';

    public function label(): string
    {
        return match ($this) {
            self::WithInvoice => 'Con factura',
            self::WithoutInvoice => 'Sin factura',
        };
    }
}
