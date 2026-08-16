<?php

namespace App\Actions\Expenses;

use App\Models\ExpenseCategory;
use App\Models\Membership;
use App\Support\Authorization\CompanyAccess;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SaveExpenseCategory
{
    public function __construct(private CompanyAccess $access) {}

    public function handle(Membership $actor, string $name, ?string $description = null, ?ExpenseCategory $category = null): ExpenseCategory
    {
        if (! $this->access->allows($actor->user, $actor->company, 'finance.expense_categories.manage')) {
            throw new DomainException('La membership no puede administrar categorías de gastos.');
        }

        if ($category !== null && $category->company_id !== $actor->company_id) {
            throw new DomainException('La categoría no pertenece a la empresa activa.');
        }

        $normalizedName = trim($name);

        if ($normalizedName === '') {
            throw new DomainException('El nombre de la categoría es obligatorio.');
        }

        return DB::transaction(function () use ($actor, $category, $normalizedName, $description): ExpenseCategory {
            $expenseCategory = $category ?? new ExpenseCategory(['company_id' => $actor->company_id]);
            $expenseCategory->name = $normalizedName;
            $expenseCategory->code = $category === null
                ? $this->uniqueCode($actor->company_id, $normalizedName)
                : $category->code;
            $expenseCategory->description = filled($description) ? trim((string) $description) : null;
            $expenseCategory->is_active = true;
            $expenseCategory->save();

            return $expenseCategory;
        });
    }

    private function uniqueCode(int $companyId, string $name): string
    {
        $base = Str::slug($name) ?: 'categoria';
        $code = Str::limit($base, 45, '');
        $suffix = 1;

        while (ExpenseCategory::query()->withoutGlobalScope('company')->where('company_id', $companyId)->where('code', $code)->exists()) {
            $code = Str::limit($base, 42, '').'-'.$suffix++;
        }

        return $code;
    }
}
