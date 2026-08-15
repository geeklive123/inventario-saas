<?php

use App\Actions\Catalog\CreateProductRecipe;
use App\Enums\InventoryBehavior;
use App\Enums\ProductItemType;
use App\Models\Product;
use App\Models\ProductRecipe;
use App\Support\Tenancy\CurrentCompany;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Recetas')] class extends Component
{
    public ?int $selectedProductId = null;
    public string $yieldQuantity = '1';
    public bool $showHistory = false;
    public bool $showWasteOptions = false;

    /** @var array<int, array{component_id: string, quantity: string, waste_percentage: string}> */
    public array $components = [];

    public function mount(): void
    {
        $requestedProductId = request()->integer('producto');
        $this->selectedProductId = Product::query()
            ->whereKey($requestedProductId ?: null)
            ->where('inventory_behavior', InventoryBehavior::Components)
            ->where('is_active', true)
            ->value('id')
            ?? Product::query()
                ->where('inventory_behavior', InventoryBehavior::Components)
                ->where('is_active', true)
                ->orderBy('name')
                ->value('id');

        $this->resetDraft();
    }

    public function updatedSelectedProductId(): void
    {
        $this->showHistory = false;
        $this->resetDraft();
        unset($this->recipes, $this->selectedProduct);
    }

    public function openDraft(): void
    {
        $product = Product::query()->findOrFail($this->selectedProductId);
        Gate::authorize('update', $product);
        $this->resetDraft();
        Flux::modal('recipe-draft')->show();
    }

    public function addComponent(): void
    {
        $this->components[] = ['component_id' => '', 'quantity' => '1', 'waste_percentage' => '0'];
    }

    public function removeComponent(int $index): void
    {
        unset($this->components[$index]);
        $this->components = array_values($this->components);
    }

    public function activateDraft(): void
    {
        $product = Product::query()->findOrFail($this->selectedProductId);
        Gate::authorize('update', $product);
        $data = $this->validate([
            'components' => ['required', 'array', 'min:1'],
            'components.*.component_id' => ['required', 'integer', 'distinct'],
            'components.*.quantity' => ['required', 'numeric', 'gt:0'],
            'components.*.waste_percentage' => ['required', 'numeric', 'between:0,100'],
        ], messages: [
            'components.*.component_id.distinct' => 'No repitas un insumo.',
            'components.*.component_id.required' => 'Selecciona un insumo.',
            'components.*.quantity.gt' => 'La cantidad debe ser mayor que cero.',
            'components.*.waste_percentage.between' => 'El desperdicio debe estar entre 0 y 100.',
        ]);

        $componentModels = Product::query()
            ->whereIn('id', collect($data['components'])->pluck('component_id'))
            ->get()
            ->keyBy('id');
        $items = collect($data['components'])->map(function (array $item) use ($componentModels): array {
            return [
                'product' => $componentModels->get((int) $item['component_id']) ?? abort(404),
                'quantity' => $item['quantity'],
                'waste_percentage' => $item['waste_percentage'],
            ];
        })->all();

        try {
            app(CreateProductRecipe::class)->handle(
                app(CurrentCompany::class)->membership(),
                $product,
                '1',
                $items,
            );
        } catch (\DomainException $exception) {
            $this->addError('components', $exception->getMessage());

            return;
        }

        Flux::modal('recipe-draft')->close();
        Flux::toast(variant: 'success', text: 'La receta actual fue guardada.');
        unset($this->recipes);
        $this->showHistory = false;
        $this->resetDraft();
    }

    public function formatQuantity(int|float|string $quantity): string
    {
        return Number::format((float) $quantity, maxPrecision: 6, locale: 'es');
    }

    #[Computed]
    public function composedProducts(): Collection
    {
        return Product::query()
            ->composed()
            ->sellable()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'unit_id', 'name', 'sku']);
    }

    #[Computed]
    public function selectedProduct(): ?Product
    {
        return Product::query()->with('unit:id,name,symbol')->find($this->selectedProductId);
    }

    #[Computed]
    public function componentProducts(): Collection
    {
        return Product::query()
            ->with('unit:id,symbol')
            ->selfManaged()
            ->where('is_sellable', false)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'unit_id', 'name', 'sku']);
    }

    #[Computed]
    public function recipes(): Collection
    {
        return ProductRecipe::query()
            ->with(['items.componentProduct.unit', 'createdBy.user'])
            ->where('product_id', $this->selectedProductId)
            ->latest('version')
            ->get();
    }

    private function resetDraft(): void
    {
        $this->yieldQuantity = '1';
        $this->components = [['component_id' => '', 'quantity' => '1', 'waste_percentage' => '0']];
        $this->showWasteOptions = false;
        $this->resetValidation();
    }
}; ?>

@php
    $currentRecipe = $this->recipes->first(fn (ProductRecipe $recipe) => $recipe->isActive());
    $recipeHistory = $this->recipes->reject(fn (ProductRecipe $recipe) => $recipe->isActive());
@endphp

<div class="flex w-full flex-col gap-6">
    <div class="flex flex-col justify-between gap-4 md:flex-row md:items-center">
        <div>
            <flux:heading size="xl">Recetas de ramos</flux:heading>
            <flux:text>Indica qué insumos y cantidades utiliza cada ramo.</flux:text>
        </div>
        @if ($selectedProductId)
            <flux:button variant="primary" icon="pencil-square" wire:click="openDraft">
                {{ $currentRecipe ? 'Cambiar receta' : 'Crear receta' }}
            </flux:button>
        @endif
    </div>

    @if (session('success'))
        <flux:callout variant="success" icon="check-circle" :heading="session('success')" />
    @endif

    <flux:card>
        <flux:select wire:model.live="selectedProductId" label="Ramo">
            <flux:select.option value="">Seleccionar ramo</flux:select.option>
            @foreach ($this->composedProducts as $product)
                <flux:select.option :value="$product->id">{{ $product->name }} · {{ $product->sku }}</flux:select.option>
            @endforeach
        </flux:select>
    </flux:card>

    @if (! $selectedProductId)
        <flux:card>
            <div class="py-12 text-center">
                <flux:heading>No hay ramos</flux:heading>
                <flux:text class="mt-1">Primero crea un ramo desde la sección Ramos.</flux:text>
                @can('create', App\Models\Product::class)
                    <flux:button class="mt-4" :href="route('bouquets')" wire:navigate>Ir a Ramos</flux:button>
                @endcan
            </div>
        </flux:card>
    @elseif ($currentRecipe)
        <flux:card>
            <div class="flex flex-col justify-between gap-4 md:flex-row md:items-start">
                <div>
                    <flux:heading size="lg">Receta actual</flux:heading>
                    <flux:text class="mt-1">
                        Esta receta prepara 1 {{ $this->selectedProduct?->name }}.
                    </flux:text>
                </div>
                <flux:badge color="green">En uso</flux:badge>
            </div>

            <div class="mt-6 overflow-x-auto">
                <flux:table>
                    <flux:table.columns>
                        <flux:table.column>Insumo</flux:table.column>
                        <flux:table.column align="end">Cantidad</flux:table.column>
                        <flux:table.column>Unidad</flux:table.column>
                        <flux:table.column align="end">Desperdicio estimado</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($currentRecipe->items as $item)
                            <flux:table.row :key="$item->id">
                                <flux:table.cell variant="strong">{{ $item->componentProduct->name }}<flux:text size="sm">{{ $item->componentProduct->sku }}</flux:text></flux:table.cell>
                                <flux:table.cell align="end">{{ $this->formatQuantity($item->quantity) }}</flux:table.cell>
                                <flux:table.cell>{{ $item->componentProduct->unit->symbol }}</flux:table.cell>
                                <flux:table.cell align="end">{{ (float) $item->waste_percentage > 0 ? $this->formatQuantity($item->waste_percentage).' %' : '—' }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>

            <flux:text class="mt-4" size="sm">
                Actualizada por {{ $currentRecipe->createdBy?->user?->name ?? 'Sistema' }} · {{ $currentRecipe->created_at->format('d/m/Y H:i') }}
            </flux:text>
        </flux:card>
    @else
        <flux:card>
            <div class="py-12 text-center">
                <flux:heading>Este ramo todavía no tiene receta</flux:heading>
                <flux:text class="mt-1">Indica los insumos y cantidades que necesitas para prepararlo.</flux:text>
                <flux:button class="mt-4" variant="primary" wire:click="openDraft">Crear receta</flux:button>
            </div>
        </flux:card>
    @endif

    @if ($recipeHistory->isNotEmpty())
        <div>
            <flux:button variant="subtle" icon="clock" wire:click="$toggle('showHistory')">
                {{ $showHistory ? 'Ocultar historial de recetas' : 'Ver historial de recetas' }}
            </flux:button>
        </div>

        @if ($showHistory)
            <div class="grid gap-4 lg:grid-cols-2">
                @foreach ($recipeHistory as $recipe)
                    <flux:card wire:key="recipe-history-{{ $recipe->id }}">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <flux:heading>Receta anterior {{ $recipe->version }}</flux:heading>
                                <flux:text>Preparaba 1 {{ $this->selectedProduct?->name }}</flux:text>
                            </div>
                            <flux:badge color="zinc">Reemplazada</flux:badge>
                        </div>
                        <div class="mt-4 divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($recipe->items as $item)
                                <div wire:key="history-item-{{ $item->id }}" class="flex items-center justify-between gap-4 py-3">
                                    <span class="font-medium">{{ $item->componentProduct->name }}</span>
                                    <span>{{ $this->formatQuantity($item->quantity) }} {{ $item->componentProduct->unit->symbol }}</span>
                                </div>
                            @endforeach
                        </div>
                    </flux:card>
                @endforeach
            </div>
        @endif
    @endif

    <flux:modal name="recipe-draft" class="max-w-4xl">
        <form wire:submit="activateDraft" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $currentRecipe ? 'Cambiar la receta' : 'Crear la receta' }}</flux:heading>
                <flux:text>La receta anterior se conservará en el historial cuando confirmes el cambio.</flux:text>
            </div>

            @error('components')<flux:callout variant="danger" icon="x-circle" :heading="$message" />@enderror

            <div class="space-y-3">
                <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                    <div>
                        <flux:heading>¿Qué necesitas para prepararlo?</flux:heading>
                        <flux:text size="sm">Agrega cada producto o insumo y la cantidad utilizada.</flux:text>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <flux:button type="button" size="sm" variant="subtle" wire:click="$toggle('showWasteOptions')">{{ $showWasteOptions ? 'Ocultar desperdicio' : 'Agregar desperdicio estimado' }}</flux:button>
                        <flux:button type="button" size="sm" variant="subtle" icon="plus" wire:click="addComponent">Agregar insumo</flux:button>
                    </div>
                </div>

                @foreach ($components as $index => $component)
                    @php($componentProduct = $this->componentProducts->firstWhere('id', (int) $component['component_id']))
                    <div wire:key="draft-component-{{ $index }}" class="grid items-end gap-3 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700 md:grid-cols-[minmax(0,1fr)_9rem_7rem_auto]">
                        <flux:select wire:model.live="components.{{ $index }}.component_id" label="Insumo">
                            <flux:select.option value="">Seleccionar</flux:select.option>
                            @foreach ($this->componentProducts as $product)<flux:select.option :value="$product->id">{{ $product->name }} · {{ $product->sku }}</flux:select.option>@endforeach
                        </flux:select>
                        <flux:input wire:model="components.{{ $index }}.quantity" type="number" step="0.000001" min="0.000001" label="Cantidad" />
                        <div>
                            <flux:text size="sm">Unidad</flux:text>
                            <div class="mt-2 min-h-10 rounded-lg bg-zinc-100 px-3 py-2 text-sm dark:bg-zinc-800">{{ $componentProduct?->unit?->symbol ?? '—' }}</div>
                        </div>
                        <flux:button type="button" variant="subtle" icon="trash" wire:click="removeComponent({{ $index }})" :disabled="count($components) === 1" aria-label="Quitar insumo" />

                        @if ($showWasteOptions)
                            <div class="md:col-span-4">
                                <flux:input wire:model="components.{{ $index }}.waste_percentage" type="number" step="0.000001" min="0" max="100" label="Desperdicio estimado (%)" description="Porcentaje adicional que normalmente se pierde al cortar, limpiar o preparar. Déjalo en 0 si no aplica." />
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="flex justify-end gap-3">
                <flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" wire:confirm="¿Confirmas esta receta? La receta actual se conservará en el historial." wire:loading.attr="disabled">Guardar receta</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
