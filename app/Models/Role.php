<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['company_id', 'name', 'description', 'is_system', 'is_active'])]
class Role extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions')
            ->withPivot('company_id')
            ->withTimestamps();
    }

    /** @return BelongsToMany<Membership, $this> */
    public function memberships(): BelongsToMany
    {
        return $this->belongsToMany(Membership::class, 'membership_roles')
            ->withPivot('company_id')
            ->withTimestamps();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
