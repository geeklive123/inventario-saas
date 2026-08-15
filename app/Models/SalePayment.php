<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\ImmutableModel;
use Database\Factories\SalePaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'sale_id', 'payment_method_id', 'payment_method_name', 'amount_base'])]
class SalePayment extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<SalePaymentFactory> */
    use HasFactory;

    use ImmutableModel;

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount_base' => 'decimal:4'];
    }
}
