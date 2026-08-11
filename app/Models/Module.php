<?php

namespace App\Models;

use App\Enums\ModuleCode;
use Database\Factories\ModuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property ModuleCode $code
 */
#[Fillable(['code', 'name', 'sort_order', 'is_active'])]
class Module extends Model
{
    /** @use HasFactory<ModuleFactory> */
    use HasFactory;

    /** @return HasMany<CompanyModule, $this> */
    public function companyModules(): HasMany
    {
        return $this->hasMany(CompanyModule::class);
    }

    /** @return BelongsToMany<Company, $this> */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_modules')
            ->withPivot(['status', 'settings', 'enabled_at', 'disabled_at', 'enabled_by_membership_id'])
            ->withTimestamps();
    }

    /** @return HasMany<Permission, $this> */
    public function permissions(): HasMany
    {
        return $this->hasMany(Permission::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'code' => ModuleCode::class,
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
