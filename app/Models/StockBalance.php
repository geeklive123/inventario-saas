<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\StockBalanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $quantity
 * @property numeric-string $inventory_value_base
 * @property numeric-string $average_unit_cost_base
 * @property numeric-string|null $last_inbound_unit_cost_base
 */
#[Fillable([
    'company_id', 'warehouse_id', 'product_id', 'quantity', 'inventory_value_base',
    'average_unit_cost_base', 'last_inbound_unit_cost_base',
])]
class StockBalance extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<StockBalanceFactory> */
    use HasFactory;

    protected $attributes = [
        'quantity' => 0,
        'inventory_value_base' => 0,
        'average_unit_cost_base' => 0,
    ];

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
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
            'inventory_value_base' => 'decimal:4',
            'average_unit_cost_base' => 'decimal:4',
            'last_inbound_unit_cost_base' => 'decimal:4',
        ];
    }
}
