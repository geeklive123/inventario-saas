<?php

use App\Enums\InventoryBehavior;
use App\Enums\ProductItemType;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Catalog\BouquetCostCalculator;
use App\Support\Authorization\CompanyAccess;
use App\Support\Tenancy\CurrentCompany;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Ramos')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $name = '';

    public string $sku = '';

    public string $salePriceBase = '0';

    public string $description = '';

    public ?int $unitId = null;

    public ?int $warehouseId = null;

    public ?int $editingId = null;

    public bool $isActive = true;

    public function mount(): void
    {
        if (! $this->canViewCosts()) {
            return;
        }

        $requestedWarehouseId = session('current_warehouse_id');
        $this->warehouseId = Warehouse::query()->whereKey($requestedWarehouseId)->where('is_active', true)->value('id')
            ?? Warehouse::query()->where('is_active', true)->orderBy('name')->value('id');

        if ($this->warehouseId !== null) {
            session()->put('current_warehouse_id', $this->warehouseId);
        }
    }

    public function updatedWarehouseId(): void
    {
        abort_unless($this->canViewCosts(), 403);
        $warehouse = Warehouse::query()->whereKey($this->warehouseId)->where('is_active', true)->firstOrFail();
        session()->put('current_warehouse_id', $warehouse->getKey());
    }

    public function create(): void
    {
        Gate::authorize('create', Product::class);
        $this->resetForm();
        Flux::modal('bouquet-form')->show();
    }

    public function edit(int $bouquetId): void
    {
        $bouquet = Product::query()->composed()->sellable()->findOrFail($bouquetId);
        Gate::authorize('update', $bouquet);
        $this->editingId = $bouquet->getKey();
        $this->name = $bouquet->name;
        $this->sku = $bouquet->sku;
        $this->salePriceBase = $bouquet->sale_price_base;
        $this->description = $bouquet->description ?? '';
        $this->unitId = $bouquet->unit_id;
        $this->isActive = $bouquet->is_active;
        Flux::modal('bouquet-form')->show();
    }

    public function save(): void
    {
        $companyId = app(CurrentCompany::class)->id();
        $bouquet = $this->editingId ? Product::query()->composed()->sellable()->findOrFail($this->editingId) : null;
        $bouquet ? Gate::authorize('update', $bouquet) : Gate::authorize('create', Product::class);
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:100', Rule::unique('products', 'sku')->where('company_id', $companyId)->ignore($bouquet?->getKey())],
            'salePriceBase' => ['required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'unitId' => ['required', Rule::exists('units', 'id')->where('company_id', $companyId)],
            'isActive' => ['boolean'],
        ]);
        if ($bouquet && bccomp($bouquet->sale_price_base, (string) $data['salePriceBase'], 4) !== 0) {
            Gate::authorize('updateBouquetPrice', $bouquet);
        }

        $attributes = [
            'company_id' => $companyId,
            'unit_id' => $data['unitId'],
            'name' => $data['name'],
            'sku' => $data['sku'],
            'sale_price_base' => $data['salePriceBase'],
            'description' => $data['description'] ?: null,
            'item_type' => ProductItemType::Physical,
            'inventory_behavior' => InventoryBehavior::Components,
            'is_sellable' => true,
            'is_active' => $data['isActive'],
        ];
        $bouquet ? $bouquet->update($attributes) : $bouquet = Product::query()->create($attributes);

        Flux::modal('bouquet-form')->close();
        if ($this->editingId) {
            Flux::toast('Ramo actualizado.', variant: 'success');
            $this->resetForm();
        } else {
            session()->flash('success', 'Ramo creado. Ahora indica qué utiliza.');
            $this->redirectRoute('catalog.recipes', ['producto' => $bouquet->getKey()], navigate: true);
        }
    }

    public function formatMoney(int|float|string $amount): string
    {
        $decimalPlaces = app(CurrentCompany::class)->company()->baseCurrency->decimal_places;

        return Number::format((float) $amount, precision: $decimalPlaces, locale: 'es');
    }

    #[Computed]
    public function bouquets(): LengthAwarePaginator
    {
        return Product::query()
            ->withExists(['recipes as has_active_recipe' => fn ($query) => $query->where('active_slot', 1)])
            ->composed()
            ->sellable()
            ->when($this->search, fn ($query) => $query->where(
                fn ($nested) => $nested->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('sku', 'like', '%'.$this->search.'%'),
            ))
            ->latest()
            ->paginate(15);
    }

    /** @return array<int, array{cost_base: numeric-string, complete: bool, has_recipe: bool, uses_fallback: bool, missing_costs: list<string>}> */
    #[Computed]
    public function bouquetCosts(): array
    {
        if (! $this->canViewCosts() || $this->warehouseId === null) {
            return [];
        }

        $warehouse = Warehouse::query()->whereKey($this->warehouseId)->where('is_active', true)->firstOrFail();

        return app(BouquetCostCalculator::class)->calculate($this->bouquets->getCollection(), $warehouse);
    }

    #[Computed]
    public function warehouses(): Collection
    {
        if (! $this->canViewCosts()) {
            return new Collection;
        }

        return Warehouse::query()->with('branch:id,name')->where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function units(): Collection
    {
        return Unit::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'symbol']);
    }

    private function canViewCosts(): bool
    {
        $user = Auth::user();

        return $user instanceof User
            && app(CompanyAccess::class)->allowsCurrent($user, 'inventory.view', mutation: false);
    }

    private function resetForm(): void
    {
        $this->reset(['name', 'sku', 'description', 'unitId', 'editingId']);
        $this->salePriceBase = '0';
        $this->isActive = true;
        $this->resetValidation();
    }
}; ?>

@php($currency = app(CurrentCompany::class)->company()->baseCurrency)

<div class="flex w-full flex-col gap-6">
    <div class="flex flex-col justify-between gap-4 md:flex-row md:items-center">
        <div>
            <flux:heading size="xl">Ramos</flux:heading>
            <flux:text>Crea arreglos vendibles y calcula su costo desde los insumos.</flux:text>
        </div>
        @can('create', App\Models\Product::class)
            <flux:button variant="primary" icon="plus" wire:click="create">Nuevo ramo</flux:button>
        @endcan
    </div>

    <div class="grid gap-4 md:grid-cols-2">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Buscar ramo o SKU" />
        @if ($this->warehouses->isNotEmpty())
            <flux:select wire:model.live="warehouseId" label="Costos del almacén">
                @foreach ($this->warehouses as $warehouse)
                    <flux:select.option :value="$warehouse->id">{{ $warehouse->branch->name }} · {{ $warehouse->name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
    </div>

    @if ($this->warehouses->isEmpty() && $this->bouquets->isNotEmpty())
        <flux:callout icon="information-circle" heading="Configura un almacén para calcular costos">El costo promedio de los insumos se mantiene por almacén.</flux:callout>
    @endif

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($this->bouquets as $bouquet)
            @php($cost = $this->bouquetCosts[$bouquet->id] ?? null)
            @php($margin = $cost && $cost['complete'] ? bcsub($bouquet->sale_price_base, $cost['cost_base'], 4) : null)
            <flux:card wire:key="bouquet-{{ $bouquet->id }}">
                <div class="flex justify-between gap-4">
                    <div>
                        <flux:heading>{{ $bouquet->name }}</flux:heading>
                        <flux:text>{{ $bouquet->sku }}</flux:text>
                    </div>
                    <flux:badge :color="$bouquet->is_active ? 'green' : 'zinc'">{{ $bouquet->is_active ? 'Activo' : 'Inactivo' }}</flux:badge>
                </div>

                <flux:text class="mt-4">{{ $bouquet->description ?: 'Sin descripción' }}</flux:text>

                <div class="mt-5 grid grid-cols-2 gap-3 rounded-xl bg-zinc-50 p-4 dark:bg-zinc-800/60">
                    <div>
                        <flux:text size="sm">Precio de venta</flux:text>
                        <div class="font-semibold">{{ $currency->symbol }} {{ $this->formatMoney($bouquet->sale_price_base) }}</div>
                    </div>
                    <div>
                        <flux:text size="sm">Costo del ramo</flux:text>
                        <div class="font-semibold">{{ $cost && $cost['complete'] ? $currency->symbol.' '.$this->formatMoney($cost['cost_base']) : 'No calculado' }}</div>
                    </div>
                    @if ($margin !== null)
                        <div class="col-span-2 border-t border-zinc-200 pt-3 dark:border-zinc-700">
                            <flux:text size="sm">Margen bruto estimado</flux:text>
                            <div class="font-semibold {{ bccomp($margin, '0', 4) < 0 ? 'text-red-600 dark:text-red-400' : 'text-green-700 dark:text-green-400' }}">{{ $currency->symbol }} {{ $this->formatMoney($margin) }}</div>
                        </div>
                    @elseif ($cost && $cost['has_recipe'] && $cost['missing_costs'] !== [])
                        <flux:callout class="col-span-2" icon="exclamation-triangle" heading="Falta costo en algunos insumos">Registra una entrada o costo de referencia para: {{ implode(', ', $cost['missing_costs']) }}. El ramo puede mantenerse a la venta, pero el margen no se calculará hasta completar estos costos.</flux:callout>
                    @endif
                    @if ($cost && $cost['complete'] && $cost['uses_fallback'])
                        <div class="col-span-2"><flux:badge color="amber">Incluye costos de referencia</flux:badge></div>
                    @endif
                </div>

                <div class="mt-5 flex justify-end gap-2">
                    @can('update', $bouquet)<flux:button variant="ghost" wire:click="edit({{ $bouquet->id }})">Editar datos y precio</flux:button>@endcan
                    <flux:button :href="route('catalog.recipes', ['producto' => $bouquet->id])" wire:navigate>{{ $bouquet->has_active_recipe ? 'Ver o cambiar receta' : 'Indicar qué utiliza' }}</flux:button>
                </div>
            </flux:card>
        @empty
            <flux:card class="md:col-span-2 xl:col-span-3">
                <div class="py-12 text-center">
                    <flux:heading>No hay ramos</flux:heading>
                    <flux:text>Crea Ramo Amor y después agrega rosas, tulipanes, papel y cinta.</flux:text>
                </div>
            </flux:card>
        @endforelse
    </div>

    {{ $this->bouquets->links() }}

    <flux:modal name="bouquet-form" class="max-w-2xl">
        <form wire:submit="save" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $editingId ? 'Editar ramo' : 'Nuevo ramo' }}</flux:heading>
                <flux:text>{{ $editingId ? 'Actualiza sus datos comerciales. La receta se administra por separado.' : 'Después de guardarlo indicarás qué insumos utiliza.' }}</flux:text>
            </div>
            <div class="grid gap-4 md:grid-cols-2">
                <flux:input wire:model="name" label="Nombre del ramo" required />
                <flux:input wire:model="sku" label="SKU" required />
                <flux:select wire:model="unitId" label="Unidad" required>
                    <flux:select.option value="">Seleccionar</flux:select.option>
                    @foreach ($this->units as $unit)
                        <flux:select.option :value="$unit->id">{{ $unit->name }} ({{ $unit->symbol }})</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="salePriceBase" type="number" step="0.0001" min="0" label="Precio de venta ({{ $currency->code }})" />
            </div>
            <flux:textarea wire:model="description" label="Descripción" />
            @if($editingId)<flux:switch wire:model="isActive" label="Ramo activo" description="Los ramos inactivos dejan de aparecer para nuevas ventas." />@endif
            <div class="flex justify-end gap-3">
                <flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ $editingId ? 'Guardar cambios' : 'Crear y configurar receta' }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
