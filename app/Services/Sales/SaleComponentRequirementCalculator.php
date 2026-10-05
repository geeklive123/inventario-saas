<?php

namespace App\Services\Sales;

use App\Models\ProductRecipe;
use App\Models\ProductRecipeItem;
use App\Support\Decimal;

final class SaleComponentRequirementCalculator
{
    /** @return numeric-string */
    public function calculate(
        ProductRecipe $recipe,
        ProductRecipeItem $recipeItem,
        int|float|string $saleQuantity,
    ): string {
        $quantity = Decimal::normalize($saleQuantity, 6);
        $perUnit = bcdiv($recipeItem->quantity, $recipe->yield_quantity, 12);
        $wasteMultiplier = bcadd('1', bcdiv($recipeItem->waste_percentage, '100', 12), 12);

        return Decimal::normalize(bcmul(bcmul($perUnit, $wasteMultiplier, 12), $quantity, 12), 6);
    }
}
