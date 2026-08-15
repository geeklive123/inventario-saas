<?php

use App\Enums\InventoryBehavior;
use App\Enums\ProductItemType;
use App\Models\Category;
use App\Models\Product;
use App\Models\Unit;
use App\Support\Tenancy\CurrentCompany;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Insumos')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public ?int $editingId = null;

    public string $name = '';

    public string $sku = '';

    public string $description = '';

    public ?int $categoryId = null;

    public ?int $unitId = null;

    public ?string $estimatedCostBase = null;

    public bool $isActive = true;

    public function create(): void
    {
        Gate::authorize('create', Product::class);
        $this->resetForm();
        Flux::modal('supply-form')->show();
    }

    public function edit(int $id): void
    {
        $product = Product::query()
            ->whereKey($id)
            ->where('item_type', ProductItemType::Physical)
            ->where('inventory_behavior', InventoryBehavior::Self)
            ->firstOrFail();

        Gate::authorize('update', $product);
        $this->editingId = $product->getKey();
        $this->name = $product->name;
        $this->sku = $product->sku;
        $this->description = $product->description ?? '';
        $this->categoryId = $product->category_id;
        $this->unitId = $product->unit_id;
        $this->estimatedCostBase = $product->fallback_unit_cost_base;
        $this->isActive = $product->is_active;
        Flux::modal('supply-form')->show();
    }

    public function save(): void
    {
        $companyId = app(CurrentCompany::class)->id();
        $product = $this->editingId ? Product::query()->findOrFail($this->editingId) : null;
        Gate::authorize($product ? 'update' : 'create', $product ?? Product::class);
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string', 'max:100', Rule::unique('products', 'sku')->where('company_id', $companyId)->ignore($product)],
            'description' => ['nullable', 'string', 'max:2000'],
            'categoryId' => ['nullable', Rule::exists('categories', 'id')->where('company_id', $companyId)],
            'unitId' => ['required', Rule::exists('units', 'id')->where('company_id', $companyId)],
            'estimatedCostBase' => ['nullable', 'numeric', 'min:0'],
            'isActive' => ['boolean'],
        ]);

        Product::query()->updateOrCreate(['id' => $product?->getKey()], [
            'company_id' => $companyId,
            'name' => $data['name'],
            'sku' => $data['sku'],
            'description' => filled($data['description']) ? $data['description'] : null,
            'category_id' => $data['categoryId'],
            'unit_id' => $data['unitId'],
            'item_type' => ProductItemType::Physical,
            'inventory_behavior' => InventoryBehavior::Self,
            'sale_price_base' => 0,
            'fallback_unit_cost_base' => filled($data['estimatedCostBase']) ? $data['estimatedCostBase'] : null,
            'is_sellable' => false,
            'is_active' => $data['isActive'],
        ]);

        Flux::modal('supply-form')->close();
        Flux::toast(variant: 'success', text: $product ? 'Insumo actualizado. La existencia y su costo real no cambiaron.' : 'Insumo creado. Ahora puedes cargar su existencia.');
        $this->resetForm();
    }

    public function formatMoney(int|float|string $amount): string
    {
        $decimalPlaces = app(CurrentCompany::class)->company()->baseCurrency->decimal_places;

        return Number::format((float) $amount, precision: $decimalPlaces, locale: 'es');
    }

    #[Computed]
    public function supplies(): LengthAwarePaginator
    {
        return Product::query()
            ->with(['category:id,name', 'unit:id,name,symbol'])
            ->selfManaged()
            ->when($this->search, fn ($query) => $query->where(
                fn ($nested) => $nested->where('name', 'like', '%'.$this->search.'%')
                    ->orWhere('sku', 'like', '%'.$this->search.'%'),
            ))
            ->latest()
            ->paginate(15);
    }

    #[Computed]
    public function categories(): Collection
    {
        return Category::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function units(): Collection
    {
        return Unit::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'symbol']);
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'sku', 'description', 'categoryId', 'unitId', 'estimatedCostBase']);
        $this->isActive = true;
        $this->resetValidation();
    }
}; ?>

@php($currency = app(CurrentCompany::class)->company()->baseCurrency)

<div class="flex w-full flex-col gap-6">
    <div class="flex flex-col justify-between gap-4 md:flex-row md:items-center">
        <div>
            <flux:heading size="xl">Insumos</flux:heading>
            <flux:text>Flores, papeles, cintas y otros materiales que utilizas.</flux:text>
        </div>
        @can('create', App\Models\Product::class)
            <flux:button variant="primary" icon="plus" wire:click="create">Nuevo insumo</flux:button>
        @endcan
    </div>

    <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Buscar insumo o SKU" class="max-w-md" />

    <flux:callout icon="information-circle" heading="Los insumos no se venden directamente">
        Registra aquí sus datos básicos. Las compras actualizan la existencia y el costo promedio; los ramos los consumen mediante sus recetas.
    </flux:callout>

    <flux:card class="overflow-hidden p-0!">
        <div class="overflow-x-auto">
            <flux:table :paginate="$this->supplies">
                <flux:table.columns>
                    <flux:table.column>Insumo</flux:table.column>
                    <flux:table.column>Categoría</flux:table.column>
                    <flux:table.column>Unidad</flux:table.column>
                    <flux:table.column align="end">Costo de referencia</flux:table.column>
                    <flux:table.column>Estado</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->supplies as $supply)
                        <flux:table.row :key="$supply->id">
                            <flux:table.cell variant="strong">
                                {{ $supply->name }}
                                <flux:text size="sm">{{ $supply->sku }}</flux:text>
                            </flux:table.cell>
                            <flux:table.cell>{{ $supply->category?->name ?? 'Sin categoría' }}</flux:table.cell>
                            <flux:table.cell>{{ $supply->unit->name }} ({{ $supply->unit->symbol }})</flux:table.cell>
                            <flux:table.cell align="end">{{ $supply->fallback_unit_cost_base === null ? '—' : $currency->symbol.' '.$this->formatMoney($supply->fallback_unit_cost_base) }}</flux:table.cell>
                            <flux:table.cell><flux:badge :color="$supply->is_active ? 'green' : 'zinc'">{{ $supply->is_active ? 'Activo' : 'Inactivo' }}</flux:badge></flux:table.cell>
                            <flux:table.cell><flux:button size="sm" variant="subtle" wire:click="edit({{ $supply->id }})">Editar</flux:button></flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="6">
                                <div class="py-12 text-center">
                                    <flux:heading>No hay insumos</flux:heading>
                                    <flux:text>Registra Rosa Roja, Tulipán, Papel o Cinta para comenzar.</flux:text>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    </flux:card>

    <flux:modal name="supply-form" class="max-w-2xl">
        <form wire:submit="save" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $editingId ? 'Editar insumo' : 'Nuevo insumo' }}</flux:heading>
                <flux:text>Registra los datos básicos del material. Las existencias y costos reales se cargan desde Inventario.</flux:text>
            </div>
            <div class="grid gap-4 md:grid-cols-2">
                <flux:input wire:model="name" label="Nombre" required />
                <flux:input wire:model="sku" label="SKU" required />
                <flux:select wire:model="categoryId" label="Categoría">
                    <flux:select.option value="">Sin categoría</flux:select.option>
                    @foreach ($this->categories as $category)
                        <flux:select.option :value="$category->id">{{ $category->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="unitId" label="Unidad" required>
                    <flux:select.option value="">Seleccionar</flux:select.option>
                    @foreach ($this->units as $unit)
                        <flux:select.option :value="$unit->id">{{ $unit->name }} ({{ $unit->symbol }})</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input
                    wire:model="estimatedCostBase"
                    type="number"
                    step="0.0001"
                    min="0"
                    label="Costo de referencia ({{ $currency->code }})"
                    description="Este valor es solo una referencia. El costo real del inventario se calcula con las compras o entradas registradas."
                />
            </div>
            <flux:textarea wire:model="description" label="Descripción" rows="3" />
            <flux:switch wire:model="isActive" label="Activo" />
            <div class="flex justify-end gap-3">
                <flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Guardar insumo</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
