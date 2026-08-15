<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\ImmutableModel;
use Database\Factories\StockMovementLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $quantity
 * @property numeric-string $quantity_before
 * @property numeric-string $quantity_after
 * @property numeric-string $unit_cost_base
 * @property numeric-string $total_cost_base
 * @property numeric-string $inventory_value_before_base
 * @property numeric-string $inventory_value_after_base
 * @property numeric-string $average_unit_cost_before_base
 * @property numeric-string $average_unit_cost_after_base
 * @property numeric-string $cost_variance_base
 */
#[Fillable([
    'company_id', 'stock_movement_id', 'product_id', 'quantity', 'quantity_before',
    'quantity_after', 'unit_cost_base', 'total_cost_base', 'inventory_value_before_base',
    'inventory_value_after_base', 'average_unit_cost_before_base',
    'average_unit_cost_after_base', 'cost_variance_base',
])]
class StockMovementLine extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<StockMovementLineFactory> */
    use HasFactory;

    use ImmutableModel;

    public const UPDATED_AT = null;

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<StockMovement, $this> */
    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'quantity_before' => 'decimal:6',
            'quantity_after' => 'decimal:6',
            'unit_cost_base' => 'decimal:4',
            'total_cost_base' => 'decimal:4',
            'inventory_value_before_base' => 'decimal:4',
            'inventory_value_after_base' => 'decimal:4',
            'average_unit_cost_before_base' => 'decimal:4',
            'average_unit_cost_after_base' => 'decimal:4',
            'cost_variance_base' => 'decimal:4',
            'created_at' => 'datetime',
        ];
    }
}
