<?php

use App\Actions\Memberships\ChangeMembershipStatus;
use App\Actions\Users\CreateWorker;
use App\Actions\Users\UpdateWorker;
use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
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
    public ?int $roleId = null;

    public function create(): void { Gate::authorize('create', Membership::class); $this->resetForm(); Flux::modal('worker-form')->show(); }
    public function edit(int $id): void
    {
        $membership = Membership::query()->with(['user', 'roles'])->findOrFail($id); Gate::authorize('update', $membership);
        $this->editingId = $membership->getKey(); $this->name = $membership->user->name; $this->email = $membership->user->email; $this->roleId = $membership->roles->first()?->getKey(); Flux::modal('worker-form')->show();
    }
    public function save(): void
    {
        $membership = $this->editingId ? Membership::query()->with('user')->findOrFail($this->editingId) : null;
        Gate::authorize($membership ? 'update' : 'create', $membership ?? Membership::class);
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($membership?->user_id)],
            'temporaryPassword' => [$membership ? 'nullable' : 'required', 'string', Password::defaults()],
            'roleId' => ['required', Rule::exists('roles', 'id')->where('company_id', app(CurrentCompany::class)->id())->where('is_active', true)],
        ]);
        $role = Role::query()->findOrFail($data['roleId']);
        try {
            if ($membership) { app(UpdateWorker::class)->handle(app(CurrentCompany::class)->membership(), $membership, $role, $data); }
            else { app(CreateWorker::class)->handle(app(CurrentCompany::class)->membership(), $role, ['name'=>$data['name'],'email'=>$data['email'],'password'=>$data['temporaryPassword']]); }
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
    #[Computed]
    public function roles(): Collection
    {
        return Role::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }
    private function resetForm(): void { $this->reset(['editingId','name','email','temporaryPassword','roleId']); $this->resetValidation(); }
}; ?>
<div class="flex w-full flex-col gap-6"><div class="flex flex-col justify-between gap-4 md:flex-row md:items-center"><div><flux:heading size="xl">Usuarios</flux:heading><flux:text>Crea trabajadores y controla su acceso mediante roles y permisos.</flux:text></div>@can('create', App\Models\Membership::class)<flux:button variant="primary" icon="user-plus" wire:click="create">Crear trabajador</flux:button>@endcan</div><flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Buscar por nombre o correo" class="max-w-md" /><flux:card class="overflow-hidden p-0!"><div class="overflow-x-auto"><flux:table :paginate="$this->workers"><flux:table.columns><flux:table.column>Trabajador</flux:table.column><flux:table.column>Rol</flux:table.column><flux:table.column>Contraseña</flux:table.column><flux:table.column>Estado</flux:table.column><flux:table.column></flux:table.column></flux:table.columns><flux:table.rows>@forelse($this->workers as $worker)<flux:table.row :key="$worker->id"><flux:table.cell variant="strong">{{ $worker->user->name }}<flux:text size="sm">{{ $worker->user->email }}</flux:text></flux:table.cell><flux:table.cell>{{ $worker->roles->pluck('name')->join(', ') ?: 'Sin rol' }}</flux:table.cell><flux:table.cell>{{ $worker->user->must_change_password ? 'Cambio pendiente' : 'Personal' }}</flux:table.cell><flux:table.cell><flux:badge :color="$worker->status===MembershipStatus::Active ? 'green':'zinc'">{{ $worker->status===MembershipStatus::Active ? 'Activo':'Suspendido' }}</flux:badge></flux:table.cell><flux:table.cell><div class="flex justify-end gap-2"><flux:button size="sm" variant="subtle" wire:click="edit({{ $worker->id }})">Editar</flux:button><flux:button size="sm" variant="subtle" wire:click="toggleStatus({{ $worker->id }})" wire:confirm="¿Confirmas este cambio de estado?">{{ $worker->status===MembershipStatus::Active ? 'Suspender':'Reactivar' }}</flux:button></div></flux:table.cell></flux:table.row>@empty<flux:table.row><flux:table.cell colspan="5"><div class="py-12 text-center"><flux:heading>No hay trabajadores</flux:heading><flux:text>Crea el primer usuario operativo de la florería.</flux:text></div></flux:table.cell></flux:table.row>@endforelse</flux:table.rows></flux:table></div></flux:card><flux:modal name="worker-form" class="max-w-xl"><form wire:submit="save" class="space-y-5"><div><flux:heading size="lg">{{ $editingId ? 'Editar trabajador':'Crear trabajador' }}</flux:heading>@if(!$editingId)<flux:text>Entregaremos acceso con una contraseña temporal que deberá cambiar al iniciar sesión.</flux:text>@endif</div><flux:input wire:model="name" label="Nombre" required /><flux:input wire:model="email" type="email" label="Correo" required />@if(!$editingId)<flux:input wire:model="temporaryPassword" type="password" label="Contraseña temporal" viewable required />@endif<flux:select wire:model="roleId" label="Rol" required><flux:select.option value="">Seleccionar</flux:select.option>@foreach($this->roles as $role)<flux:select.option :value="$role->id">{{ $role->name }}</flux:select.option>@endforeach</flux:select><div class="flex justify-end gap-3"><flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close><flux:button type="submit" variant="primary">Guardar trabajador</flux:button></div></form></flux:modal></div>
