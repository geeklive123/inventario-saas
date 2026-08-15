<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property MembershipStatus $status
 */
#[Fillable([
    'company_id',
    'user_id',
    'status',
    'is_owner',
    'invited_by_membership_id',
    'invited_at',
    'joined_at',
])]
class Membership extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<MembershipFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => MembershipStatus::Invited->value,
        'is_owner' => false,
    ];

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Membership, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(self::class, 'invited_by_membership_id');
    }

    /** @return HasMany<Membership, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(self::class, 'invited_by_membership_id');
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'membership_roles')
            ->withPivot('company_id')
            ->withTimestamps();
    }

    /** @return HasMany<ProductRecipe, $this> */
    public function createdRecipes(): HasMany
    {
        return $this->hasMany(ProductRecipe::class, 'created_by_membership_id');
    }

    /** @return HasMany<StockMovement, $this> */
    public function createdStockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'created_by_membership_id');
    }

    /** @return HasMany<Sale, $this> */
    public function confirmedSales(): HasMany
    {
        return $this->hasMany(Sale::class, 'confirmed_by_membership_id');
    }

    /** @return HasMany<Sale, $this> */
    public function voidedSales(): HasMany
    {
        return $this->hasMany(Sale::class, 'voided_by_membership_id');
    }

    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'is_owner' => 'boolean',
            'invited_at' => 'datetime',
            'joined_at' => 'datetime',
        ];
    }
}
