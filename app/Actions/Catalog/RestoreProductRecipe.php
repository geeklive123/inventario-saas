<?php

namespace App\Actions\Catalog;

use App\Models\Membership;
use App\Models\ProductRecipe;
use App\Support\Authorization\CompanyAccess;
use DomainException;
use Illuminate\Support\Facades\DB;

class RestoreProductRecipe
{
    public function __construct(
        private CreateProductRecipe $createRecipe,
        private CompanyAccess $access,
    ) {}

    public function handle(Membership $actor, ProductRecipe $historicalRecipe): ProductRecipe
    {
        if ($actor->company_id !== $historicalRecipe->company_id
            || ! $this->access->allows($actor->user, $actor->company, 'catalog.recipes.manage')) {
            throw new DomainException('La membership responsable no puede restaurar esta receta.');
        }

        return DB::transaction(function () use ($actor, $historicalRecipe): ProductRecipe {
            $lockedRecipe = ProductRecipe::query()
                ->withoutGlobalScope('company')
                ->with(['product', 'items.componentProduct'])
                ->whereKey($historicalRecipe->getKey())
                ->where('company_id', $actor->company_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRecipe->isActive()) {
                throw new DomainException('La receta seleccionada dejó de ser histórica.');
            }

            $components = $lockedRecipe->items->map(fn ($item): array => [
                'product' => $item->componentProduct,
                'quantity' => $item->quantity,
                'waste_percentage' => $item->waste_percentage,
            ])->all();

            return $this->createRecipe->handle(
                $actor,
                $lockedRecipe->product,
                $lockedRecipe->yield_quantity,
                $components,
            );
        }, attempts: 3);
    }
}
