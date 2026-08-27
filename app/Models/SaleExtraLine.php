<?php

namespace App\Models;

use App\Enums\SaleExtraType;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\ImmutableModel;
use Database\Factories\SaleExtraLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id', 'sale_id', 'sale_extra_id', 'extra_name', 'extra_type',
    'quantity', 'unit_price_base', 'subtotal_base',
])]
class SaleExtraLine extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<SaleExtraLineFactory> */
    use HasFactory;

    use ImmutableModel;

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** @return BelongsTo<SaleExtra, $this> */
    public function extra(): BelongsTo
    {
        return $this->belongsTo(SaleExtra::class, 'sale_extra_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'extra_type' => SaleExtraType::class,
            'quantity' => 'decimal:6',
            'unit_price_base' => 'decimal:4',
            'subtotal_base' => 'decimal:4',
        ];
    }
}
