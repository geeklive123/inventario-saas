<?php

namespace App\Policies;

use App\Models\SaleInventoryPending;
use App\Models\User;
use App\Support\Authorization\CompanyAccess;
use App\Support\Tenancy\CurrentCompany;

class SaleInventoryPendingPolicy
{
    public function __construct(
        private CompanyAccess $access,
        private CurrentCompany $currentCompany,
    ) {}

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        if (! $this->access->allowsCurrent($user, 'inventory.regularize_sales')) {
            return false;
        }

        return $this->access->isOwnerOrAdministrator($user, $this->currentCompany->company());
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, SaleInventoryPending $saleInventoryPending): bool
    {
        return $this->access->isOwnerOrAdministrator($user, $saleInventoryPending->company)
            && $this->access->allows($user, $saleInventoryPending->company, 'inventory.regularize_sales');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, SaleInventoryPending $saleInventoryPending): bool
    {
        return $this->view($user, $saleInventoryPending);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, SaleInventoryPending $saleInventoryPending): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, SaleInventoryPending $saleInventoryPending): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, SaleInventoryPending $saleInventoryPending): bool
    {
        return false;
    }
}
