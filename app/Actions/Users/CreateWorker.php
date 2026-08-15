<?php

namespace App\Actions\Users;

use App\Enums\MembershipStatus;
use App\Enums\UserStatus;
use App\Models\Membership;
use App\Models\MembershipRole;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\CompanyAccess;
use DomainException;
use Illuminate\Support\Facades\DB;

class CreateWorker
{
    public function __construct(private CompanyAccess $access) {}

    /**
     * @param  array{name: string, email: string, password: string}  $attributes
     */
    public function handle(Membership $actor, Role $role, array $attributes): Membership
    {
        if (! $this->access->allows($actor->user, $actor->company, 'core.users.create')) {
            throw new DomainException('The membership actor is not authorized.');
        }

        $this->ensureRoleBelongsToCompany($actor, $role);

        if (User::query()->where('email', $attributes['email'])->exists()) {
            throw new DomainException('Ya existe un usuario global con este correo.');
        }

        return DB::transaction(function () use ($actor, $role, $attributes): Membership {
            $user = User::query()->create([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
                'password' => $attributes['password'],
                'must_change_password' => true,
                'status' => UserStatus::Active,
            ]);
            $membership = Membership::query()->create([
                'company_id' => $actor->company_id,
                'user_id' => $user->getKey(),
                'status' => MembershipStatus::Active,
                'is_owner' => false,
                'invited_by_membership_id' => $actor->getKey(),
                'invited_at' => now(),
                'joined_at' => now(),
            ]);

            MembershipRole::query()->create([
                'company_id' => $actor->company_id,
                'membership_id' => $membership->getKey(),
                'role_id' => $role->getKey(),
            ]);

            return $membership->load(['user', 'roles']);
        }, attempts: 3);
    }

    private function ensureRoleBelongsToCompany(Membership $actor, Role $role): void
    {
        if ($role->company_id !== $actor->company_id || ! $role->is_active) {
            throw new DomainException('El rol debe estar activo y pertenecer a la empresa actual.');
        }
    }
}
