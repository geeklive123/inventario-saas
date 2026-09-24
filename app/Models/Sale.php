<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Enums\SaleInventoryStatus;
use App\Enums\SaleOrderStatus;
use App\Enums\SaleStatus;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\SaleFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property SaleStatus $status
 * @property PaymentStatus $payment_status
 * @property SaleOrderStatus $order_status
 * @property numeric-string $subtotal_base
 * @property numeric-string $total_base
 * @property numeric-string $paid_total_base
 * @property numeric-string $balance_due_base
 * @property numeric-string|null $total_cost_base
 * @property numeric-string|null $gross_margin_base
 * @property SaleInventoryStatus $inventory_status
 * @property int $confirmed_by_membership_id
 * @property Carbon $occurred_at
 * @property Carbon|null $delivery_at
 */
#[Fillable([
    'company_id', 'sequence_number', 'number', 'branch_id', 'warehouse_id', 'customer_id',
    'customer_name', 'branch_name', 'warehouse_name', 'status', 'subtotal_base', 'total_base',
    'order_status', 'payment_status', 'inventory_status', 'extras_total_base', 'paid_total_base', 'balance_due_base',
    'total_cost_base', 'gross_margin_base', 'confirmed_by_membership_id',
    'voided_by_membership_id', 'occurred_at', 'delivery_at', 'delivery_updated_by_membership_id',
    'voided_at', 'void_reason',
])]
class Sale extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<SaleFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => SaleStatus::Confirmed->value,
        'order_status' => SaleOrderStatus::Reserved->value,
        'payment_status' => PaymentStatus::Pending->value,
        'inventory_status' => SaleInventoryStatus::Complete->value,
        'extras_total_base' => 0,
        'paid_total_base' => 0,
        'balance_due_base' => 0,
    ];

    protected static function booted(): void
    {
        static::updating(function (Sale $sale): void {
            $allowed = [
                'status', 'order_status', 'payment_status', 'paid_total_base', 'balance_due_base',
                'inventory_status', 'total_cost_base', 'gross_margin_base',
                'delivery_at', 'delivery_updated_by_membership_id', 'voided_by_membership_id',
                'voided_at', 'void_reason', 'updated_at',
            ];

            $dirty = array_keys($sale->getDirty());
            $inventoryFields = ['inventory_status', 'total_cost_base', 'gross_margin_base'];
            $hasInventoryMutation = array_intersect($dirty, $inventoryFields) !== [];
            $isValidVoid = $sale->getRawOriginal('status') === SaleStatus::Confirmed->value
                && $sale->status === SaleStatus::Voided
                && $sale->order_status === SaleOrderStatus::Cancelled
                && bccomp($sale->paid_total_base, '0', 4) === 0
                && $sale->voided_by_membership_id !== null
                && $sale->voided_at !== null
                && trim((string) $sale->void_reason) !== '';
            $isMutableStateOnly = $sale->getRawOriginal('status') === SaleStatus::Confirmed->value
                && $sale->status === SaleStatus::Confirmed
                && ! $hasInventoryMutation
                && $sale->order_status !== SaleOrderStatus::Cancelled
                && ! in_array('voided_by_membership_id', $dirty, true)
                && ! in_array('voided_at', $dirty, true)
                && ! in_array('void_reason', $dirty, true);
            $isValidInventoryCompletion = $sale->getRawOriginal('status') === SaleStatus::Confirmed->value
                && $sale->status === SaleStatus::Confirmed
                && $sale->getRawOriginal('inventory_status') === SaleInventoryStatus::PendingRegularization->value
                && $sale->inventory_status === SaleInventoryStatus::Complete
                && $sale->getRawOriginal('total_cost_base') === null
                && $sale->getRawOriginal('gross_margin_base') === null
                && $sale->total_cost_base !== null
                && $sale->gross_margin_base !== null
                && array_diff($dirty, [...$inventoryFields, 'updated_at']) === [];

            if (array_diff($dirty, $allowed) !== [] || (! $isValidVoid && ! $isMutableStateOnly && ! $isValidInventoryCompletion)) {
                throw new DomainException('Confirmed sale records are immutable except for audited voiding.');
            }

            $expectedBalance = bcsub($sale->total_base, $sale->paid_total_base, 4);
            $expectedPaymentStatus = bccomp($sale->paid_total_base, '0', 4) === 0
                ? PaymentStatus::Pending
                : (bccomp($expectedBalance, '0', 4) === 0 ? PaymentStatus::Paid : PaymentStatus::Partial);

            if (bccomp($sale->paid_total_base, '0', 4) < 0
                || bccomp($expectedBalance, '0', 4) < 0
                || bccomp($sale->balance_due_base, $expectedBalance, 4) !== 0
                || $sale->payment_status !== $expectedPaymentStatus) {
                throw new DomainException('Sale payment totals are inconsistent.');
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

    /** @return BelongsTo<Membership, $this> */
    public function deliveryUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'delivery_updated_by_membership_id');
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

    /** @return HasMany<SaleExtraLine, $this> */
    public function extraLines(): HasMany
    {
        return $this->hasMany(SaleExtraLine::class);
    }

    /** @return HasMany<SaleStockMovement, $this> */
    public function stockMovementLinks(): HasMany
    {
        return $this->hasMany(SaleStockMovement::class);
    }

    /** @return HasMany<SaleInventoryPending, $this> */
    public function inventoryPendings(): HasMany
    {
        return $this->hasMany(SaleInventoryPending::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sequence_number' => 'integer',
            'status' => SaleStatus::class,
            'order_status' => SaleOrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'inventory_status' => SaleInventoryStatus::class,
            'subtotal_base' => 'decimal:4',
            'extras_total_base' => 'decimal:4',
            'total_base' => 'decimal:4',
            'paid_total_base' => 'decimal:4',
            'balance_due_base' => 'decimal:4',
            'total_cost_base' => 'decimal:4',
            'gross_margin_base' => 'decimal:4',
            'occurred_at' => 'datetime',
            'delivery_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }
}
