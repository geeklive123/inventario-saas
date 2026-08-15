<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use App\Support\Authorization\CompanyAccess;

class ProductPolicy
{
    public function __construct(private CompanyAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allowsCurrent($user, 'catalog.view', mutation: false);
    }

    public function view(User $user, Product $product): bool
    {
        return $this->access->allows($user, $product->company, 'catalog.view', mutation: false);
    }

    public function create(User $user): bool
    {
        return $this->access->allowsCurrent($user, 'catalog.create');
    }

    public function update(User $user, Product $product): bool
    {
        return $this->access->allows($user, $product->company, 'catalog.update');
    }

    public function deactivate(User $user, Product $product): bool
    {
        return $this->access->allows($user, $product->company, 'catalog.deactivate');
    }

    public function delete(User $user, Product $product): bool
    {
        return false;
    }

    public function restore(User $user, Product $product): bool
    {
        return false;
    }

    public function forceDelete(User $user, Product $product): bool
    {
        return false;
    }
}
