<?php

use App\Actions\Inventory\ReverseStockMovement;
use App\Enums\StockMovementType;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Support\Tenancy\CurrentCompany;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Movimientos de inventario')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public ?int $warehouseId = null;

    public string $typeFilter = '';

    public ?int $selectedId = null;

    public string $reversalReason = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedWarehouseId(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function viewMovement(int $id): void
    {
        $movement = StockMovement::query()->findOrFail($id);
        Gate::authorize('view', $movement);
        $this->selectedId = $movement->id;
        $this->reversalReason = '';
        unset($this->selectedMovement);
        Flux::modal('movement-detail')->show();
    }

    public function reverse(): void
    {
        $movement = StockMovement::query()->findOrFail($this->selectedId);
        Gate::authorize('reverse', $movement);
        $this->validate(
            ['reversalReason' => ['required', 'string', 'max:1000']],
            messages: ['reversalReason.required' => 'Indica el motivo de la reversión.'],
        );

        try {
            app(ReverseStockMovement::class)->handle(
                app(CurrentCompany::class)->membership(),
                $movement,
                $this->reversalReason,
            );
        } catch (DomainException $exception) {
            $this->addError('reversalReason', $exception->getMessage());

            return;
        }

        unset($this->selectedMovement);
        Flux::modal('movement-detail')->close();
        Flux::toast(variant: 'success', text: 'Movimiento revertido. El original se conserva sin cambios.');
    }

    public function formatQuantity(int|float|string $quantity): string
    {
        return Number::format((float) $quantity, maxPrecision: 6, locale: 'es');
    }

    public function formatMoney(int|float|string $amount): string
    {
        $decimalPlaces = app(CurrentCompany::class)->company()->baseCurrency->decimal_places;

        return Number::format((float) $amount, precision: $decimalPlaces, locale: 'es');
    }

    #[Computed]
    public function warehouses(): Collection
    {
        return Warehouse::query()->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function movements(): LengthAwarePaginator
    {
        return StockMovement::query()
            ->with(['warehouse:id,name', 'createdBy.user:id,name'])
            ->withExists('reversals')
            ->when($this->warehouseId, fn ($query) => $query->where('warehouse_id', $this->warehouseId))
            ->when($this->typeFilter, fn ($query) => $query->where('type', $this->typeFilter))
            ->when($this->search, fn ($query) => $query->where(fn ($nested) => $nested
                ->where('id', $this->search)
                ->orWhere('reason', 'like', '%'.$this->search.'%')
                ->orWhereHas('createdBy.user', fn ($user) => $user->where('name', 'like', '%'.$this->search.'%'))))
            ->latest('occurred_at')
            ->paginate(15);
    }

    #[Computed]
    public function selectedMovement(): ?StockMovement
    {
        return $this->selectedId
            ? StockMovement::query()->with(['warehouse.branch', 'createdBy.user', 'lines.product.unit', 'reversedMovement', 'reversals', 'saleLinks.sale'])->findOrFail($this->selectedId)
            : null;
    }
}; ?>

@php
    $company = app(CurrentCompany::class)->company();
    $currency = $company->baseCurrency;
@endphp

<div class="flex w-full flex-col gap-6">
    <div>
        <flux:heading size="xl">Historial de inventario</flux:heading>
        <flux:text>Aquí encontrarás cada entrada, salida, corrección y reversión registrada.</flux:text>
    </div>

    <flux:card class="grid gap-4 md:grid-cols-3">
        <flux:input wire:model.live.debounce.300ms="search" label="Buscar" icon="magnifying-glass" placeholder="Número, motivo o responsable" />
        <flux:select wire:model.live="warehouseId" label="Almacén">
            <flux:select.option value="">Todos</flux:select.option>
            @foreach ($this->warehouses as $warehouse)<flux:select.option :value="$warehouse->id">{{ $warehouse->name }}</flux:select.option>@endforeach
        </flux:select>
        <flux:select wire:model.live="typeFilter" label="Operación">
            <flux:select.option value="">Todas</flux:select.option>
            <flux:select.option value="opening">Stock inicial</flux:select.option>
            <flux:select.option value="adjustment_in">Entrada</flux:select.option>
            <flux:select.option value="adjustment_out">Salida</flux:select.option>
            <flux:select.option value="sale">Venta</flux:select.option>
            <flux:select.option value="waste">Merma</flux:select.option>
            <flux:select.option value="reversal">Reversión</flux:select.option>
        </flux:select>
    </flux:card>

    <flux:card class="overflow-hidden p-0!">
        <div class="overflow-x-auto">
            <flux:table :paginate="$this->movements">
                <flux:table.columns>
                    <flux:table.column>Número / Fecha</flux:table.column>
                    <flux:table.column>Operación</flux:table.column>
                    <flux:table.column>Almacén</flux:table.column>
                    <flux:table.column>Responsable</flux:table.column>
                    <flux:table.column>Motivo</flux:table.column>
                    <flux:table.column>Estado</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->movements as $movement)
                        <flux:table.row :key="$movement->id">
                            <flux:table.cell variant="strong">#{{ $movement->id }}<flux:text size="sm">{{ $movement->occurred_at->timezone($company->timezone)->format('d/m/Y H:i') }}</flux:text></flux:table.cell>
                            <flux:table.cell>{{ match ($movement->type) {
                                StockMovementType::Opening => 'Stock inicial',
                                StockMovementType::AdjustmentIn => 'Entrada',
                                StockMovementType::AdjustmentOut => 'Salida',
                                StockMovementType::Sale => 'Venta',
                                StockMovementType::Waste => 'Merma',
                                StockMovementType::Reversal => 'Reversión',
                            } }}</flux:table.cell>
                            <flux:table.cell>{{ $movement->warehouse->name }}</flux:table.cell>
                            <flux:table.cell>{{ $movement->createdBy->user->name }}</flux:table.cell>
                            <flux:table.cell>{{ $movement->reason ?: '—' }}</flux:table.cell>
                            <flux:table.cell>
                                @if ($movement->reversal_of_movement_id)<flux:badge color="amber">Corrección de otro movimiento</flux:badge>
                                @elseif ($movement->reversals_exists)<flux:badge color="zinc">Revertido</flux:badge>
                                @else<flux:badge color="green">Vigente</flux:badge>@endif
                            </flux:table.cell>
                            <flux:table.cell><flux:button size="sm" variant="subtle" wire:click="viewMovement({{ $movement->id }})">Ver detalle</flux:button></flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row><flux:table.cell colspan="7"><div class="py-12 text-center"><flux:heading>No hay movimientos</flux:heading><flux:text>Las operaciones registradas aparecerán aquí.</flux:text></div></flux:table.cell></flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    </flux:card>

    <flux:modal name="movement-detail" class="max-w-5xl">
        @if ($this->selectedMovement)
            <div class="space-y-6">
                <div class="flex flex-col justify-between gap-3 md:flex-row">
                    <div>
                        <flux:heading size="lg">Movimiento #{{ $this->selectedMovement->id }}</flux:heading>
                        <flux:text>{{ $this->selectedMovement->warehouse->branch->name }} · {{ $this->selectedMovement->warehouse->name }} · {{ $this->selectedMovement->occurred_at->timezone($company->timezone)->format('d/m/Y H:i') }}</flux:text>
                    </div>
                    <flux:badge>{{ $this->selectedMovement->createdBy->user->name }}</flux:badge>
                </div>

                <div class="overflow-x-auto">
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>Producto</flux:table.column>
                            <flux:table.column align="end">Existencia anterior</flux:table.column>
                            <flux:table.column align="end">Cantidad registrada</flux:table.column>
                            <flux:table.column align="end">Existencia resultante</flux:table.column>
                            <flux:table.column align="end">Costo por unidad</flux:table.column>
                            <flux:table.column align="end">Costo promedio anterior</flux:table.column>
                            <flux:table.column align="end">Costo promedio resultante</flux:table.column>
                            <flux:table.column align="end">Valor anterior</flux:table.column>
                            <flux:table.column align="end">Valor resultante</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($this->selectedMovement->lines as $line)
                                <flux:table.row :key="$line->id">
                                    <flux:table.cell variant="strong">{{ $line->product->name }}<flux:text size="sm">{{ $line->product->sku }}</flux:text></flux:table.cell>
                                    <flux:table.cell align="end">{{ $this->formatQuantity($line->quantity_before) }}</flux:table.cell>
                                    <flux:table.cell align="end"><span class="{{ (float) $line->quantity < 0 ? 'text-red-600' : 'text-green-600' }}">{{ $this->formatQuantity($line->quantity) }}</span></flux:table.cell>
                                    <flux:table.cell align="end">{{ $this->formatQuantity($line->quantity_after) }}</flux:table.cell>
                                    <flux:table.cell align="end">{{ $currency->symbol }} {{ $this->formatMoney($line->unit_cost_base) }}</flux:table.cell>
                                    <flux:table.cell align="end">{{ $currency->symbol }} {{ $this->formatMoney($line->average_unit_cost_before_base) }}</flux:table.cell>
                                    <flux:table.cell align="end">{{ $currency->symbol }} {{ $this->formatMoney($line->average_unit_cost_after_base) }}</flux:table.cell>
                                    <flux:table.cell align="end">{{ $currency->symbol }} {{ $this->formatMoney($line->inventory_value_before_base) }}</flux:table.cell>
                                    <flux:table.cell align="end">{{ $currency->symbol }} {{ $this->formatMoney($line->inventory_value_after_base) }}</flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>

                @if ($sale = $this->selectedMovement->saleLinks->first()?->sale)
                    @can('view', $sale)
                        <flux:callout icon="shopping-bag" heading="Relacionado con {{ $sale->number }}">
                            <flux:button size="sm" variant="subtle" :href="route('sales.show', ['saleId' => $sale->id])" wire:navigate>Ver venta</flux:button>
                        </flux:callout>
                    @endcan
                @endif

                @if (! $this->selectedMovement->reversal_of_movement_id && $this->selectedMovement->reversals->isEmpty() && Gate::allows('reverse', $this->selectedMovement))
                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900 dark:bg-amber-950/30">
                        <flux:heading>Revertir movimiento</flux:heading>
                        <flux:text class="mb-3">Se registrará una corrección que deshace este movimiento. El original seguirá visible.</flux:text>
                        <flux:textarea wire:model="reversalReason" label="Motivo obligatorio" />
                        <div class="mt-3 flex justify-end"><flux:button variant="danger" wire:click="reverse" wire:confirm="¿Confirmas la reversión? Se registrará una corrección y el original se conservará.">Revertir movimiento</flux:button></div>
                    </div>
                @endif
            </div>
        @endif
    </flux:modal>
</div>
