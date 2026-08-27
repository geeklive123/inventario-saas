<?php

namespace App\Policies;

use App\Models\SaleExtra;
use App\Models\User;
use App\Support\Authorization\CompanyAccess;

class SaleExtraPolicy
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
    public function view(User $user, SaleExtra $saleExtra): bool
    {
        return $this->access->allows($user, $saleExtra->company, 'sales.view', mutation: false);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->access->allowsCurrent($user, 'sales.extras.manage');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, SaleExtra $saleExtra): bool
    {
        return $this->access->allows($user, $saleExtra->company, 'sales.extras.manage');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, SaleExtra $saleExtra): bool
    {
        return false;
    }

    public function deactivate(User $user, SaleExtra $saleExtra): bool
    {
        return $this->update($user, $saleExtra);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, SaleExtra $saleExtra): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, SaleExtra $saleExtra): bool
    {
        return false;
    }
}
