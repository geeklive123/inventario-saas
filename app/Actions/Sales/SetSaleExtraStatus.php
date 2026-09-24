<?php

namespace App\Actions\Sales;

use App\Models\Membership;
use App\Models\SaleExtra;
use App\Support\Authorization\CompanyAccess;
use DomainException;
use Illuminate\Support\Facades\DB;

class SetSaleExtraStatus
{
    public function __construct(private CompanyAccess $access) {}

    public function handle(Membership $actor, SaleExtra $extra, bool $isActive): SaleExtra
    {
        if ($actor->company_id !== $extra->company_id
            || ! $this->access->allows($actor->user, $actor->company, 'sales.extras.manage')) {
            throw new DomainException('La membership responsable no puede administrar extras.');
        }

        return DB::transaction(function () use ($actor, $extra, $isActive): SaleExtra {
            $lockedActor = Membership::query()
                ->withoutGlobalScope('company')
                ->whereKey($actor->getKey())
                ->where('company_id', $actor->company_id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedExtra = SaleExtra::query()
                ->withoutGlobalScope('company')
                ->whereKey($extra->getKey())
                ->where('company_id', $lockedActor->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->access->allows($lockedActor->user, $lockedActor->company, 'sales.extras.manage')) {
                throw new DomainException('La membership responsable no puede administrar extras.');
            }

            $lockedExtra->update(['is_active' => $isActive]);

            return $lockedExtra;
        }, attempts: 3);
    }
}
