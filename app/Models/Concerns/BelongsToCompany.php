<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToCompany
{
    protected static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $builder): void {
            $currentCompany = app(CurrentCompany::class);

            if ($currentCompany->isResolved()) {
                $builder->where(
                    $builder->getModel()->qualifyColumn('company_id'),
                    $currentCompany->id(),
                );
            }
        });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function forCompany(Builder $query, Company|int $company): Builder
    {
        $companyId = $company instanceof Company ? $company->getKey() : $company;

        return $query->where($query->getModel()->qualifyColumn('company_id'), $companyId);
    }
}
