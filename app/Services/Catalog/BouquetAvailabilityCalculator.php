<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductRecipe;
use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Support\Decimal;
use DomainException;
use Illuminate\Database\Eloquent\Collection;

class BouquetAvailabilityCalculator
{
    /**
     * @param  Collection<int, Product>  $bouquets
     * @return array<int, array{
     *     has_recipe: bool,
     *     available: bool,
     *     possible_quantity: numeric-string,
     *     components: list<array{
     *         product_id: int,
     *         name: string,
     *         required_quantity: numeric-string,
     *         available_quantity: numeric-string,
     *         missing_quantity: numeric-string,
     *         possible_quantity: numeric-string,
     *         sufficient: bool
     *     }>
     * }>
     */
    public function calculate(Collection $bouquets, Warehouse $warehouse): array
    {
        if ($bouquets->contains(fn (Product $product): bool => $product->company_id !== $warehouse->company_id)) {
            throw new DomainException('Bouquets and warehouse must belong to the same company.');
        }

        $recipes = ProductRecipe::query()
            ->with([
                'items:id,company_id,product_recipe_id,component_product_id,quantity,waste_percentage',
                'items.componentProduct:id,company_id,name',
            ])
            ->where('company_id', $warehouse->company_id)
            ->whereIn('product_id', $bouquets->modelKeys())
            ->where('active_slot', 1)
            ->get()
            ->keyBy('product_id');
        $componentIds = $recipes->flatMap(
            fn (ProductRecipe $recipe) => $recipe->items->pluck('component_product_id'),
        )->unique()->values();
        $balances = StockBalance::query()
            ->where('company_id', $warehouse->company_id)
            ->where('warehouse_id', $warehouse->getKey())
            ->whereIn('product_id', $componentIds)
            ->get(['id', 'company_id', 'warehouse_id', 'product_id', 'quantity'])
            ->keyBy('product_id');
        $availability = [];

        foreach ($bouquets as $bouquet) {
            $recipe = $recipes->get($bouquet->getKey());

            if (! $recipe instanceof ProductRecipe) {
                $availability[(int) $bouquet->getKey()] = $this->emptyResult();

                continue;
            }

            $components = [];
            $possibleQuantity = null;

            foreach ($recipe->items as $item) {
                $component = $item->componentProduct;
                $requiredQuantity = $this->requiredQuantity(
                    $item->quantity,
                    $recipe->yield_quantity,
                    $item->waste_percentage,
                );
                $availableQuantity = Decimal::normalize(
                    $balances->has($item->component_product_id)
                        ? $balances->get($item->component_product_id)->quantity
                        : 0,
                    6,
                );
                $sufficient = bccomp($availableQuantity, $requiredQuantity, 6) >= 0;
                $componentPossibleQuantity = bccomp($availableQuantity, '0', 6) <= 0
                    ? '0'
                    : bcdiv($availableQuantity, $requiredQuantity, 0);
                $missingQuantity = $sufficient
                    ? Decimal::normalize(0, 6)
                    : Decimal::normalize(bcsub($requiredQuantity, $availableQuantity, 6), 6);
                $possibleQuantity = $possibleQuantity === null
                    || bccomp($componentPossibleQuantity, $possibleQuantity, 0) < 0
                        ? $componentPossibleQuantity
                        : $possibleQuantity;
                $components[] = [
                    'product_id' => (int) $component->getKey(),
                    'name' => $component->name,
                    'required_quantity' => $requiredQuantity,
                    'available_quantity' => $availableQuantity,
                    'missing_quantity' => $missingQuantity,
                    'possible_quantity' => $componentPossibleQuantity,
                    'sufficient' => $sufficient,
                ];
            }

            $possibleQuantity ??= '0';
            $availability[(int) $bouquet->getKey()] = [
                'has_recipe' => true,
                'available' => bccomp($possibleQuantity, '0', 0) > 0,
                'possible_quantity' => $possibleQuantity,
                'components' => $components,
            ];
        }

        return $availability;
    }

    /**
     * @param  numeric-string  $quantity
     * @param  numeric-string  $yieldQuantity
     * @param  numeric-string  $wastePercentage
     * @return numeric-string
     */
    private function requiredQuantity(string $quantity, string $yieldQuantity, string $wastePercentage): string
    {
        $quantityPerBouquet = bcdiv($quantity, $yieldQuantity, 12);
        $wasteMultiplier = bcadd('1', bcdiv($wastePercentage, '100', 12), 12);

        return Decimal::normalize(bcmul($quantityPerBouquet, $wasteMultiplier, 12), 6);
    }

    /**
     * @return array{has_recipe: bool, available: bool, possible_quantity: numeric-string, components: list<array{
     *     product_id: int,
     *     name: string,
     *     required_quantity: numeric-string,
     *     available_quantity: numeric-string,
     *     missing_quantity: numeric-string,
     *     possible_quantity: numeric-string,
     *     sufficient: bool
     * }>}
     */
    private function emptyResult(): array
    {
        return [
            'has_recipe' => false,
            'available' => false,
            'possible_quantity' => '0',
            'components' => [],
        ];
    }
}
