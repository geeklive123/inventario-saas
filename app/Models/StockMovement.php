<?php

namespace App\Models;

use App\Enums\StockMovementType;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\ImmutableModel;
use Database\Factories\StockMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property StockMovementType $type
 * @property Carbon $occurred_at
 */
#[Fillable([
    'company_id', 'warehouse_id', 'type', 'reversal_of_movement_id',
    'created_by_membership_id', 'reason', 'occurred_at',
])]
class StockMovement extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<StockMovementFactory> */
    use HasFactory;

    use ImmutableModel;

    public const UPDATED_AT = null;

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<Membership, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'created_by_membership_id');
    }

    /** @return BelongsTo<StockMovement, $this> */
    public function reversedMovement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_movement_id');
    }

    /** @return HasMany<StockMovement, $this> */
    public function reversals(): HasMany
    {
        return $this->hasMany(self::class, 'reversal_of_movement_id');
    }

    /** @return HasMany<StockMovementLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(StockMovementLine::class);
    }

    /** @return HasMany<SaleStockMovement, $this> */
    public function saleLinks(): HasMany
    {
        return $this->hasMany(SaleStockMovement::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
