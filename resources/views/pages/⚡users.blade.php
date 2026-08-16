<?php

use App\Actions\Memberships\ChangeMembershipStatus;
use App\Actions\Users\CreateWorker;
use App\Actions\Users\ConfigureWorkerAccess;
use App\Actions\Users\UpdateWorker;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\Role;
use App\Support\Authorization\WorkerPermissionCatalog;
use App\Support\Tenancy\CurrentCompany;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Usuarios')] class extends Component
{
    use WithPagination;
    public string $search = '';
    public ?int $editingId = null;
    public string $name = '';
    public string $email = '';
    public string $temporaryPassword = '';
    public string $profile = 'Vendedor';
    public string $membershipStatus = 'active';
    /** @var array<int, string> */
    public array $selectedCapabilities = [];

    public function create(): void { Gate::authorize('create', Membership::class); $this->resetForm(); Flux::modal('worker-form')->show(); }
    public function edit(int $id): void
    {
        $membership = Membership::query()->with(['user', 'roles'])->findOrFail($id); Gate::authorize('update', $membership);
        $role = $membership->roles->first(); $this->editingId = $membership->getKey(); $this->name = $membership->user->name; $this->email = $membership->user->email; $this->membershipStatus = $membership->status->value; $this->profile = $role?->is_system ? $role->name : 'Personalizado'; $this->selectedCapabilities = app(WorkerPermissionCatalog::class)->selectedCapabilities($role?->permissions()->pluck('code') ?? []); Flux::modal('worker-form')->show();
    }
    public function save(): void
    {
        $membership = $this->editingId ? Membership::query()->with('user')->findOrFail($this->editingId) : null;
        Gate::authorize($membership ? 'update' : 'create', $membership ?? Membership::class);
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($membership?->user_id)],
            'temporaryPassword' => [$membership ? 'nullable' : 'required', 'string', Password::defaults()],
            'profile' => ['required', Rule::in(['Administrador', 'Vendedor', 'Inventario', 'Trabajador', 'Personalizado'])],
            'selectedCapabilities' => ['array'],
            'selectedCapabilities.*' => ['string', Rule::in(app(WorkerPermissionCatalog::class)->keys())],
        ]);
        $baseProfile = $data['profile'] === 'Personalizado' ? 'Trabajador' : $data['profile'];
        $role = Role::query()->where('name', $baseProfile)->where('is_system', true)->where('is_active', true)->firstOrFail();
        try {
            if ($membership) { app(UpdateWorker::class)->handle(app(CurrentCompany::class)->membership(), $membership, $role, $data); }
            else { $membership = app(CreateWorker::class)->handle(app(CurrentCompany::class)->membership(), $role, ['name'=>$data['name'],'email'=>$data['email'],'password'=>$data['temporaryPassword']]); }
            $permissionCodes = app(WorkerPermissionCatalog::class)->permissionCodes($data['selectedCapabilities']);
            app(ConfigureWorkerAccess::class)->handle(app(CurrentCompany::class)->membership(), $membership, $data['profile'], $permissionCodes);
        } catch (\DomainException $exception) { $this->addError('email', $exception->getMessage()); return; }
        Flux::modal('worker-form')->close(); Flux::toast(variant: 'success', text: $membership ? 'Trabajador actualizado.' : 'Trabajador creado con contraseña temporal.'); $this->resetForm();
    }
    public function toggleStatus(int $id): void
    {
        $membership = Membership::query()->findOrFail($id); Gate::authorize('changeStatus', $membership);
        $status = $membership->status === MembershipStatus::Active ? MembershipStatus::Suspended : MembershipStatus::Active;
        app(ChangeMembershipStatus::class)->handle(app(CurrentCompany::class)->membership(), $membership, $status, 'core.users.suspend'); Flux::toast(variant: 'success', text: $status === MembershipStatus::Active ? 'Trabajador reactivado.' : 'Trabajador suspendido.');
    }
    #[Computed] public function workers(): LengthAwarePaginator { return Membership::query()->with(['user', 'roles'])->where('is_owner', false)->when($this->search, fn($q)=>$q->whereHas('user', fn($u)=>$u->where('name','like','%'.$this->search.'%')->orWhere('email','like','%'.$this->search.'%')))->latest()->paginate(15); }
    /** @return array<string, array{label: string, capabilities: array<string, array{label: string, permissions: list<string>}>}> */
    public function permissionGroups(): array
    {
        return app(WorkerPermissionCatalog::class)->groups();
    }
    private function resetForm(): void { $this->reset(['editingId','name','email','temporaryPassword','selectedCapabilities']); $this->profile = 'Vendedor'; $this->membershipStatus = 'active'; $this->resetValidation(); }
}; ?>
<div class="flex w-full flex-col gap-6">
    <div class="flex flex-col justify-between gap-4 md:flex-row md:items-center">
        <div><flux:heading size="xl">Usuarios</flux:heading><flux:text>Asigna un perfil simple o personaliza qué puede hacer cada trabajador.</flux:text></div>
        @can('create', App\Models\Membership::class)<flux:button variant="primary" icon="user-plus" wire:click="create">Crear trabajador</flux:button>@endcan
    </div>
    <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Buscar por nombre o correo" class="max-w-md" />
    <flux:card class="overflow-hidden p-0!">
        <div class="overflow-x-auto"><flux:table :paginate="$this->workers"><flux:table.columns><flux:table.column>Trabajador</flux:table.column><flux:table.column>Perfil</flux:table.column><flux:table.column>Contraseña</flux:table.column><flux:table.column>Estado</flux:table.column><flux:table.column></flux:table.column></flux:table.columns><flux:table.rows>@forelse($this->workers as $worker)<flux:table.row :key="$worker->id"><flux:table.cell variant="strong">{{ $worker->user->name }}<flux:text size="sm">{{ $worker->user->email }}</flux:text></flux:table.cell><flux:table.cell>{{ $worker->roles->pluck('name')->join(', ') ?: 'Sin perfil' }}</flux:table.cell><flux:table.cell>{{ $worker->user->must_change_password ? 'Cambio pendiente' : 'Personal' }}</flux:table.cell><flux:table.cell><flux:badge :color="$worker->status===MembershipStatus::Active ? 'green':'zinc'">{{ $worker->status===MembershipStatus::Active ? 'Activo':'Suspendido' }}</flux:badge></flux:table.cell><flux:table.cell><div class="flex justify-end gap-2"><flux:button size="sm" variant="subtle" wire:click="edit({{ $worker->id }})">Editar</flux:button><flux:button size="sm" variant="subtle" wire:click="toggleStatus({{ $worker->id }})" wire:confirm="¿Confirmas este cambio de estado?">{{ $worker->status===MembershipStatus::Active ? 'Suspender':'Reactivar' }}</flux:button></div></flux:table.cell></flux:table.row>@empty<flux:table.row><flux:table.cell colspan="5"><div class="py-12 text-center"><flux:heading>No hay trabajadores</flux:heading><flux:text>Crea el primer usuario operativo de la florería.</flux:text></div></flux:table.cell></flux:table.row>@endforelse</flux:table.rows></flux:table></div>
    </flux:card>
    <flux:modal name="worker-form" class="max-w-4xl">
        <form wire:submit="save" class="space-y-6">
            <div><flux:heading size="lg">{{ $editingId ? 'Editar trabajador' : 'Crear trabajador' }}</flux:heading>@if(!$editingId)<flux:text>Usará una contraseña temporal que deberá cambiar al iniciar sesión.</flux:text>@endif</div>
            <div class="grid gap-4 md:grid-cols-2"><flux:input wire:model="name" label="Nombre" required /><flux:input wire:model="email" type="email" label="Correo" required /></div>
            @if($editingId)<div class="flex flex-wrap items-center gap-2"><flux:text>Estado:</flux:text><flux:badge :color="$membershipStatus === 'active' ? 'green' : 'zinc'">{{ $membershipStatus === 'active' ? 'Activo' : 'Suspendido' }}</flux:badge><flux:text size="sm">El cambio de estado se realiza desde la lista para requerir confirmación.</flux:text></div>@else<flux:input wire:model="temporaryPassword" type="password" label="Contraseña temporal" viewable required />@endif
            <div>
                <flux:heading>Perfil rápido</flux:heading>
                <flux:text class="mt-1">Selecciona una base sencilla o configura los accesos uno por uno.</flux:text>
                <flux:radio.group wire:model.live="profile" variant="cards" class="mt-3 grid gap-3 md:grid-cols-2">
                    <flux:radio value="Vendedor" label="Vendedor" description="Ventas y consulta operativa, sin finanzas ni administración." />
                    <flux:radio value="Inventario" label="Inventario" description="Existencias, compras, salidas y mermas, sin finanzas ni usuarios." />
                    <flux:radio value="Administrador" label="Administrador" description="Todos los permisos empresariales habilitados." />
                    <flux:radio value="Personalizado" label="Personalizado" description="El dueño selecciona manualmente cada acceso." />
                </flux:radio.group>
            </div>
            @if($profile === 'Personalizado')
                <div class="space-y-5 rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                    <div><flux:heading>Permisos personalizados</flux:heading><flux:text class="mt-1">Solo se muestran acciones de negocio. Los identificadores internos permanecen ocultos.</flux:text></div>
                    <div class="grid gap-5 lg:grid-cols-2">
                        @foreach($this->permissionGroups() as $groupKey => $group)
                            <section wire:key="permission-group-{{ $groupKey }}" class="rounded-xl bg-zinc-50 p-4 dark:bg-zinc-800/60">
                                <flux:heading size="sm">{{ $group['label'] }}</flux:heading>
                                <div class="mt-3 grid gap-3">
                                    @foreach($group['capabilities'] as $capabilityKey => $capability)
                                        <flux:checkbox wire:key="capability-{{ $capabilityKey }}" wire:model="selectedCapabilities" :value="$capabilityKey" :label="$capability['label']" />
                                    @endforeach
                                </div>
                            </section>
                        @endforeach
                    </div>
                </div>
            @endif
            <div class="flex justify-end gap-3"><flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close><flux:button type="submit" variant="primary">Guardar trabajador</flux:button></div>
        </form>
    </flux:modal>
</div>
