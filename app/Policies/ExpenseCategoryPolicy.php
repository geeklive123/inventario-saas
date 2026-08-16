<?php

namespace App\Policies;

use App\Models\ExpenseCategory;
use App\Models\User;
use App\Support\Authorization\CompanyAccess;

class ExpenseCategoryPolicy
{
    public function __construct(private CompanyAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allowsCurrent($user, 'finance.expenses.view', mutation: false);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, ExpenseCategory $expenseCategory): bool
    {
        return $this->access->allows($user, $expenseCategory->company, 'finance.expenses.view', mutation: false);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->access->allowsCurrent($user, 'finance.expense_categories.manage');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, ExpenseCategory $expenseCategory): bool
    {
        return $this->access->allows($user, $expenseCategory->company, 'finance.expense_categories.manage');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function deactivate(User $user, ExpenseCategory $expenseCategory): bool
    {
        return $this->update($user, $expenseCategory);
    }
}
