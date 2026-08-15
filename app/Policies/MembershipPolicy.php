<?php

namespace App\Policies;

use App\Models\Membership;
use App\Models\User;
use App\Support\Authorization\CompanyAccess;

class MembershipPolicy
{
    public function __construct(private CompanyAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allowsCurrent($user, 'core.users.view', mutation: false);
    }

    public function create(User $user): bool
    {
        return $this->access->allowsCurrent($user, 'core.users.create');
    }

    public function update(User $user, Membership $membership): bool
    {
        return ! $membership->is_owner
            && $this->access->allows($user, $membership->company, 'core.users.update');
    }

    public function assignRole(User $user, Membership $membership): bool
    {
        return ! $membership->is_owner
            && $this->access->allows($user, $membership->company, 'core.users.assign_roles');
    }

    public function changeStatus(User $user, Membership $membership): bool
    {
        return ! $membership->is_owner
            && $this->access->allows($user, $membership->company, 'core.users.suspend');
    }

    public function delete(User $user, Membership $membership): bool
    {
        return false;
    }
}
