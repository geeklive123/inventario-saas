<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\ImmutableModel;
use Database\Factories\SaleItemComponentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $customization_quantity
 * @property numeric-string|null $original_quantity_required
 * @property numeric-string|null $quantity_required
 * @property numeric-string $customization_quantity_consumed
 * @property numeric-string $customization_unit_price_base
 * @property numeric-string $customization_total_price_base
 * @property string|null $customization_note
 */
#[Fillable([
    'company_id', 'sale_item_id', 'source_key', 'original_product_id', 'original_product_name',
    'original_product_sku', 'original_unit_symbol', 'product_id', 'product_name', 'product_sku',
    'unit_symbol', 'recipe_quantity', 'customization_quantity', 'waste_percentage', 'quantity_consumed',
    'original_quantity_required', 'quantity_required',
    'customization_quantity_consumed', 'customization_unit_price_base', 'customization_total_price_base',
    'customization_note', 'unit_cost_base', 'total_cost_base', 'customization_total_cost_base',
])]
class SaleItemComponent extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<SaleItemComponentFactory> */
    use HasFactory;

    use ImmutableModel;

    /** @return BelongsTo<SaleItem, $this> */
    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function originalProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'original_product_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'recipe_quantity' => 'decimal:6', 'customization_quantity' => 'decimal:6',
            'waste_percentage' => 'decimal:6', 'original_quantity_required' => 'decimal:6',
            'quantity_required' => 'decimal:6', 'quantity_consumed' => 'decimal:6',
            'customization_quantity_consumed' => 'decimal:6', 'customization_unit_price_base' => 'decimal:4',
            'customization_total_price_base' => 'decimal:4', 'unit_cost_base' => 'decimal:4',
            'total_cost_base' => 'decimal:4', 'customization_total_cost_base' => 'decimal:4',
        ];
    }
}
