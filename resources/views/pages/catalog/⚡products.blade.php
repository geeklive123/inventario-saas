<?php

use App\Enums\InventoryBehavior;
use App\Enums\ProductItemType;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Support\Tenancy\CurrentCompany;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Productos')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $kindFilter = '';

    public string $statusFilter = '';

    public ?int $editingId = null;

    public string $registrationKind = 'product';

    public string $name = '';

    public string $sku = '';

    public ?string $barcode = null;

    public ?int $categoryId = null;

    public ?int $unitId = null;

    public string $description = '';

    public string $salePriceBase = '0';

    public ?string $fallbackUnitCostBase = null;

    public bool $isSellable = true;

    public bool $isActive = true;

    public bool $showAdvanced = false;

    public bool $showProductForm = false;

    public function mount(): void
    {
        $requestedKind = match (request()->string('crear')->toString()) {
            'insumo' => 'supply',
            'preparado' => 'prepared',
            'servicio' => 'service',
            default => 'product',
        };

        if (request()->has('crear') && Gate::allows('create', Product::class)) {
            $this->resetForm();
            $this->registrationKind = $requestedKind;
            $this->updatedRegistrationKind($requestedKind);
            $this->showProductForm = true;
        }
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedKindFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedRegistrationKind(string $value): void
    {
        if ($value === 'supply') {
            $this->isSellable = false;
        } elseif (in_array($value, ['product', 'prepared', 'service'], true)) {
            $this->isSellable = true;
        }

        if ($value === 'service') {
            $this->fallbackUnitCostBase = null;
        }
    }

    public function create(string $kind = 'product'): void
    {
        Gate::authorize('create', Product::class);
        $this->resetForm();
        $this->registrationKind = in_array($kind, ['product', 'supply', 'prepared', 'service'], true) ? $kind : 'product';
        $this->updatedRegistrationKind($this->registrationKind);
        $this->showProductForm = true;
        Flux::modal('product-form')->show();
    }

    public function edit(int $productId): void
    {
        $product = Product::query()->findOrFail($productId);
        Gate::authorize('update', $product);

        $this->editingId = $product->getKey();
        $this->registrationKind = $this->registrationKindFor($product);
        $this->name = $product->name;
        $this->sku = $product->sku;
        $this->barcode = $product->barcode;
        $this->categoryId = $product->category_id;
        $this->unitId = $product->unit_id;
        $this->description = $product->description ?? '';
        $this->salePriceBase = $product->sale_price_base;
        $this->fallbackUnitCostBase = $product->fallback_unit_cost_base;
        $this->isSellable = $product->is_sellable;
        $this->isActive = $product->is_active;
        $this->showAdvanced = filled($this->barcode) || filled($this->description) || filled($this->fallbackUnitCostBase);
        $this->showProductForm = true;
        Flux::modal('product-form')->show();
    }

    public function save(): void
    {
        $companyId = app(CurrentCompany::class)->id();
        $product = $this->editingId ? Product::query()->findOrFail($this->editingId) : null;
        Gate::authorize($product ? 'update' : 'create', $product ?? Product::class);

        $validated = $this->validate([
            'registrationKind' => ['required', Rule::in(['product', 'supply', 'prepared', 'service'])],
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:100', Rule::unique('products', 'sku')->where('company_id', $companyId)->ignore($product)],
            'barcode' => ['nullable', 'string', 'max:100', Rule::unique('products', 'barcode')->where('company_id', $companyId)->ignore($product)],
            'categoryId' => ['nullable', Rule::exists('categories', 'id')->where('company_id', $companyId)],
            'unitId' => ['required', Rule::exists('units', 'id')->where('company_id', $companyId)],
            'description' => ['nullable', 'string'],
            'salePriceBase' => ['required', 'numeric', 'min:0'],
            'fallbackUnitCostBase' => ['nullable', 'numeric', 'min:0'],
            'isSellable' => ['boolean'],
            'isActive' => ['boolean'],
        ], messages: [
            'registrationKind.in' => 'Selecciona qué quieres registrar.',
            'sku.unique' => 'El SKU ya está siendo utilizado en esta empresa.',
            'barcode.unique' => 'El código de barras ya está siendo utilizado en esta empresa.',
            'unitId.required' => 'Selecciona una unidad.',
        ]);

        [$itemType, $inventoryBehavior] = $this->domainConfigurationFor($validated['registrationKind']);
        $isSupply = $validated['registrationKind'] === 'supply';
        $isNewPreparedProduct = $product === null && $validated['registrationKind'] === 'prepared';

        $savedProduct = Product::query()->updateOrCreate(['id' => $product?->getKey()], [
            'company_id' => $companyId,
            'name' => $validated['name'],
            'sku' => $validated['sku'],
            'barcode' => filled($validated['barcode']) ? $validated['barcode'] : null,
            'category_id' => $validated['categoryId'],
            'unit_id' => $validated['unitId'],
            'description' => filled($validated['description']) ? $validated['description'] : null,
            'sale_price_base' => $isSupply ? 0 : $validated['salePriceBase'],
            'fallback_unit_cost_base' => $itemType === ProductItemType::Service ? null : (filled($validated['fallbackUnitCostBase']) ? $validated['fallbackUnitCostBase'] : null),
            'item_type' => $itemType,
            'inventory_behavior' => $inventoryBehavior,
            'is_sellable' => $isSupply ? false : $validated['isSellable'],
            'is_active' => $validated['isActive'],
        ]);

        Flux::modal('product-form')->close();
        $this->showProductForm = false;
        $this->resetForm();

        if ($isNewPreparedProduct) {
            session()->flash('success', 'Producto preparado creado. Ahora indica qué necesitas para prepararlo.');
            $this->redirectRoute('catalog.recipes', ['producto' => $savedProduct->getKey()], navigate: true);

            return;
        }

        Flux::toast(variant: 'success', text: $product ? 'Producto actualizado.' : 'Producto creado.');
    }

    public function toggleStatus(int $productId): void
    {
        $product = Product::query()->findOrFail($productId);
        Gate::authorize($product->is_active ? 'deactivate' : 'update', $product);
        $product->update(['is_active' => ! $product->is_active]);
        Flux::toast(variant: 'success', text: $product->is_active ? 'Producto activado.' : 'Producto desactivado.');
    }

    #[Computed]
    public function products(): LengthAwarePaginator
    {
        return Product::query()->with(['category:id,name', 'unit:id,name,symbol'])
            ->when($this->search, fn ($query) => $query->where(fn ($nested) => $nested
                ->where('name', 'like', '%'.$this->search.'%')
                ->orWhere('sku', 'like', '%'.$this->search.'%')
                ->orWhere('barcode', 'like', '%'.$this->search.'%')))
            ->when($this->kindFilter === 'stocked', fn ($query) => $query
                ->where('item_type', ProductItemType::Physical)
                ->where('inventory_behavior', InventoryBehavior::Self))
            ->when($this->kindFilter === 'prepared', fn ($query) => $query
                ->where('item_type', ProductItemType::Physical)
                ->where('inventory_behavior', InventoryBehavior::Components))
            ->when($this->kindFilter === 'service', fn ($query) => $query->where('item_type', ProductItemType::Service))
            ->when($this->statusFilter !== '', fn ($query) => $query->where('is_active', $this->statusFilter === 'active'))
            ->latest()
            ->paginate(12);
    }

    #[Computed]
    public function categories()
    {
        return Category::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function units()
    {
        return Unit::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'symbol']);
    }

    /** @return array{ProductItemType, InventoryBehavior} */
    private function domainConfigurationFor(string $registrationKind): array
    {
        return match ($registrationKind) {
            'service' => [ProductItemType::Service, InventoryBehavior::None],
            'prepared' => [ProductItemType::Physical, InventoryBehavior::Components],
            default => [ProductItemType::Physical, InventoryBehavior::Self],
        };
    }

    private function registrationKindFor(Product $product): string
    {
        if ($product->item_type === ProductItemType::Service) {
            return 'service';
        }

        if ($product->inventory_behavior === InventoryBehavior::Components) {
            return 'prepared';
        }

        return $product->is_sellable ? 'product' : 'supply';
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'sku', 'barcode', 'categoryId', 'unitId', 'description', 'fallbackUnitCostBase']);
        $this->registrationKind = 'product';
        $this->salePriceBase = '0';
        $this->isSellable = true;
        $this->isActive = true;
        $this->showAdvanced = false;
        $this->resetValidation();
    }
}; ?>

@php($currency = app(CurrentCompany::class)->company()->baseCurrency)

<div class="flex w-full flex-col gap-6">
    <div class="flex flex-col justify-between gap-4 md:flex-row md:items-center">
        <div>
            <flux:heading size="xl">Productos</flux:heading>
            <flux:text>Organiza lo que vendes y los insumos que utilizas.</flux:text>
        </div>
        @can('create', App\Models\Product::class)
            <flux:button variant="primary" icon="plus" wire:click="create">Nuevo registro</flux:button>
        @endcan
    </div>

    <flux:card class="grid gap-4 md:grid-cols-3">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Buscar por nombre, SKU o código de barras" />
        <flux:select wire:model.live="kindFilter" label="Qué muestra">
            <flux:select.option value="">Todo el catálogo</flux:select.option>
            <flux:select.option value="stocked">Productos e insumos</flux:select.option>
            <flux:select.option value="prepared">Productos preparados</flux:select.option>
            <flux:select.option value="service">Servicios</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="statusFilter" label="Estado">
            <flux:select.option value="">Todos</flux:select.option>
            <flux:select.option value="active">Activos</flux:select.option>
            <flux:select.option value="inactive">Inactivos</flux:select.option>
        </flux:select>
    </flux:card>

    <flux:card class="overflow-hidden p-0!">
        <div class="overflow-x-auto">
            <flux:table :paginate="$this->products">
                <flux:table.columns>
                    <flux:table.column>Nombre</flux:table.column>
                    <flux:table.column>Categoría / Unidad</flux:table.column>
                    <flux:table.column>Cómo se usa</flux:table.column>
                    <flux:table.column>Precio</flux:table.column>
                    <flux:table.column>Estado</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->products as $product)
                        <flux:table.row :key="$product->id">
                            <flux:table.cell variant="strong">
                                <div>{{ $product->name }}</div>
                                <flux:text size="sm">{{ $product->sku }}{{ $product->barcode ? ' · '.$product->barcode : '' }}</flux:text>
                            </flux:table.cell>
                            <flux:table.cell>
                                {{ $product->category?->name ?? 'Sin categoría' }}
                                <flux:text size="sm">{{ $product->unit->name }} ({{ $product->unit->symbol }})</flux:text>
                            </flux:table.cell>
                            <flux:table.cell>
                                {{ match (true) {
                                    $product->item_type === ProductItemType::Service => 'Servicio',
                                    $product->inventory_behavior === InventoryBehavior::Components => 'Producto preparado',
                                    ! $product->is_sellable => 'Insumo',
                                    default => 'Producto',
                                } }}
                            </flux:table.cell>
                            <flux:table.cell>{{ $currency->symbol }} {{ number_format((float) $product->sale_price_base, $currency->decimal_places) }}</flux:table.cell>
                            <flux:table.cell><flux:badge :color="$product->is_active ? 'green' : 'zinc'">{{ $product->is_active ? 'Activo' : 'Inactivo' }}</flux:badge></flux:table.cell>
                            <flux:table.cell>
                                <div class="flex justify-end gap-2">
                                    <flux:button size="sm" variant="subtle" wire:click="edit({{ $product->id }})">Editar</flux:button>
                                    @if ($product->inventory_behavior === InventoryBehavior::Components)
                                        <flux:button size="sm" variant="subtle" :href="route('catalog.recipes', ['producto' => $product->id])" wire:navigate>Receta</flux:button>
                                    @endif
                                    <flux:button size="sm" variant="subtle" wire:click="toggleStatus({{ $product->id }})" wire:confirm="¿Confirmas el cambio de estado?">{{ $product->is_active ? 'Desactivar' : 'Activar' }}</flux:button>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="6">
                                <div class="py-12 text-center">
                                    <flux:heading>Tu catálogo está vacío</flux:heading>
                                    <flux:text class="mt-1">Registra tu primer producto, insumo, preparado o servicio.</flux:text>
                                    @can('create', App\Models\Product::class)
                                        <flux:button class="mt-4" variant="primary" wire:click="create">Crear el primero</flux:button>
                                    @endcan
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    </flux:card>

    <flux:modal name="product-form" wire:model.self="showProductForm" class="max-w-4xl">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingId ? 'Editar registro' : 'Nuevo registro' }}</flux:heading>
                <flux:text>Completa primero los datos esenciales. Puedes ampliar la información cuando la necesites.</flux:text>
            </div>

            <fieldset class="space-y-3">
                <legend class="text-sm font-medium text-zinc-800 dark:text-zinc-200">¿Qué quieres registrar?</legend>
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ([
                        ['product', 'Producto', 'Algo que compras o produces y vendes por sí mismo.', 'cube'],
                        ['supply', 'Insumo', 'Material que controlas y utilizas para preparar ramos.', 'archive-box'],
                        ['prepared', 'Producto preparado o compuesto', 'Se prepara usando otros productos o insumos.', 'beaker'],
                        ['service', 'Servicio', 'No controla cantidades en almacén.', 'wrench-screwdriver'],
                    ] as [$value, $label, $help, $icon])
                        <label wire:key="registration-kind-{{ $value }}" class="cursor-pointer rounded-xl border p-4 transition {{ $registrationKind === $value ? 'border-blue-500 bg-blue-50 ring-1 ring-blue-500 dark:bg-blue-950/30' : 'border-zinc-200 hover:border-zinc-400 dark:border-zinc-700' }}">
                            <input class="sr-only" type="radio" wire:model.live="registrationKind" value="{{ $value }}">
                            <span class="flex items-start gap-3">
                                <flux:icon :name="$icon" class="mt-0.5 size-5 shrink-0" />
                                <span>
                                    <span class="block font-medium">{{ $label }}</span>
                                    <span class="mt-1 block text-sm text-zinc-500 dark:text-zinc-400">{{ $help }}</span>
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>
                @error('registrationKind')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
            </fieldset>

            <div class="grid gap-4 md:grid-cols-2">
                <flux:input wire:model="name" label="Nombre" required />
                <flux:input wire:model="sku" label="SKU" description="Código interno para identificarlo." required />
                <flux:select wire:model="categoryId" label="Categoría">
                    <flux:select.option value="">Sin categoría</flux:select.option>
                    @foreach ($this->categories as $category)<flux:select.option :value="$category->id">{{ $category->name }}</flux:select.option>@endforeach
                </flux:select>
                <flux:select wire:model="unitId" label="Unidad" required>
                    <flux:select.option value="">Seleccionar</flux:select.option>
                    @foreach ($this->units as $unit)<flux:select.option :value="$unit->id">{{ $unit->name }} ({{ $unit->symbol }})</flux:select.option>@endforeach
                </flux:select>
                @if ($registrationKind !== 'supply')
                    <flux:input wire:model="salePriceBase" type="number" step="0.0001" min="0" label="Precio de venta ({{ $currency->code }})" />
                @endif
                <div class="flex flex-wrap items-center gap-6 pt-7">
                    @if ($registrationKind !== 'supply')
                        <flux:switch wire:model="isSellable" label="Se puede vender" />
                    @endif
                    <flux:switch wire:model="isActive" label="Activo" />
                </div>
            </div>

            @if ($registrationKind === 'prepared')
                <flux:callout icon="information-circle" heading="El siguiente paso será crear su receta">
                    Después de guardar, te llevaremos a indicar los productos e insumos necesarios para prepararlo.
                </flux:callout>
            @endif

            <div>
                <flux:button type="button" variant="subtle" icon="adjustments-horizontal" wire:click="$toggle('showAdvanced')">
                    {{ $showAdvanced ? 'Ocultar opciones avanzadas' : 'Mostrar opciones avanzadas' }}
                </flux:button>
            </div>

            @if ($showAdvanced)
                <div class="grid gap-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700 md:grid-cols-2">
                    <flux:input wire:model="barcode" label="Código de barras" />
                    @if ($registrationKind !== 'service')
                        <flux:input wire:model="fallbackUnitCostBase" type="number" step="0.0001" min="0" label="Costo de referencia ({{ $currency->code }})" description="Valor orientativo. No modifica la existencia, el costo promedio ni el valor real del inventario." />
                    @endif
                    <flux:textarea class="md:col-span-2" wire:model="description" label="Descripción" rows="3" />
                </div>
            @endif

            <div class="flex justify-end gap-3">
                <flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ $editingId ? 'Guardar cambios' : 'Crear registro' }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
