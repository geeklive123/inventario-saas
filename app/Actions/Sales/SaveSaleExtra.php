<?php

namespace App\Actions\Sales;

use App\Enums\SaleExtraType;
use App\Models\Membership;
use App\Models\SaleExtra;
use App\Support\Authorization\CompanyAccess;
use App\Support\Decimal;
use DomainException;
use Illuminate\Support\Facades\DB;

class SaveSaleExtra
{
    public function __construct(private CompanyAccess $access) {}

    public function handle(
        Membership $actor,
        string $name,
        int|float|string $defaultPriceBase,
        SaleExtraType $type,
        ?SaleExtra $extra = null,
    ): SaleExtra {
        if (($extra !== null && $extra->company_id !== $actor->company_id)
            || ! $this->access->allows($actor->user, $actor->company, 'sales.extras.manage')) {
            throw new DomainException('La membership responsable no puede administrar extras.');
        }

        $name = trim($name);
        $price = Decimal::normalize($defaultPriceBase, 4);

        if ($name === '' || bccomp($price, '0', 4) < 0) {
            throw new DomainException('El extra requiere nombre y un precio válido.');
        }

        return DB::transaction(function () use ($actor, $name, $price, $type, $extra): SaleExtra {
            $lockedActor = Membership::query()
                ->withoutGlobalScope('company')
                ->whereKey($actor->getKey())
                ->where('company_id', $actor->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->access->allows($lockedActor->user, $lockedActor->company, 'sales.extras.manage')) {
                throw new DomainException('La membership responsable no puede administrar extras.');
            }

            $model = $extra === null
                ? new SaleExtra(['company_id' => $lockedActor->company_id])
                : SaleExtra::query()
                    ->withoutGlobalScope('company')
                    ->whereKey($extra->getKey())
                    ->where('company_id', $lockedActor->company_id)
                    ->lockForUpdate()
                    ->firstOrFail();

            $model->fill([
                'name' => $name,
                'default_price_base' => $price,
                'type' => $type,
            ])->save();

            return $model;
        }, attempts: 3);
    }
}
