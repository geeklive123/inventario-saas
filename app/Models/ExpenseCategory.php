<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\ExpenseCategoryFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'code', 'name', 'description', 'is_active'])]
class ExpenseCategory extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<ExpenseCategoryFactory> */
    use HasFactory;

    protected $attributes = ['is_active' => true];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new DomainException('Expense categories must be deactivated instead of deleted.'));
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
