<?php

namespace App\Actions\Companies;

use App\Models\Company;
use App\Models\ExpenseCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProvisionDefaultExpenseCategories
{
    /** @return Collection<string, ExpenseCategory> */
    public function handle(Company $company): Collection
    {
        $categories = [
            'alquiler' => 'Alquiler',
            'servicios-basicos' => 'Servicios básicos',
            'internet-telefonia' => 'Internet / Telefonía',
            'transporte' => 'Transporte',
            'delivery' => 'Delivery',
            'limpieza' => 'Limpieza',
            'publicidad' => 'Publicidad',
            'mantenimiento' => 'Mantenimiento',
            'sueldos' => 'Sueldos',
            'insumos-no-inventariables' => 'Insumos no inventariables',
            'otros' => 'Otros',
        ];

        return DB::transaction(function () use ($company, $categories): Collection {
            return collect($categories)->mapWithKeys(function (string $name, string $code) use ($company): array {
                $category = ExpenseCategory::query()->withoutGlobalScope('company')->updateOrCreate(
                    ['company_id' => $company->getKey(), 'code' => $code],
                    ['name' => $name, 'is_active' => true],
                );

                return [$code => $category];
            });
        });
    }
}
