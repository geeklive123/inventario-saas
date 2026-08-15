<?php

namespace App\Models;

use App\Enums\SaleStatus;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\SaleFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property SaleStatus $status
 * @property numeric-string $subtotal_base
 * @property numeric-string $total_base
 * @property numeric-string $total_cost_base
 * @property numeric-string $gross_margin_base
 */
#[Fillable([
    'company_id', 'sequence_number', 'number', 'branch_id', 'warehouse_id', 'customer_id',
    'customer_name', 'branch_name', 'warehouse_name', 'status', 'subtotal_base', 'total_base',
    'total_cost_base', 'gross_margin_base', 'confirmed_by_membership_id',
    'voided_by_membership_id', 'occurred_at', 'voided_at', 'void_reason',
])]
class Sale extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<SaleFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (Sale $sale): void {
            $allowed = ['status', 'voided_by_membership_id', 'voided_at', 'void_reason', 'updated_at'];

            if ($sale->getRawOriginal('status') !== SaleStatus::Confirmed->value
                || $sale->status !== SaleStatus::Voided
                || $sale->voided_by_membership_id === null
                || $sale->voided_at === null
                || trim((string) $sale->void_reason) === ''
                || array_diff(array_keys($sale->getDirty()), $allowed) !== []) {
                throw new DomainException('Confirmed sale records are immutable except for audited voiding.');
            }
        });
        static::deleting(fn () => throw new DomainException('Sales cannot be deleted.'));
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<Membership, $this> */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'confirmed_by_membership_id');
    }

    /** @return BelongsTo<Membership, $this> */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'voided_by_membership_id');
    }

    /** @return HasMany<SaleItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    /** @return HasMany<SalePayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    /** @return HasMany<SaleStockMovement, $this> */
    public function stockMovementLinks(): HasMany
    {
        return $this->hasMany(SaleStockMovement::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sequence_number' => 'integer',
            'status' => SaleStatus::class,
            'subtotal_base' => 'decimal:4',
            'total_base' => 'decimal:4',
            'total_cost_base' => 'decimal:4',
            'gross_margin_base' => 'decimal:4',
            'occurred_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
