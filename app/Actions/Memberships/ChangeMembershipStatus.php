<?php

namespace App\Actions\Memberships;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Support\Authorization\CompanyAccess;
use DomainException;
use Illuminate\Support\Facades\DB;

class ChangeMembershipStatus
{
    public function __construct(private CompanyAccess $access) {}

    public function handle(
        Membership $actor,
        Membership $membership,
        MembershipStatus $status,
        string $permissionCode = 'core.memberships.suspend',
    ): Membership {
        if ($actor->company_id !== $membership->company_id) {
            throw new DomainException('Memberships must belong to the same company.');
        }

        if (! $this->access->allows($actor->user, $actor->company, $permissionCode)) {
            throw new DomainException('The membership actor is not authorized.');
        }

        return DB::transaction(function () use ($membership, $status): Membership {
            $lockedMembership = Membership::query()
                ->withoutGlobalScope('company')
                ->whereKey($membership->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $lockedMembership->is_owner
                && $lockedMembership->status === MembershipStatus::Active
                && $status !== MembershipStatus::Active
            ) {
                $activeOwnerCount = Membership::query()
                    ->withoutGlobalScope('company')
                    ->where('company_id', $lockedMembership->company_id)
                    ->where('status', MembershipStatus::Active)
                    ->where('is_owner', true)
                    ->lockForUpdate()
                    ->count();

                if ($activeOwnerCount <= 1) {
                    throw new DomainException('A company must keep at least one active owner.');
                }
            }

            $lockedMembership->update([
                'status' => $status,
                'joined_at' => $status === MembershipStatus::Active
                    ? ($lockedMembership->joined_at ?? now())
                    : $lockedMembership->joined_at,
            ]);

            return $lockedMembership->refresh();
        }, attempts: 3);
    }
}
