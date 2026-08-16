<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;
use App\Support\Authorization\CompanyAccess;

class ExpensePolicy
{
    public function __construct(private CompanyAccess $access) {}

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->access->allowsCurrent($user, 'finance.expenses.view', mutation: false);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Expense $expense): bool
    {
        return $this->access->allows($user, $expense->company, 'finance.expenses.view', mutation: false);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->access->allowsCurrent($user, 'finance.expenses.create');
    }

    public function update(User $user, Expense $expense): bool
    {
        return $this->access->allows($user, $expense->company, 'finance.expenses.update');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function cancel(User $user, Expense $expense): bool
    {
        return $this->access->allows($user, $expense->company, 'finance.expenses.cancel');
    }
}
