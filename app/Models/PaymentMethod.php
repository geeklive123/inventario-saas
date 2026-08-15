<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\PaymentMethodFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'code', 'name', 'is_active'])]
class PaymentMethod extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<PaymentMethodFactory> */
    use HasFactory;

    protected $attributes = ['is_active' => true];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new DomainException('Payment methods must be deactivated instead of deleted.'));
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<SalePayment, $this> */
    public function salePayments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
