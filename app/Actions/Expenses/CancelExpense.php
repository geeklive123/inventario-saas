<?php

namespace App\Actions\Expenses;

use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\Membership;
use App\Support\Authorization\CompanyAccess;
use DomainException;
use Illuminate\Support\Facades\DB;

class CancelExpense
{
    public function __construct(private CompanyAccess $access) {}

    public function handle(Membership $actor, Expense $expense, string $reason): Expense
    {
        if ($expense->company_id !== $actor->company_id
            || ! $this->access->allows($actor->user, $actor->company, 'finance.expenses.cancel')) {
            throw new DomainException('La membership no puede anular este gasto.');
        }

        if (trim($reason) === '') {
            throw new DomainException('Debes indicar el motivo de anulación.');
        }

        return DB::transaction(function () use ($actor, $expense, $reason): Expense {
            $lockedExpense = Expense::query()->whereKey($expense->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedExpense->status !== ExpenseStatus::Confirmed) {
                throw new DomainException('El gasto ya fue anulado.');
            }

            $lockedExpense->status = ExpenseStatus::Cancelled;
            $lockedExpense->cancelled_by_membership_id = $actor->getKey();
            $lockedExpense->cancelled_at = now();
            $lockedExpense->cancellation_reason = trim($reason);
            $lockedExpense->save();

            return $lockedExpense;
        });
    }
}
