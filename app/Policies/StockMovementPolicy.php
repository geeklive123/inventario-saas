<?php

namespace App\Policies;

use App\Enums\StockMovementType;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Authorization\CompanyAccess;

class StockMovementPolicy
{
    public function __construct(private CompanyAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allowsCurrent($user, 'inventory.view', mutation: false);
    }

    public function view(User $user, StockMovement $movement): bool
    {
        return $this->access->allows($user, $movement->company, 'inventory.view', mutation: false);
    }

    public function reverse(User $user, StockMovement $movement): bool
    {
        return $movement->type !== StockMovementType::Sale
            && $this->access->allows($user, $movement->company, 'inventory.reverse');
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, StockMovement $movement): bool
    {
        return false;
    }

    public function delete(User $user, StockMovement $movement): bool
    {
        return false;
    }

    public function restore(User $user, StockMovement $movement): bool
    {
        return false;
    }

    public function forceDelete(User $user, StockMovement $movement): bool
    {
        return false;
    }
}
