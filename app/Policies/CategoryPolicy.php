<?php

namespace App\Policies;

use App\Models\Category;
use App\Models\User;
use App\Support\Authorization\CompanyAccess;

class CategoryPolicy
{
    public function __construct(private CompanyAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allowsCurrent($user, 'catalog.view', mutation: false);
    }

    public function view(User $user, Category $category): bool
    {
        return $this->access->allows($user, $category->company, 'catalog.view', mutation: false);
    }

    public function create(User $user): bool
    {
        return $this->access->allowsCurrent($user, 'catalog.create');
    }

    public function update(User $user, Category $category): bool
    {
        return $this->access->allows($user, $category->company, 'catalog.update');
    }

    public function deactivate(User $user, Category $category): bool
    {
        return $this->access->allows($user, $category->company, 'catalog.deactivate');
    }

    public function delete(User $user, Category $category): bool
    {
        return false;
    }
}
