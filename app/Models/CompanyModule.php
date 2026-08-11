<?php

namespace App\Models;

use App\Enums\CompanyModuleStatus;
use App\Models\Concerns\BelongsToCompany;
use Database\Factories\CompanyModuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CompanyModuleStatus $status
 */
#[Fillable([
    'company_id',
    'module_id',
    'status',
    'settings',
    'enabled_at',
    'disabled_at',
    'enabled_by_membership_id',
])]
class CompanyModule extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<CompanyModuleFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => CompanyModuleStatus::Disabled->value,
    ];

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Module, $this> */
    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }

    /** @return BelongsTo<Membership, $this> */
    public function enabledBy(): BelongsTo
    {
        return $this->belongsTo(Membership::class, 'enabled_by_membership_id');
    }

    public function isEnabled(): bool
    {
        return $this->status === CompanyModuleStatus::Enabled;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CompanyModuleStatus::class,
            'settings' => 'array',
            'enabled_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }
}
