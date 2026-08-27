<?php

namespace App\Models;

use App\Enums\CompanyStatus;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property CompanyStatus $status
 */
#[Fillable([
    'name',
    'legal_name',
    'tax_identifier',
    'base_currency_id',
    'timezone',
    'locale',
    'logo_path',
    'primary_color',
    'allow_negative_stock',
    'status',
])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected $attributes = [
        'allow_negative_stock' => false,
        'status' => CompanyStatus::Active->value,
    ];

    /** @return BelongsTo<Currency, $this> */
    public function baseCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'base_currency_id');
    }

    /** @return HasMany<Membership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** @return HasMany<Role, $this> */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    /** @return HasMany<CompanyModule, $this> */
    public function companyModules(): HasMany
    {
        return $this->hasMany(CompanyModule::class);
    }

    /** @return BelongsToMany<Module, $this> */
    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'company_modules')
            ->withPivot(['status', 'settings', 'enabled_at', 'disabled_at', 'enabled_by_membership_id'])
            ->withTimestamps();
    }

    /** @return HasMany<Branch, $this> */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /** @return HasMany<Warehouse, $this> */
    public function warehouses(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }

    /** @return HasMany<Unit, $this> */
    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    /** @return HasMany<Category, $this> */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** @return HasMany<StockMovement, $this> */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /** @return HasMany<PaymentMethod, $this> */
    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
    }

    /** @return HasMany<Sale, $this> */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /** @return HasMany<SaleExtra, $this> */
    public function saleExtras(): HasMany
    {
        return $this->hasMany(SaleExtra::class);
    }

    /** @return HasMany<ExpenseCategory, $this> */
    public function expenseCategories(): HasMany
    {
        return $this->hasMany(ExpenseCategory::class);
    }

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'allow_negative_stock' => 'boolean',
            'status' => CompanyStatus::class,
        ];
    }
}
