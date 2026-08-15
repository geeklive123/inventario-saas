<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\ProductRecipeFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property numeric-string $yield_quantity
 */
#[Fillable(['company_id', 'product_id', 'version', 'yield_quantity', 'active_slot', 'created_by_membership_id'])]
class ProductRecipe extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<ProductRecipeFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (ProductRecipe $recipe): void {
            $dirty = array_keys($recipe->getDirty());

            if ($recipe->getOriginal('active_slot') === 1 && $dirty !== ['active_slot']) {
                throw new DomainException('An active recipe cannot be edited destructively.');
            }
        });
        static::deleting(fn () => throw new DomainException('Recipe history cannot be deleted.'));
    }

    public function isActive(): bool
    {
        return $this->active_slot === 1;
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Membership, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'created_by_membership_id');
    }

    /** @return HasMany<ProductRecipeItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ProductRecipeItem::class);
    }

    /** @return HasMany<SaleItem, $this> */
    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class, 'product_recipe_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'yield_quantity' => 'decimal:6',
            'active_slot' => 'integer',
        ];
    }
}
