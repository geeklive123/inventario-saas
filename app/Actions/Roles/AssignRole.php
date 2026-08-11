<?php

namespace App\Actions\Roles;

use App\Models\Membership;
use App\Models\MembershipRole;
use App\Models\Role;
use App\Support\Authorization\CompanyAccess;
use DomainException;
use Illuminate\Support\Facades\DB;

class AssignRole
{
    public function __construct(private CompanyAccess $access) {}

    public function handle(Membership $actor, Membership $membership, Role $role): MembershipRole
    {
        if ($actor->company_id !== $membership->company_id || $role->company_id !== $membership->company_id) {
            throw new DomainException('The role and memberships must belong to the same company.');
        }

        if (! $this->access->allows($actor->user, $actor->company, 'core.roles.assign')) {
            throw new DomainException('The membership actor is not authorized.');
        }

        return DB::transaction(fn (): MembershipRole => MembershipRole::query()->firstOrCreate([
            'company_id' => $membership->company_id,
            'membership_id' => $membership->getKey(),
            'role_id' => $role->getKey(),
        ]), attempts: 3);
    }
}
