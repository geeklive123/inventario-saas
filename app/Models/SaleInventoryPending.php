<?php

namespace App\Models;

use App\Enums\SaleInventoryPendingStatus;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\SaleInventoryPendingFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property SaleInventoryPendingStatus $status
 * @property numeric-string $required_quantity
 * @property numeric-string $regularized_quantity
 * @property int|null $stock_movement_id
 * @property int|null $regularized_by_membership_id
 * @property Carbon|null $regularized_at
 */
#[Fillable([
    'company_id', 'sale_id', 'sale_item_id', 'warehouse_id', 'original_component_product_id',
    'stock_movement_id', 'original_component_name', 'original_component_sku', 'unit_symbol',
    'required_quantity', 'regularized_quantity', 'status', 'regularized_at',
    'regularized_by_membership_id',
])]
class SaleInventoryPending extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<SaleInventoryPendingFactory> */
    use HasFactory;

    protected $attributes = [
        'regularized_quantity' => 0,
        'status' => SaleInventoryPendingStatus::Pending->value,
    ];

    protected static function booted(): void
    {
        static::updating(function (SaleInventoryPending $pending): void {
            $allowed = [
                'stock_movement_id', 'regularized_quantity', 'status', 'regularized_at',
                'regularized_by_membership_id', 'updated_at',
            ];
            $isCompletion = $pending->getRawOriginal('status') === SaleInventoryPendingStatus::Pending->value
                && $pending->status === SaleInventoryPendingStatus::Completed
                && bccomp($pending->regularized_quantity, $pending->required_quantity, 6) === 0
                && $pending->stock_movement_id !== null
                && $pending->regularized_at !== null
                && $pending->regularized_by_membership_id !== null;
            $isCancellation = $pending->getRawOriginal('status') === SaleInventoryPendingStatus::Pending->value
                && $pending->status === SaleInventoryPendingStatus::Cancelled
                && array_diff(array_keys($pending->getDirty()), ['status', 'updated_at']) === [];

            if (array_diff(array_keys($pending->getDirty()), $allowed) !== [] || (! $isCompletion && ! $isCancellation)) {
                throw new DomainException('A sale inventory pending record can only be completed once.');
            }
        });
        static::deleting(fn () => throw new DomainException('Sale inventory pending records cannot be deleted.'));
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** @return BelongsTo<SaleItem, $this> */
    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function originalComponent(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'original_component_product_id');
    }

    /** @return BelongsTo<StockMovement, $this> */
    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }

    /** @return BelongsTo<Membership, $this> */
    public function regularizedBy(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'regularized_by_membership_id');
    }

    /** @return HasMany<SaleInventoryRegularizationLine, $this> */
    public function regularizationLines(): HasMany
    {
        return $this->hasMany(SaleInventoryRegularizationLine::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'required_quantity' => 'decimal:6',
            'regularized_quantity' => 'decimal:6',
            'status' => SaleInventoryPendingStatus::class,
            'regularized_at' => 'datetime',
        ];
    }
}
