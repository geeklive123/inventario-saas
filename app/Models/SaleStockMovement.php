<?php

namespace App\Models;

use App\Enums\SaleStockMovementKind;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\ImmutableModel;
use Database\Factories\SaleStockMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'sale_id', 'stock_movement_id', 'kind'])]
class SaleStockMovement extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<SaleStockMovementFactory> */
    use HasFactory;

    use ImmutableModel;

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** @return BelongsTo<StockMovement, $this> */
    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['kind' => SaleStockMovementKind::class];
    }
}
