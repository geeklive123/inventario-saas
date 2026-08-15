<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductRecipe;
use App\Models\StockBalance;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryService;
use App\Support\Decimal;
use DomainException;
use Illuminate\Database\Eloquent\Collection;

class BouquetCostCalculator
{
    public function __construct(private InventoryService $inventory) {}

    /**
     * @param  Collection<int, Product>  $bouquets
     * @return array<int, array{cost_base: numeric-string, complete: bool, has_recipe: bool, uses_fallback: bool, missing_costs: list<string>}>
     */
    public function calculate(Collection $bouquets, Warehouse $warehouse): array
    {
        if ($bouquets->contains(fn (Product $product): bool => $product->company_id !== $warehouse->company_id)) {
            throw new DomainException('Bouquets and warehouse must belong to the same company.');
        }

        $recipes = ProductRecipe::query()
            ->with('items.componentProduct')
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
            ->get()
            ->keyBy('product_id');

        $costs = [];

        foreach ($bouquets as $bouquet) {
            $recipe = $recipes->get($bouquet->getKey());

            if (! $recipe instanceof ProductRecipe) {
                $costs[(int) $bouquet->getKey()] = $this->emptyResult();

                continue;
            }

            $total = Decimal::normalize(0, 4);
            $missingCosts = [];
            $usesFallback = false;

            foreach ($recipe->items as $item) {
                $component = $item->componentProduct;
                $balance = $balances->get($component->getKey());
                $unitCost = $this->inventory->currentUnitCost($component, $balance);

                if ($unitCost === null) {
                    $missingCosts[] = $component->name;

                    continue;
                }

                $usesFallback = $usesFallback || $balance === null
                    || (bccomp($balance->quantity, '0', 6) === 0 && $balance->last_inbound_unit_cost_base === null);
                $quantityPerBouquet = bcdiv($item->quantity, $recipe->yield_quantity, 12);
                $wasteMultiplier = bcadd('1', bcdiv($item->waste_percentage, '100', 12), 12);
                $consumption = bcmul($quantityPerBouquet, $wasteMultiplier, 12);
                $total = $this->money(bcadd($total, bcmul($consumption, $unitCost, 12), 12));
            }

            $costs[(int) $bouquet->getKey()] = [
                'cost_base' => $total,
                'complete' => $missingCosts === [],
                'has_recipe' => true,
                'uses_fallback' => $usesFallback,
                'missing_costs' => $missingCosts,
            ];
        }

        return $costs;
    }

    /** @return array{cost_base: numeric-string, complete: bool, has_recipe: bool, uses_fallback: bool, missing_costs: list<string>} */
    private function emptyResult(): array
    {
        return [
            'cost_base' => Decimal::normalize(0, 4),
            'complete' => false,
            'has_recipe' => false,
            'uses_fallback' => false,
            'missing_costs' => [],
        ];
    }

    /**
     * @param  numeric-string  $value
     * @return numeric-string
     */
    private function money(string $value): string
    {
        $adjustment = bccomp($value, '0', 12) < 0 ? '-0.00005' : '0.00005';

        return bcadd(bcadd($value, $adjustment, 12), '0', 4);
    }
}
