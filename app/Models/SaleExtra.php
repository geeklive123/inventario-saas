<?php

namespace App\Models;

use App\Enums\SaleExtraType;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\SaleExtraFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property numeric-string $default_price_base
 */
#[Fillable(['company_id', 'name', 'default_price_base', 'type', 'inventory_product_id', 'is_active'])]
class SaleExtra extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<SaleExtraFactory> */
    use HasFactory;

    protected $attributes = [
        'type' => SaleExtraType::Service->value,
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new DomainException('Sale extras must be deactivated instead of deleted.'));
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function inventoryProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'inventory_product_id');
    }

    /** @return HasMany<SaleExtraLine, $this> */
    public function saleLines(): HasMany
    {
        return $this->hasMany(SaleExtraLine::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'default_price_base' => 'decimal:4',
            'type' => SaleExtraType::class,
            'is_active' => 'boolean',
        ];
    }
}
