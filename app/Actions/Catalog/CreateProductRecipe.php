<?php

namespace App\Actions\Catalog;

use App\Enums\InventoryBehavior;
use App\Enums\ProductItemType;
use App\Models\Membership;
use App\Models\Product;
use App\Models\ProductRecipe;
use App\Models\ProductRecipeItem;
use App\Support\Authorization\CompanyAccess;
use App\Support\Decimal;
use DomainException;
use Illuminate\Support\Facades\DB;

class CreateProductRecipe
{
    public function __construct(private CompanyAccess $access) {}

    /**
     * @param  array<int, array{product: Product, quantity: int|float|string, waste_percentage?: int|float|string}>  $components
     */
    public function handle(
        Membership $actor,
        Product $product,
        int|float|string $yieldQuantity,
        array $components,
    ): ProductRecipe {
        if (! $this->access->allows($actor->user, $actor->company, 'catalog.recipes.manage')) {
            throw new DomainException('The membership actor is not authorized.');
        }

        if ($actor->company_id !== $product->company_id) {
            throw new DomainException('The product must belong to the actor company.');
        }

        if ($product->item_type !== ProductItemType::Physical
            || $product->inventory_behavior !== InventoryBehavior::Components) {
            throw new DomainException('Only physical component-based products can have recipes.');
        }

        if (bccomp(Decimal::normalize($yieldQuantity, 6), '0', 6) <= 0 || $components === []) {
            throw new DomainException('A recipe requires a positive yield and at least one component.');
        }

        return DB::transaction(function () use ($actor, $product, $yieldQuantity, $components): ProductRecipe {
            Product::query()
                ->withoutGlobalScope('company')
                ->whereKey($product->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $activeRecipe = ProductRecipe::query()
                ->withoutGlobalScope('company')
                ->where('company_id', $product->company_id)
                ->where('product_id', $product->getKey())
                ->where('active_slot', 1)
                ->lockForUpdate()
                ->first();

            $version = (int) ProductRecipe::query()
                ->withoutGlobalScope('company')
                ->where('company_id', $product->company_id)
                ->where('product_id', $product->getKey())
                ->max('version') + 1;

            $recipe = ProductRecipe::query()->create([
                'company_id' => $product->company_id,
                'product_id' => $product->getKey(),
                'version' => $version,
                'yield_quantity' => Decimal::normalize($yieldQuantity, 6),
                'active_slot' => null,
                'created_by_membership_id' => $actor->getKey(),
            ]);

            foreach ($components as $component) {
                $this->validateComponent($product, $component);

                ProductRecipeItem::query()->create([
                    'company_id' => $product->company_id,
                    'product_recipe_id' => $recipe->getKey(),
                    'component_product_id' => $component['product']->getKey(),
                    'quantity' => Decimal::normalize($component['quantity'], 6),
                    'waste_percentage' => Decimal::normalize($component['waste_percentage'] ?? 0, 6),
                ]);
            }

            $activeRecipe?->update(['active_slot' => null]);
            $recipe->update(['active_slot' => 1]);

            return $recipe->load(['product', 'items.componentProduct']);
        }, attempts: 3);
    }

    /**
     * @param  array{product: Product, quantity: int|float|string, waste_percentage?: int|float|string}  $component
     */
    private function validateComponent(Product $product, array $component): void
    {
        $componentProduct = $component['product'];
        $wastePercentage = Decimal::normalize($component['waste_percentage'] ?? 0, 6);

        if ($componentProduct->company_id !== $product->company_id) {
            throw new DomainException('Recipe components must belong to the same company.');
        }

        if ($componentProduct->getKey() === $product->getKey()
            || $componentProduct->item_type !== ProductItemType::Physical
            || $componentProduct->inventory_behavior !== InventoryBehavior::Self) {
            throw new DomainException('A recipe component must be a non-composed physical product.');
        }

        if (bccomp(Decimal::normalize($component['quantity'], 6), '0', 6) <= 0
            || bccomp($wastePercentage, '0', 6) < 0
            || bccomp($wastePercentage, '100', 6) > 0) {
            throw new DomainException('Component quantity and waste percentage are invalid.');
        }
    }
}
