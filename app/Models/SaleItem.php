<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\ImmutableModel;
use Database\Factories\SaleItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $product_name
 * @property numeric-string $quantity
 * @property numeric-string $subtotal_base
 * @property numeric-string $total_cost_base
 * @property numeric-string $gross_margin_base
 */
#[Fillable([
    'company_id', 'sale_id', 'product_id', 'product_recipe_id', 'recipe_version',
    'product_name', 'product_sku', 'unit_symbol', 'quantity', 'unit_price_base',
    'subtotal_base', 'unit_cost_base', 'total_cost_base', 'gross_margin_base',
])]
class SaleItem extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<SaleItemFactory> */
    use HasFactory;

    use ImmutableModel;

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<ProductRecipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(ProductRecipe::class, 'product_recipe_id');
    }

    /** @return HasMany<SaleItemComponent, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(SaleItemComponent::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'recipe_version' => 'integer', 'quantity' => 'decimal:6',
            'unit_price_base' => 'decimal:4', 'subtotal_base' => 'decimal:4',
            'unit_cost_base' => 'decimal:4', 'total_cost_base' => 'decimal:4',
            'gross_margin_base' => 'decimal:4',
        ];
    }
}
