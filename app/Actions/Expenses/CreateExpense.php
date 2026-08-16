<?php

namespace App\Actions\Expenses;

use App\Enums\ExpenseStatus;
use App\Enums\MembershipStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Membership;
use App\Models\PaymentMethod;
use App\Support\Authorization\CompanyAccess;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

class CreateExpense
{
    public function __construct(private CompanyAccess $access) {}

    public function handle(
        Membership $actor,
        ExpenseCategory $category,
        string|int|float $amountBase,
        string $concept,
        ?Branch $branch = null,
        ?PaymentMethod $paymentMethod = null,
        ?string $reference = null,
        ?string $notes = null,
        ?CarbonInterface $occurredAt = null,
    ): Expense {
        $this->authorize($actor);
        $this->validateReferences($actor, $category, $branch, $paymentMethod);

        $amount = number_format((float) $amountBase, 4, '.', '');

        if (bccomp($amount, '0', 4) !== 1 || trim($concept) === '') {
            throw new DomainException('El gasto requiere un concepto y un importe mayor a cero.');
        }

        return DB::transaction(function () use ($actor, $category, $amount, $concept, $branch, $paymentMethod, $reference, $notes, $occurredAt): Expense {
            $company = Company::query()->whereKey($actor->company_id)->lockForUpdate()->firstOrFail();
            $lockedActor = Membership::query()->withoutGlobalScope('company')
                ->whereKey($actor->getKey())->where('company_id', $company->getKey())->lockForUpdate()->firstOrFail();

            $this->authorize($lockedActor);
            $sequence = (int) Expense::query()->withoutGlobalScope('company')
                ->where('company_id', $company->getKey())->max('sequence_number') + 1;

            return Expense::query()->create([
                'company_id' => $company->getKey(),
                'sequence_number' => $sequence,
                'number' => 'G-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
                'branch_id' => $branch?->getKey(),
                'membership_id' => $lockedActor->getKey(),
                'expense_category_id' => $category->getKey(),
                'payment_method_id' => $paymentMethod?->getKey(),
                'category_name' => $category->name,
                'payment_method_name' => $paymentMethod?->name,
                'branch_name' => $branch?->name,
                'reference' => filled($reference) ? trim((string) $reference) : null,
                'concept' => trim($concept),
                'amount_base' => $amount,
                'occurred_at' => $occurredAt ?? now(),
                'notes' => filled($notes) ? trim((string) $notes) : null,
                'status' => ExpenseStatus::Confirmed,
            ]);
        }, attempts: 3);
    }

    private function authorize(Membership $actor): void
    {
        if ($actor->status !== MembershipStatus::Active
            || ! $this->access->allows($actor->user, $actor->company, 'finance.expenses.create')) {
            throw new DomainException('La membership no puede registrar gastos.');
        }
    }

    private function validateReferences(Membership $actor, ExpenseCategory $category, ?Branch $branch, ?PaymentMethod $paymentMethod): void
    {
        if ($category->company_id !== $actor->company_id || ! $category->is_active
            || ($branch !== null && ($branch->company_id !== $actor->company_id || ! $branch->is_active))
            || ($paymentMethod !== null && ($paymentMethod->company_id !== $actor->company_id || ! $paymentMethod->is_active))) {
            throw new DomainException('La categoría, sucursal y forma de pago deben estar activas y pertenecer a la empresa.');
        }
    }
}
