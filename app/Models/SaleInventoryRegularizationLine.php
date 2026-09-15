<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\ImmutableModel;
use Database\Factories\SaleInventoryRegularizationLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id', 'sale_inventory_pending_id', 'actual_product_id', 'actual_product_name',
    'actual_product_sku', 'unit_symbol', 'quantity', 'unit_cost_base', 'total_cost_base',
])]
class SaleInventoryRegularizationLine extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<SaleInventoryRegularizationLineFactory> */
    use HasFactory;

    use ImmutableModel;

    public const UPDATED_AT = null;

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<SaleInventoryPending, $this> */
    public function pending(): BelongsTo
    {
        return $this->belongsTo(SaleInventoryPending::class, 'sale_inventory_pending_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function actualProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'actual_product_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'unit_cost_base' => 'decimal:4',
            'total_cost_base' => 'decimal:4',
            'created_at' => 'datetime',
        ];
    }
}
