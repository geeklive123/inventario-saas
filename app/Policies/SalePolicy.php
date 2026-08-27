<?php

namespace App\Policies;

use App\Enums\ModuleCode;
use App\Enums\SaleOrderStatus;
use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Models\User;
use App\Support\Authorization\CompanyAccess;

class SalePolicy
{
    public function __construct(private CompanyAccess $access) {}

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->access->allowsCurrent($user, 'sales.view', mutation: false);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Sale $sale): bool
    {
        return $this->access->allows($user, $sale->company, 'sales.view', mutation: false);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->access->allowsCurrent($user, 'sales.create')
            && $this->access->moduleEnabledCurrent(ModuleCode::Inventory);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Sale $sale): bool
    {
        return false;
    }

    public function void(User $user, Sale $sale): bool
    {
        return $this->access->allows($user, $sale->company, 'sales.void')
            && $this->access->moduleEnabled($sale->company, ModuleCode::Inventory)
            && $sale->status === SaleStatus::Confirmed
            && bccomp($sale->paid_total_base, '0', 4) === 0;
    }

    public function viewBalance(User $user, Sale $sale): bool
    {
        return $this->access->allows($user, $sale->company, 'sales.balances.view', mutation: false);
    }

    public function registerPayment(User $user, Sale $sale): bool
    {
        return $this->access->allows($user, $sale->company, 'sales.payments.create')
            && $sale->status === SaleStatus::Confirmed
            && $sale->order_status !== SaleOrderStatus::Cancelled
            && bccomp($sale->balance_due_base, '0', 4) === 1;
    }

    public function updateOrderStatus(User $user, Sale $sale): bool
    {
        return $this->access->allows($user, $sale->company, 'sales.create')
            && $sale->status === SaleStatus::Confirmed;
    }

    public function updateDelivery(User $user, Sale $sale): bool
    {
        return $this->updateOrderStatus($user, $sale);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Sale $sale): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Sale $sale): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Sale $sale): bool
    {
        return false;
    }
}
