<?php

namespace App\Actions\Memberships;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\User;
use App\Support\Authorization\CompanyAccess;
use DomainException;
use Illuminate\Support\Facades\DB;

class AddMembership
{
    public function __construct(private CompanyAccess $access) {}

    public function handle(
        Membership $actor,
        User $user,
        MembershipStatus $status = MembershipStatus::Invited,
    ): Membership {
        if (! $this->access->allows($actor->user, $actor->company, 'core.memberships.create')) {
            throw new DomainException('The membership actor is not authorized.');
        }

        return DB::transaction(fn (): Membership => Membership::query()->create([
            'company_id' => $actor->company_id,
            'user_id' => $user->getKey(),
            'status' => $status,
            'is_owner' => false,
            'invited_by_membership_id' => $actor->getKey(),
            'invited_at' => now(),
            'joined_at' => $status === MembershipStatus::Active ? now() : null,
        ]), attempts: 3);
    }
}
