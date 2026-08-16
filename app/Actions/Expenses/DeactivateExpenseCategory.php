<?php

namespace App\Actions\Expenses;

use App\Models\ExpenseCategory;
use App\Models\Membership;
use App\Support\Authorization\CompanyAccess;
use DomainException;
use Illuminate\Support\Facades\DB;

class DeactivateExpenseCategory
{
    public function __construct(private CompanyAccess $access) {}

    public function handle(Membership $actor, ExpenseCategory $category): ExpenseCategory
    {
        if ($category->company_id !== $actor->company_id
            || ! $this->access->allows($actor->user, $actor->company, 'finance.expense_categories.manage')) {
            throw new DomainException('La membership no puede desactivar esta categoría.');
        }

        return DB::transaction(function () use ($category): ExpenseCategory {
            $category->is_active = false;
            $category->save();

            return $category;
        });
    }
}
