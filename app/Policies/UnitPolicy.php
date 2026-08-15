<?php

namespace App\Policies;

use App\Models\Unit;
use App\Models\User;
use App\Support\Authorization\CompanyAccess;

class UnitPolicy
{
    public function __construct(private CompanyAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allowsCurrent($user, 'catalog.view', mutation: false);
    }

    public function view(User $user, Unit $unit): bool
    {
        return $this->access->allows($user, $unit->company, 'catalog.view', mutation: false);
    }

    public function create(User $user): bool
    {
        return $this->access->allowsCurrent($user, 'catalog.create');
    }

    public function update(User $user, Unit $unit): bool
    {
        return $this->access->allows($user, $unit->company, 'catalog.update');
    }

    public function deactivate(User $user, Unit $unit): bool
    {
        return $this->access->allows($user, $unit->company, 'catalog.deactivate');
    }

    public function delete(User $user, Unit $unit): bool
    {
        return false;
    }
}
