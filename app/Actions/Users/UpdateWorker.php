<?php

namespace App\Actions\Users;

use App\Models\Membership;
use App\Models\MembershipRole;
use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\CompanyAccess;
use DomainException;
use Illuminate\Support\Facades\DB;

class UpdateWorker
{
    public function __construct(private CompanyAccess $access) {}

    /**
     * @param  array{name: string, email: string}  $attributes
     */
    public function handle(Membership $actor, Membership $worker, Role $role, array $attributes): Membership
    {
        if ($actor->company_id !== $worker->company_id || $worker->is_owner) {
            throw new DomainException('Solo se pueden editar trabajadores de la empresa actual.');
        }

        if (! $this->access->allows($actor->user, $actor->company, 'core.users.update')
            || ! $this->access->allows($actor->user, $actor->company, 'core.users.assign_roles')) {
            throw new DomainException('The membership actor is not authorized.');
        }

        if ($role->company_id !== $actor->company_id || ! $role->is_active) {
            throw new DomainException('El rol debe estar activo y pertenecer a la empresa actual.');
        }

        if (User::query()->where('email', $attributes['email'])->whereKeyNot($worker->user_id)->exists()) {
            throw new DomainException('Ya existe un usuario global con este correo.');
        }

        return DB::transaction(function () use ($worker, $role, $attributes): Membership {
            $worker->user->update([
                'name' => $attributes['name'],
                'email' => $attributes['email'],
            ]);
            MembershipRole::query()
                ->where('company_id', $worker->company_id)
                ->where('membership_id', $worker->getKey())
                ->delete();
            MembershipRole::query()->create([
                'company_id' => $worker->company_id,
                'membership_id' => $worker->getKey(),
                'role_id' => $role->getKey(),
            ]);

            return $worker->refresh()->load(['user', 'roles']);
        }, attempts: 3);
    }
}
