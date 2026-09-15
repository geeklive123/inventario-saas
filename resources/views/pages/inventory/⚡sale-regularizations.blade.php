<?php

use App\Enums\SaleInventoryPendingStatus;
use App\Models\Branch;
use App\Models\Sale;
use App\Models\SaleInventoryPending;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Pendientes de regularización')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public ?int $branchId = null;

    public ?int $warehouseId = null;

    public string $status = 'pending';

    public function mount(): void
    {
        Gate::authorize('viewAny', SaleInventoryPending::class);
    }

    public function updatedSearch(): void { $this->resetPage(); }

    public function updatedBranchId(): void
    {
        $this->warehouseId = null;
        $this->resetPage();
        unset($this->warehouses);
    }

    public function updatedWarehouseId(): void { $this->resetPage(); }

    public function updatedStatus(): void { $this->resetPage(); }

    public function formatQuantity(int|float|string $quantity): string
    {
        return Number::format((float) $quantity, maxPrecision: 6, locale: 'es');
    }

    #[Computed]
    public function branches(): Collection
    {
        return Branch::query()->where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function warehouses(): Collection
    {
        return Warehouse::query()->with('branch:id,name')->where('is_active', true)
            ->when($this->branchId, fn (Builder $query) => $query->where('branch_id', $this->branchId))
            ->orderBy('name')->get();
    }

    #[Computed]
    public function pendingSalesCount(): int
    {
        return Sale::query()->whereHas('inventoryPendings', fn (Builder $query) => $query
            ->where('status', SaleInventoryPendingStatus::Pending))->count();
    }

    #[Computed]
    public function sales(): LengthAwarePaginator
    {
        $status = SaleInventoryPendingStatus::tryFrom($this->status);

        return Sale::query()
            ->with([
                'branch:id,name',
                'inventoryPendings' => fn ($query) => $query
                    ->when($status, fn ($pendingQuery) => $pendingQuery->where('status', $status))
                    ->with('saleItem:id,product_name'),
            ])
            ->whereHas('inventoryPendings', fn (Builder $query) => $query
                ->when($status, fn (Builder $pendingQuery) => $pendingQuery->where('status', $status)))
            ->when($this->search, fn (Builder $query) => $query->where('number', 'like', '%'.$this->search.'%'))
            ->when($this->branchId, fn (Builder $query) => $query->where('branch_id', $this->branchId))
            ->when($this->warehouseId, fn (Builder $query) => $query->where('warehouse_id', $this->warehouseId))
            ->latest('occurred_at')
            ->paginate(15);
    }
};
?>

<div class="flex w-full flex-col gap-6">
    <div class="flex flex-col justify-between gap-3 md:flex-row md:items-center">
        <div>
            <flux:heading size="xl">Pendientes de regularización</flux:heading>
            <flux:text>Registra qué insumos se utilizaron realmente en ventas confirmadas sin inventario suficiente.</flux:text>
        </div>
        <flux:badge color="amber" size="lg">{{ $this->pendingSalesCount }} ventas pendientes</flux:badge>
    </div>

    <flux:card class="grid gap-4 md:grid-cols-4">
        <flux:input wire:model.live.debounce.300ms="search" label="Buscar venta" icon="magnifying-glass" placeholder="Ej.: V-000105" />
        <flux:select wire:model.live="branchId" label="Sucursal"><flux:select.option value="">Todas</flux:select.option>@foreach($this->branches as $branch)<flux:select.option :value="$branch->id">{{ $branch->name }}</flux:select.option>@endforeach</flux:select>
        <flux:select wire:model.live="warehouseId" label="Almacén"><flux:select.option value="">Todos</flux:select.option>@foreach($this->warehouses as $warehouse)<flux:select.option :value="$warehouse->id">{{ $warehouse->branch->name }} · {{ $warehouse->name }}</flux:select.option>@endforeach</flux:select>
        <flux:select wire:model.live="status" label="Estado"><flux:select.option value="pending">Pendientes</flux:select.option><flux:select.option value="completed">Completados</flux:select.option><flux:select.option value="cancelled">Cancelados</flux:select.option><flux:select.option value="">Todos</flux:select.option></flux:select>
    </flux:card>

    <div class="grid gap-4">
        @forelse($this->sales as $sale)
            <flux:card wire:key="pending-sale-{{ $sale->id }}" class="space-y-4">
                <div class="flex flex-col justify-between gap-3 md:flex-row md:items-start">
                    <div><flux:heading size="lg">Venta {{ $sale->number }}</flux:heading><flux:text>{{ $sale->occurred_at->timezone(app(\App\Support\Tenancy\CurrentCompany::class)->company()->timezone)->format('d/m/Y H:i') }} · {{ $sale->customer_name ?: 'Consumidor final' }} · {{ $sale->branch_name }}</flux:text></div>
                    <flux:badge :color="$sale->inventory_status->color()">{{ $sale->inventory_status->label() }}</flux:badge>
                </div>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach($sale->inventoryPendings as $pending)
                        <div class="flex items-center justify-between gap-3 rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800/60" wire:key="pending-{{ $pending->id }}">
                            <div><strong>{{ $pending->original_component_name }}</strong><flux:text size="sm">{{ $pending->saleItem->product_name }} · {{ $this->formatQuantity($pending->required_quantity) }} {{ $pending->unit_symbol }}</flux:text></div>
                            @if($pending->status === SaleInventoryPendingStatus::Pending)<flux:button size="sm" variant="primary" :href="route('inventory.regularizations.show', $pending->id)" wire:navigate>Regularizar</flux:button>@elseif($pending->status === SaleInventoryPendingStatus::Completed)<flux:badge color="green">Completado</flux:badge>@else<flux:badge color="zinc">Cancelado</flux:badge>@endif
                        </div>
                    @endforeach
                </div>
            </flux:card>
        @empty
            <flux:card><div class="py-12 text-center"><flux:heading>Sin resultados</flux:heading><flux:text>No hay ventas con el estado seleccionado.</flux:text></div></flux:card>
        @endforelse
    </div>

    {{ $this->sales->links() }}
</div>
