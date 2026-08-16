<?php

namespace App\Actions\Users;

use App\Models\Membership;
use App\Models\MembershipRole;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Authorization\CompanyAccess;
use App\Support\Authorization\WorkerPermissionCatalog;
use DomainException;
use Illuminate\Support\Facades\DB;

class ConfigureWorkerAccess
{
    public function __construct(
        private CompanyAccess $access,
        private WorkerPermissionCatalog $permissionCatalog,
    ) {}

    /**
     * @param  array<int, string>  $permissionCodes
     */
    public function handle(Membership $actor, Membership $worker, string $profile, array $permissionCodes = []): Membership
    {
        if ($actor->company_id !== $worker->company_id || $worker->is_owner) {
            throw new DomainException('Solo se puede configurar a trabajadores de la empresa actual.');
        }

        if (! $this->access->allows($actor->user, $actor->company, 'core.users.assign_roles')) {
            throw new DomainException('La membership no puede asignar accesos.');
        }

        return DB::transaction(function () use ($actor, $worker, $profile, $permissionCodes): Membership {
            $role = $profile === 'Personalizado'
                ? $this->customRole($worker, $permissionCodes)
                : Role::query()->withoutGlobalScope('company')
                    ->where('company_id', $actor->company_id)
                    ->where('name', $profile)
                    ->where('is_system', true)
                    ->where('is_active', true)
                    ->firstOrFail();

            MembershipRole::query()->where('company_id', $worker->company_id)
                ->where('membership_id', $worker->getKey())->delete();
            MembershipRole::query()->create([
                'company_id' => $worker->company_id,
                'membership_id' => $worker->getKey(),
                'role_id' => $role->getKey(),
            ]);

            return $worker->refresh()->load(['user', 'roles.permissions']);
        }, attempts: 3);
    }

    /** @param array<int, string> $permissionCodes */
    private function customRole(Membership $worker, array $permissionCodes): Role
    {
        $allowedCodes = $this->permissionCatalog->permissionCodes($this->permissionCatalog->keys());

        if (array_diff($permissionCodes, $allowedCodes) !== []) {
            throw new DomainException('Uno o más permisos no están disponibles para asignación personalizada.');
        }

        $role = Role::query()->withoutGlobalScope('company')->updateOrCreate(
            ['company_id' => $worker->company_id, 'name' => "Personalizado #{$worker->getKey()}"],
            ['description' => "Acceso personalizado para {$worker->user->name}.", 'is_system' => false, 'is_active' => true],
        );

        $requestedCodes = array_values(array_unique($permissionCodes));
        $permissions = Permission::query()->whereIn('code', $requestedCodes)->where('is_active', true)->get();

        if ($permissions->count() !== count($requestedCodes)) {
            throw new DomainException('Uno o más permisos seleccionados no existen o están inactivos.');
        }

        $permissionIds = $permissions->pluck('id');
        $role->permissions()->syncWithPivotValues($permissionIds, ['company_id' => $worker->company_id]);

        return $role;
    }
}
