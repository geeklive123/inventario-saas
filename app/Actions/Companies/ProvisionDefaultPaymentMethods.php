<?php

namespace App\Actions\Companies;

use App\Models\Company;
use App\Models\PaymentMethod;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ProvisionDefaultPaymentMethods
{
    /** @return Collection<int, PaymentMethod> */
    public function handle(Company $company): Collection
    {
        return DB::transaction(function () use ($company): Collection {
            foreach (['cash' => 'Efectivo', 'qr' => 'QR', 'bank_transfer' => 'Transferencia'] as $code => $name) {
                PaymentMethod::query()->withoutGlobalScope('company')->updateOrCreate(
                    ['company_id' => $company->getKey(), 'code' => $code],
                    ['name' => $name, 'is_active' => true],
                );
            }

            return PaymentMethod::query()
                ->withoutGlobalScope('company')
                ->where('company_id', $company->getKey())
                ->orderBy('id')
                ->get();
        }, attempts: 3);
    }
}
