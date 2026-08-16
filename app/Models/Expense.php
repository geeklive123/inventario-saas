<?php

namespace App\Models;

use App\Enums\ExpenseStatus;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\ExpenseFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ExpenseStatus $status
 * @property numeric-string $amount_base
 */
#[Fillable([
    'company_id', 'sequence_number', 'number', 'branch_id', 'membership_id',
    'expense_category_id', 'payment_method_id', 'category_name', 'payment_method_name',
    'branch_name', 'reference', 'concept', 'amount_base', 'occurred_at', 'notes', 'status',
    'cancelled_by_membership_id', 'cancelled_at', 'cancellation_reason',
])]
class Expense extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<ExpenseFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (Expense $expense): void {
            $allowed = ['status', 'cancelled_by_membership_id', 'cancelled_at', 'cancellation_reason', 'updated_at'];

            if ($expense->getRawOriginal('status') !== ExpenseStatus::Confirmed->value
                || $expense->status !== ExpenseStatus::Cancelled
                || $expense->cancelled_by_membership_id === null
                || $expense->cancelled_at === null
                || trim((string) $expense->cancellation_reason) === ''
                || array_diff(array_keys($expense->getDirty()), $allowed) !== []) {
                throw new DomainException('Confirmed expenses are immutable except for audited cancellation.');
            }
        });
        static::deleting(fn () => throw new DomainException('Expenses cannot be deleted.'));
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

    /** @return BelongsTo<Membership, $this> */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'membership_id');
    }

    /** @return BelongsTo<ExpenseCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /** @return BelongsTo<Membership, $this> */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'cancelled_by_membership_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sequence_number' => 'integer',
            'amount_base' => 'decimal:4',
            'occurred_at' => 'datetime',
            'status' => ExpenseStatus::class,
            'cancelled_at' => 'datetime',
        ];
    }
}
