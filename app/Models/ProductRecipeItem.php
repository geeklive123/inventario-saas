<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\ProductRecipeItemFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property numeric-string $quantity
 * @property numeric-string $waste_percentage
 */
#[Fillable(['company_id', 'product_recipe_id', 'component_product_id', 'quantity', 'waste_percentage'])]
class ProductRecipeItem extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<ProductRecipeItemFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        $guardActiveRecipe = function (ProductRecipeItem $item): void {
            if ($item->recipe()->withoutGlobalScope('company')->value('active_slot') === 1) {
                throw new DomainException('Items of an active recipe cannot be changed destructively.');
            }
        };

        static::creating($guardActiveRecipe);
        static::updating($guardActiveRecipe);
        static::deleting($guardActiveRecipe);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<ProductRecipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(ProductRecipe::class, 'product_recipe_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function componentProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6',
            'waste_percentage' => 'decimal:6',
        ];
    }
}
