<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\UnitFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'code', 'name', 'symbol', 'decimal_places', 'is_active'])]
class Unit extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<UnitFactory> */
    use HasFactory;

    protected $attributes = ['is_active' => true];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new DomainException('Units must be deactivated instead of deleted.'));
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'decimal_places' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
