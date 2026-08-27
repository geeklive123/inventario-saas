<?php

namespace App\Actions\Sales;

use App\Models\Membership;
use App\Models\SaleExtra;
use App\Support\Authorization\CompanyAccess;
use DomainException;
use Illuminate\Support\Facades\DB;

class DeactivateSaleExtra
{
    public function __construct(private CompanyAccess $access) {}

    public function handle(Membership $actor, SaleExtra $extra): SaleExtra
    {
        if ($extra->company_id !== $actor->company_id
            || ! $this->access->allows($actor->user, $actor->company, 'sales.extras.manage')) {
            throw new DomainException('La membership responsable no puede administrar extras.');
        }

        return DB::transaction(function () use ($actor, $extra): SaleExtra {
            $lockedActor = Membership::query()
                ->withoutGlobalScope('company')
                ->whereKey($actor->getKey())
                ->where('company_id', $actor->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->access->allows($lockedActor->user, $lockedActor->company, 'sales.extras.manage')) {
                throw new DomainException('La membership responsable no puede administrar extras.');
            }

            $lockedExtra = SaleExtra::query()
                ->withoutGlobalScope('company')
                ->whereKey($extra->getKey())
                ->where('company_id', $lockedActor->company_id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedExtra->update(['is_active' => false]);

            return $lockedExtra;
        }, attempts: 3);
    }
}
