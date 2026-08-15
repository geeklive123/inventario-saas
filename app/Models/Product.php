<?php

namespace App\Models;

use App\Enums\InventoryBehavior;
use App\Enums\ProductItemType;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\ProductFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ProductItemType $item_type
 * @property InventoryBehavior $inventory_behavior
 * @property numeric-string $sale_price_base
 * @property numeric-string|null $fallback_unit_cost_base
 */
#[Fillable([
    'company_id', 'unit_id', 'category_id', 'sku', 'barcode', 'name', 'description',
    'item_type', 'inventory_behavior', 'is_sellable', 'sale_price_base',
    'fallback_unit_cost_base', 'is_active',
])]
class Product extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $attributes = [
        'item_type' => ProductItemType::Physical->value,
        'inventory_behavior' => InventoryBehavior::Self->value,
        'is_sellable' => true,
        'sale_price_base' => 0,
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        static::saving(function (Product $product): void {
            $validService = $product->item_type === ProductItemType::Service
                && $product->inventory_behavior === InventoryBehavior::None;
            $validPhysical = $product->item_type === ProductItemType::Physical
                && $product->inventory_behavior !== InventoryBehavior::None;

            if (! $validService && ! $validPhysical) {
                throw new DomainException('The product type and inventory behavior are incompatible.');
            }
        });
        static::deleting(fn () => throw new DomainException('Products must be deactivated instead of deleted.'));
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<ProductRecipe, $this> */
    public function recipes(): HasMany
    {
        return $this->hasMany(ProductRecipe::class);
    }

    /** @return HasMany<ProductRecipeItem, $this> */
    public function recipeUsages(): HasMany
    {
        return $this->hasMany(ProductRecipeItem::class, 'component_product_id');
    }

    /** @return HasMany<StockBalance, $this> */
    public function stockBalances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }

    /** @return HasMany<StockMovementLine, $this> */
    public function stockMovementLines(): HasMany
    {
        return $this->hasMany(StockMovementLine::class);
    }

    /** @return HasMany<SaleItem, $this> */
    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    /** @return HasMany<SaleItemComponent, $this> */
    public function saleItemComponents(): HasMany
    {
        return $this->hasMany(SaleItemComponent::class);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    #[Scope]
    protected function sellable(Builder $query): Builder
    {
        return $query->where('is_sellable', true);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    #[Scope]
    protected function selfManaged(Builder $query): Builder
    {
        return $query
            ->where('item_type', ProductItemType::Physical)
            ->where('inventory_behavior', InventoryBehavior::Self);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    #[Scope]
    protected function composed(Builder $query): Builder
    {
        return $query
            ->where('item_type', ProductItemType::Physical)
            ->where('inventory_behavior', InventoryBehavior::Components);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'item_type' => ProductItemType::class,
            'inventory_behavior' => InventoryBehavior::class,
            'is_sellable' => 'boolean',
            'sale_price_base' => 'decimal:4',
            'fallback_unit_cost_base' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }
}
