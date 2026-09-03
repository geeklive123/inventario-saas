<?php

use App\Actions\Inventory\AdjustStock;
use App\Actions\Inventory\RecordManualInbound;
use App\Actions\Inventory\RecordManualOutbound;
use App\Actions\Inventory\RegisterOpeningStock;
use App\Enums\InventoryBehavior;
use App\Enums\ProductItemType;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\StockMovementLine;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryService;
use App\Support\Authorization\CompanyAccess;
use App\Support\Decimal;
use App\Support\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Inventario')] class extends Component
{
    use WithPagination;

    public ?int $warehouseId = null;

    public string $search = '';

    public string $stockFilter = '';

    public string $operation = 'opening';

    public ?int $productId = null;

    public string $quantity = '';

    public ?string $unitCostBase = null;

    public string $occurredAt = '';

    public string $reason = '';

    public bool $showOperationModal = false;

    public ?int $historyProductId = null;

    public bool $showHistoryModal = false;

    public function mount(): void
    {
        $requested = session('current_warehouse_id');
        $this->warehouseId = Warehouse::query()->whereKey($requested)->where('is_active', true)->value('id')
            ?? Warehouse::query()->where('is_active', true)->orderBy('name')->value('id');

        if ($this->warehouseId) {
            session()->put('current_warehouse_id', $this->warehouseId);
        }

        $requestedOperation = request()->string('operacion')->toString();

        if (in_array($requestedOperation, ['opening', 'inbound', 'outbound', 'adjustment'], true)) {
            $permission = $requestedOperation === 'opening' ? 'inventory.opening' : 'inventory.adjust';

            if (app(CompanyAccess::class)->allowsCurrent(auth()->user(), $permission)) {
                $this->operation = $requestedOperation;
                $this->occurredAt = $this->todayForCompany();
                $this->showOperationModal = true;
            }
        }
    }

    public function updatedWarehouseId(): void
    {
        Warehouse::query()->whereKey($this->warehouseId)->where('is_active', true)->firstOrFail();
        session()->put('current_warehouse_id', $this->warehouseId);
        if ($this->operation === 'opening') {
            $this->reset('productId', 'unitCostBase');
        }
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStockFilter(): void
    {
        $this->resetPage();
    }

    public function updatedProductId(): void
    {
        $this->unitCostBase = null;

        if ($this->operation !== 'opening' || $this->productId === null) {
            return;
        }

        $this->unitCostBase = Product::query()->whereKey($this->productId)->value('fallback_unit_cost_base');
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

    public function formatMovementQuantity(int|float|string $quantity): string
    {
        $normalized = Decimal::normalize($quantity, 6);

        return (bccomp($normalized, '0', 6) > 0 ? '+' : '').$this->formatQuantity($normalized);
    }

    public function openOperation(string $operation, ?int $productId = null): void
    {
        abort_unless(in_array($operation, ['opening', 'inbound', 'outbound', 'adjustment'], true), 404);
        $permission = $operation === 'opening' ? 'inventory.opening' : 'inventory.adjust';

        if (! app(CompanyAccess::class)->allowsCurrent(auth()->user(), $permission)) {
            Flux::toast(variant: 'danger', text: 'No tienes permiso para registrar esta operación.');

            return;
        }

        $this->resetValidation();
        $this->operation = $operation;
        $this->productId = $productId;
        $this->quantity = '';
        $this->unitCostBase = null;
        $this->occurredAt = $this->todayForCompany();
        $this->reason = '';
        $this->showOperationModal = true;
        $this->updatedProductId();
        Flux::modal('stock-operation')->show();
    }

    public function openHistory(int $productId): void
    {
        Gate::authorize('viewAny', StockMovement::class);
        $product = Product::query()
            ->whereKey($productId)
            ->where('item_type', ProductItemType::Physical)
            ->where('inventory_behavior', InventoryBehavior::Self)
            ->first();
        $warehouseExists = Warehouse::query()->whereKey($this->warehouseId)->exists();

        abort_if($product === null || ! $warehouseExists, 404);

        $this->historyProductId = $productId;
        $this->showHistoryModal = true;
        $this->resetPage('historyPage');
        unset($this->historyProduct, $this->historyMovements);
        Flux::modal('stock-history')->show();
    }

    public function submitOperation(): void
    {
        if (! $this->warehouseId) {
            $this->addError('warehouseId', 'Selecciona un almacén.');

            return;
        }

        $quantityRule = $this->operation === 'adjustment' ? 'gte:0' : 'gt:0';
        $data = $this->validate([
            'operation' => ['required', 'in:opening,inbound,outbound,adjustment'],
            'productId' => ['required', 'integer'],
            'quantity' => ['required', 'numeric', $quantityRule],
            'reason' => ['nullable', 'string', 'max:1000'],
            'unitCostBase' => ['nullable', 'numeric', 'min:0'],
            'occurredAt' => ['nullable', 'required_if:operation,inbound', 'date_format:Y-m-d'],
        ], messages: [
            'quantity.gt' => 'La cantidad debe ser mayor que cero.',
            'quantity.gte' => 'La existencia física no puede ser negativa.',
            'occurredAt.required_if' => 'Indica la fecha de la compra o entrada.',
        ]);

        $warehouse = Warehouse::query()->whereKey($this->warehouseId)->where('is_active', true)->firstOrFail();
        $product = Product::query()
            ->whereKey($data['productId'])
            ->where('item_type', ProductItemType::Physical)
            ->where('inventory_behavior', InventoryBehavior::Self)
            ->firstOrFail();
        $balance = StockBalance::query()
            ->where('warehouse_id', $warehouse->getKey())
            ->where('product_id', $product->getKey())
            ->first();
        $quantityChange = $data['quantity'];

        if ($data['operation'] === 'adjustment') {
            $quantityChange = bcsub(Decimal::normalize($data['quantity'], 6), $balance?->quantity ?? '0.000000', 6);

            if (bccomp($quantityChange, '0', 6) === 0) {
                $this->addError('quantity', 'La existencia indicada ya coincide con el sistema.');

                return;
            }
        }

        $requiresCost = in_array($data['operation'], ['opening', 'inbound'], true)
            || ($data['operation'] === 'adjustment' && bccomp($quantityChange, '0', 6) > 0);

        if ($requiresCost && $data['unitCostBase'] === null) {
            $this->addError('unitCostBase', 'Indica el costo real por unidad.');

            return;
        }

        $actor = app(CurrentCompany::class)->membership();
        $occurredAt = $data['operation'] === 'inbound'
            ? CarbonImmutable::createFromFormat('Y-m-d', $data['occurredAt'], app(CurrentCompany::class)->company()->timezone)->startOfDay()
            : null;

        try {
            match ($data['operation']) {
                'opening' => app(RegisterOpeningStock::class)->handle($actor, $warehouse, $product, $data['quantity'], $data['unitCostBase'], $data['reason']),
                'inbound' => app(RecordManualInbound::class)->handle($actor, $warehouse, $product, $data['quantity'], $data['unitCostBase'], $data['reason'], $occurredAt),
                'outbound' => app(RecordManualOutbound::class)->handle($actor, $warehouse, $product, $data['quantity'], $data['reason']),
                default => app(AdjustStock::class)->handle($actor, $warehouse, $product, $quantityChange, $data['unitCostBase'], $data['reason']),
            };
        } catch (DomainException $exception) {
            $this->addError('operation', $exception->getMessage());

            return;
        }

        Flux::modal('stock-operation')->close();
        $this->showOperationModal = false;
        Flux::toast(variant: 'success', text: match ($data['operation']) {
            'opening' => 'Stock inicial cargado correctamente.',
            'inbound' => 'Compra registrada. El costo promedio fue actualizado.',
            'outbound' => 'Salida registrada correctamente.',
            default => 'Existencia corregida y movimiento registrado.',
        });
        $this->reset(['productId', 'quantity', 'unitCostBase', 'occurredAt', 'reason']);
    }

    #[Computed]
    public function canOpen(): bool
    {
        return app(CompanyAccess::class)->allowsCurrent(auth()->user(), 'inventory.opening');
    }

    #[Computed]
    public function canAdjust(): bool
    {
        return app(CompanyAccess::class)->allowsCurrent(auth()->user(), 'inventory.adjust');
    }

    #[Computed]
    public function warehouses(): Collection
    {
        return Warehouse::query()->with('branch:id,name')->where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function products(): Collection
    {
        return Product::query()
            ->with('unit:id,symbol')
            ->where('is_active', true)
            ->where('item_type', ProductItemType::Physical)
            ->where('inventory_behavior', InventoryBehavior::Self)
            ->when($this->operation === 'opening' && $this->warehouseId !== null, fn ($query) => $query
                ->whereDoesntHave('stockMovementLines', fn ($line) => $line
                    ->whereHas('movement', fn ($movement) => $movement
                        ->where('warehouse_id', $this->warehouseId)
                        ->where('type', StockMovementType::Opening))))
            ->orderBy('name')
            ->get(['id', 'unit_id', 'name', 'sku']);
    }

    #[Computed]
    public function selectedBalance(): ?StockBalance
    {
        if ($this->warehouseId === null || $this->productId === null) {
            return null;
        }

        return StockBalance::query()
            ->where('warehouse_id', $this->warehouseId)
            ->where('product_id', $this->productId)
            ->first();
    }

    /**
     * @return array{quantity_before: numeric-string, quantity_after: numeric-string, purchase_total_base: numeric-string, inventory_value_before_base: numeric-string, inventory_value_after_base: numeric-string, average_unit_cost_before_base: numeric-string, average_unit_cost_after_base: numeric-string}|null
     */
    #[Computed]
    public function inboundPreview(): ?array
    {
        if (! in_array($this->operation, ['opening', 'inbound'], true)
            || $this->productId === null
            || ! is_numeric($this->quantity)
            || ! is_numeric($this->unitCostBase)
            || (float) $this->quantity <= 0
            || (float) $this->unitCostBase < 0) {
            return null;
        }

        $product = Product::query()
            ->whereKey($this->productId)
            ->where('item_type', ProductItemType::Physical)
            ->where('inventory_behavior', InventoryBehavior::Self)
            ->first();

        if ($product === null) {
            return null;
        }

        try {
            return app(InventoryService::class)->previewInbound(
                $product,
                $this->selectedBalance,
                $this->quantity,
                $this->unitCostBase,
            );
        } catch (DomainException) {
            return null;
        }
    }

    #[Computed]
    public function adjustmentDifference(): ?string
    {
        if ($this->operation !== 'adjustment' || ! is_numeric($this->quantity)) {
            return null;
        }

        return bcsub(
            Decimal::normalize($this->quantity, 6),
            $this->selectedBalance?->quantity ?? '0.000000',
            6,
        );
    }

    #[Computed]
    public function balances(): LengthAwarePaginator
    {
        return StockBalance::query()
            ->with(['product:id,name,sku,unit_id', 'product.unit:id,symbol'])
            ->where('warehouse_id', $this->warehouseId)
            ->when($this->search, fn ($query) => $query->whereHas('product', fn ($product) => $product
                ->where('name', 'like', '%'.$this->search.'%')
                ->orWhere('sku', 'like', '%'.$this->search.'%')))
            ->when($this->stockFilter === 'positive', fn ($query) => $query->where('quantity', '>', 0))
            ->when($this->stockFilter === 'zero', fn ($query) => $query->where('quantity', 0))
            ->when($this->stockFilter === 'negative', fn ($query) => $query->where('quantity', '<', 0))
            ->latest()
            ->paginate(15);
    }

    #[Computed]
    public function historyProduct(): ?Product
    {
        return $this->historyProductId === null
            ? null
            : Product::query()->with('unit:id,symbol')->findOrFail($this->historyProductId);
    }

    #[Computed]
    public function historyMovements(): LengthAwarePaginator
    {
        $movementsTable = (new StockMovement)->getTable();
        $linesTable = (new StockMovementLine)->getTable();

        return StockMovementLine::query()
            ->with([
                'movement.createdBy.user:id,name',
                'movement.reversedMovement:id',
                'movement.reversals:id,reversal_of_movement_id',
            ])
            ->where('product_id', $this->historyProductId ?? 0)
            ->whereHas('movement', fn ($movement) => $movement->where('warehouse_id', $this->warehouseId))
            ->orderByDesc(
                StockMovement::query()
                    ->withoutGlobalScope('company')
                    ->select('occurred_at')
                    ->whereColumn($movementsTable.'.id', $linesTable.'.stock_movement_id')
                    ->whereColumn($movementsTable.'.company_id', $linesTable.'.company_id')
                    ->limit(1),
            )
            ->orderByDesc('id')
            ->paginate(10, ['*'], 'historyPage');
    }

    private function todayForCompany(): string
    {
        return now(app(CurrentCompany::class)->company()->timezone)->toDateString();
    }
};

?>

@php($currency = app(CurrentCompany::class)->company()->baseCurrency)

<div class="flex w-full flex-col gap-6">
    <div class="flex flex-col justify-between gap-4 xl:flex-row xl:items-center">
        <div>
            <flux:heading size="xl">Inventario</flux:heading>
            <flux:text>Consulta lo que tienes disponible y registra compras, salidas o correcciones.</flux:text>
        </div>
        @if ($this->canOpen || $this->canAdjust)
            <div class="flex flex-wrap gap-2">
                @if ($this->canOpen)
                    <flux:button variant="primary" icon="archive-box-arrow-down" wire:click="openOperation('opening')">Cargar stock inicial</flux:button>
                @endif
                @if ($this->canAdjust)
                    <flux:button icon="shopping-cart" wire:click="openOperation('inbound')">Registrar compra</flux:button>
                    <flux:button icon="arrow-up-tray" wire:click="openOperation('outbound')">Registrar salida</flux:button>
                    <flux:button icon="scale" wire:click="openOperation('adjustment')">Ajustar stock</flux:button>
                @endif
            </div>
        @endif
    </div>

    @if (! $this->canOpen && ! $this->canAdjust)
        <flux:callout icon="information-circle" heading="Modo histórico">
            El módulo está deshabilitado o tu acceso no permite registrar operaciones. Puedes consultar los datos existentes.
        </flux:callout>
    @endif

    <flux:card class="grid gap-4 md:grid-cols-3">
        <flux:select wire:model.live="warehouseId" label="Almacén">
            <flux:select.option value="">Seleccionar</flux:select.option>
            @foreach ($this->warehouses as $warehouse)
                <flux:select.option :value="$warehouse->id">{{ $warehouse->branch->name }} · {{ $warehouse->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input wire:model.live.debounce.300ms="search" label="Buscar" icon="magnifying-glass" placeholder="Insumo o SKU" />
        <flux:select wire:model.live="stockFilter" label="Cantidad disponible">
            <flux:select.option value="">Todas</flux:select.option>
            <flux:select.option value="positive">Disponible</flux:select.option>
            <flux:select.option value="zero">Agotada</flux:select.option>
            <flux:select.option value="negative">Negativa</flux:select.option>
        </flux:select>
    </flux:card>

    <flux:card class="overflow-hidden p-0!">
        <div class="overflow-x-auto">
            <flux:table :paginate="$this->balances">
                <flux:table.columns>
                    <flux:table.column>Insumo</flux:table.column>
                    <flux:table.column align="end">Cantidad disponible</flux:table.column>
                    <flux:table.column align="end">
                        <span class="inline-flex items-center justify-end gap-1">
                            Costo promedio actual
                            <flux:tooltip content="Es el costo promedio por unidad considerando todas las compras registradas." toggleable>
                                <button type="button" aria-label="Información sobre el costo promedio actual" class="rounded text-zinc-400 outline-none hover:text-zinc-700 focus-visible:ring-2 focus-visible:ring-zinc-500 dark:hover:text-zinc-200">
                                    <flux:icon.information-circle class="size-4" />
                                </button>
                            </flux:tooltip>
                        </span>
                    </flux:table.column>
                    <flux:table.column align="end">
                        <span class="inline-flex items-center justify-end gap-1">
                            Valor total en stock
                            <flux:tooltip content="Es cuánto vale actualmente todo el stock disponible de este insumo." toggleable>
                                <button type="button" aria-label="Información sobre el valor total en stock" class="rounded text-zinc-400 outline-none hover:text-zinc-700 focus-visible:ring-2 focus-visible:ring-zinc-500 dark:hover:text-zinc-200">
                                    <flux:icon.information-circle class="size-4" />
                                </button>
                            </flux:tooltip>
                        </span>
                    </flux:table.column>
                    <flux:table.column align="end">
                        <span class="inline-flex items-center justify-end gap-1">
                            Último precio pagado
                            <flux:tooltip content="Es el precio por unidad de la compra más reciente." toggleable>
                                <button type="button" aria-label="Información sobre el último precio pagado" class="rounded text-zinc-400 outline-none hover:text-zinc-700 focus-visible:ring-2 focus-visible:ring-zinc-500 dark:hover:text-zinc-200">
                                    <flux:icon.information-circle class="size-4" />
                                </button>
                            </flux:tooltip>
                        </span>
                    </flux:table.column>
                    <flux:table.column>Acciones</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($this->balances as $balance)
                        <flux:table.row :key="$balance->id">
                            <flux:table.cell variant="strong">
                                {{ $balance->product->name }}
                                <flux:text size="sm">{{ $balance->product->sku }}</flux:text>
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                <span class="font-semibold {{ (float) $balance->quantity < 0 ? 'text-red-600 dark:text-red-400' : '' }}">{{ $this->formatQuantity($balance->quantity) }} {{ $balance->product->unit->symbol }}</span>
                            </flux:table.cell>
                            <flux:table.cell align="end">{{ $currency->symbol }} {{ $this->formatMoney($balance->average_unit_cost_base) }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $currency->symbol }} {{ $this->formatMoney($balance->inventory_value_base) }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $balance->last_inbound_unit_cost_base === null ? '—' : $currency->symbol.' '.$this->formatMoney($balance->last_inbound_unit_cost_base) }}</flux:table.cell>
                            <flux:table.cell>
                                <div class="flex flex-wrap justify-end gap-2">
                                    @if ($this->canAdjust)
                                        <flux:button size="sm" variant="subtle" icon="plus" wire:click="openOperation('inbound', {{ $balance->product_id }})">Compra</flux:button>
                                        <flux:button size="sm" variant="subtle" icon="minus" wire:click="openOperation('outbound', {{ $balance->product_id }})">Salida</flux:button>
                                    @endif
                                    <flux:button size="sm" variant="subtle" icon="clock" wire:click="openHistory({{ $balance->product_id }})">Historial</flux:button>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="6">
                                <div class="py-12 text-center">
                                    <flux:heading>Sin existencias registradas</flux:heading>
                                    <flux:text>Selecciona un almacén y carga el stock inicial de tus insumos.</flux:text>
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>
    </flux:card>

    @if ($this->canOpen || $this->canAdjust)
        <flux:modal name="stock-operation" wire:model.self="showOperationModal" class="max-w-2xl">
            <form wire:submit="submitOperation" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ match ($operation) { 'opening' => 'Cargar stock inicial', 'inbound' => 'Registrar compra', 'outbound' => 'Registrar salida', default => 'Ajustar stock' } }}</flux:heading>
                    <flux:text>{{ match ($operation) { 'opening' => 'Indica cuánto inventario tienes actualmente y cuánto te costó realmente cada unidad.', 'inbound' => 'Registra las flores o materiales que compraste para actualizar el costo promedio.', 'outbound' => 'Usa esta opción para consumos internos o salidas que no sean mermas ni ventas de ramos.', default => 'Utiliza esta opción cuando el inventario físico no coincide con el sistema.' } }}</flux:text>
                </div>

                @error('operation')
                    <flux:callout variant="danger" icon="x-circle" :heading="$message" />
                @enderror

                <flux:select wire:model.live="warehouseId" label="Almacén" required>
                    <flux:select.option value="">Seleccionar</flux:select.option>
                    @foreach ($this->warehouses as $warehouse)
                        <flux:select.option :value="$warehouse->id">{{ $warehouse->branch->name }} · {{ $warehouse->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($operation === 'opening' && $this->products->isEmpty())
                    <flux:callout icon="check-circle" heading="Todos los insumos ya tienen una existencia inicial registrada.">
                        Para agregar nuevas unidades utiliza Registrar compra.
                    </flux:callout>
                @else
                    <flux:select wire:model.live="productId" label="Insumo" required>
                        <flux:select.option value="">Seleccionar</flux:select.option>
                        @foreach ($this->products as $product)
                            <flux:select.option :value="$product->id">{{ $product->name }} · {{ $product->sku }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif

                @if ($operation === 'inbound')
                    <flux:input wire:model="occurredAt" type="date" label="Fecha" required />
                @endif

                <flux:input
                    wire:model.live.debounce.250ms="quantity"
                    type="number"
                    step="0.000001"
                    min="0"
                    :label="match ($operation) { 'opening' => 'Cantidad inicial', 'inbound' => 'Cantidad comprada', 'adjustment' => 'Existencia física real', default => 'Cantidad' }"
                    :description="$operation === 'adjustment' ? 'Escribe el total que contaste físicamente; el sistema registrará solo la diferencia.' : null"
                    required
                />

                @if (in_array($operation, ['opening', 'inbound'], true) || ($operation === 'adjustment' && $this->adjustmentDifference !== null && bccomp($this->adjustmentDifference, '0', 6) > 0))
                    <flux:input
                        wire:model.live.debounce.250ms="unitCostBase"
                        type="number"
                        step="0.0001"
                        min="0"
                        :label="match ($operation) { 'opening' => 'Costo real por unidad', 'inbound' => 'Precio unitario pagado', default => 'Costo por unidad agregada' }"
                    />
                @endif

                @if ($this->inboundPreview)
                    <flux:card class="bg-zinc-50 dark:bg-zinc-800/60">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <flux:text size="sm">Existencia actual</flux:text>
                                <div class="font-semibold">{{ $this->formatQuantity($this->inboundPreview['quantity_before']) }}</div>
                            </div>
                            <div>
                                <flux:text size="sm">Costo promedio actual</flux:text>
                                <div class="font-semibold">{{ $currency->symbol }} {{ $this->formatMoney($this->inboundPreview['average_unit_cost_before_base']) }}</div>
                            </div>
                            <div>
                                <flux:text size="sm">{{ $operation === 'opening' ? 'Carga inicial' : 'Compra' }}</flux:text>
                                <div class="font-semibold">{{ $this->formatQuantity($quantity) }} × {{ $currency->symbol }} {{ $this->formatMoney($unitCostBase) }} = {{ $currency->symbol }} {{ $this->formatMoney($this->inboundPreview['purchase_total_base']) }}</div>
                            </div>
                            <div>
                                <flux:text size="sm">Nueva existencia</flux:text>
                                <div class="font-semibold">{{ $this->formatQuantity($this->inboundPreview['quantity_after']) }}</div>
                            </div>
                            <div class="sm:col-span-2 border-t border-zinc-200 pt-3 dark:border-zinc-700">
                                <flux:text size="sm">Nuevo costo promedio</flux:text>
                                <div class="font-semibold text-green-700 dark:text-green-400">{{ $currency->symbol }} {{ $this->formatMoney($this->inboundPreview['average_unit_cost_after_base']) }}</div>
                            </div>
                        </div>
                    </flux:card>
                @endif

                @if ($operation === 'adjustment' && $productId)
                    <flux:callout icon="scale" heading="Comparación con el conteo físico">
                        Sistema: {{ $this->formatQuantity($this->selectedBalance?->quantity ?? 0) }}.
                        @if ($this->adjustmentDifference !== null)
                            Se registrará una diferencia de {{ $this->formatQuantity($this->adjustmentDifference) }}.
                        @endif
                    </flux:callout>
                @endif

                <flux:textarea
                    wire:model="reason"
                    :label="$operation === 'inbound' ? 'Observación' : 'Motivo / observación'"
                    :placeholder="match ($operation) { 'inbound' => 'Ej.: Compra proveedor Mercado Floral', 'outbound' => 'Ej.: Uso interno, ajuste u otro motivo', 'opening' => 'Ej.: Inventario inicial', default => 'Ej.: Diferencia encontrada en conteo físico' }"
                    rows="3"
                />

                <div class="flex justify-end gap-3">
                    <flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" wire:confirm="¿Confirmas esta operación de inventario?" wire:loading.attr="disabled">Confirmar</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    <flux:modal name="stock-history" wire:model.self="showHistoryModal" class="max-w-[95vw]">
        @if ($this->historyProduct)
            <div class="space-y-5">
                <div>
                    <flux:heading size="lg">Historial de {{ $this->historyProduct->name }}</flux:heading>
                    <flux:text>
                        {{ $this->historyProduct->sku }} · {{ $this->historyProduct->unit->symbol }} ·
                        {{ $this->warehouses->firstWhere('id', $warehouseId)?->branch?->name }} ·
                        {{ $this->warehouses->firstWhere('id', $warehouseId)?->name }}
                    </flux:text>
                </div>

                <flux:callout icon="information-circle">
                    Los importes y cantidades son los valores guardados al registrar cada operación; no se recalculan con el stock actual.
                </flux:callout>

                <div class="overflow-x-auto">
                    <flux:table :paginate="$this->historyMovements">
                        <flux:table.columns>
                            <flux:table.column>Fecha y hora</flux:table.column>
                            <flux:table.column>Tipo</flux:table.column>
                            <flux:table.column align="end">Movimiento</flux:table.column>
                            <flux:table.column align="end">Antes</flux:table.column>
                            <flux:table.column align="end">Después</flux:table.column>
                            <flux:table.column align="end">Precio / costo unitario</flux:table.column>
                            <flux:table.column align="end">Costo promedio antes</flux:table.column>
                            <flux:table.column align="end">Costo promedio después</flux:table.column>
                            <flux:table.column align="end">Valor antes</flux:table.column>
                            <flux:table.column align="end">Valor después</flux:table.column>
                            <flux:table.column>Observación</flux:table.column>
                            <flux:table.column>Responsable</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @forelse ($this->historyMovements as $line)
                                <flux:table.row wire:key="stock-history-line-{{ $line->id }}">
                                    <flux:table.cell>{{ $line->movement->occurred_at->timezone(app(CurrentCompany::class)->company()->timezone)->format('d/m/Y H:i') }}</flux:table.cell>
                                    <flux:table.cell>
                                        <div class="space-y-1">
                                            <flux:badge :color="$line->movement->type->color()">{{ $line->movement->type->label() }}</flux:badge>
                                            @if ($line->movement->reversal_of_movement_id)
                                                <flux:text size="sm">Revierte #{{ $line->movement->reversal_of_movement_id }}</flux:text>
                                            @elseif ($reversal = $line->movement->reversals->first())
                                                <flux:text size="sm">Revertido por #{{ $reversal->id }}</flux:text>
                                            @endif
                                        </div>
                                    </flux:table.cell>
                                    <flux:table.cell align="end"><span class="font-semibold {{ bccomp($line->quantity, '0', 6) < 0 ? 'text-red-600 dark:text-red-400' : 'text-green-700 dark:text-green-400' }}">{{ $this->formatMovementQuantity($line->quantity) }} {{ $this->historyProduct->unit->symbol }}</span></flux:table.cell>
                                    <flux:table.cell align="end">{{ $this->formatQuantity($line->quantity_before) }}</flux:table.cell>
                                    <flux:table.cell align="end">{{ $this->formatQuantity($line->quantity_after) }}</flux:table.cell>
                                    <flux:table.cell align="end">{{ $currency->symbol }} {{ $this->formatMoney($line->unit_cost_base) }}</flux:table.cell>
                                    <flux:table.cell align="end">{{ $currency->symbol }} {{ $this->formatMoney($line->average_unit_cost_before_base) }}</flux:table.cell>
                                    <flux:table.cell align="end">{{ $currency->symbol }} {{ $this->formatMoney($line->average_unit_cost_after_base) }}</flux:table.cell>
                                    <flux:table.cell align="end">{{ $currency->symbol }} {{ $this->formatMoney($line->inventory_value_before_base) }}</flux:table.cell>
                                    <flux:table.cell align="end">{{ $currency->symbol }} {{ $this->formatMoney($line->inventory_value_after_base) }}</flux:table.cell>
                                    <flux:table.cell>{{ $line->movement->reason ?: 'Sin observación' }}</flux:table.cell>
                                    <flux:table.cell>{{ $line->movement->createdBy->user->name }}</flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="12"><div class="py-10 text-center"><flux:heading>Sin movimientos</flux:heading><flux:text>Este insumo todavía no tiene operaciones en el almacén seleccionado.</flux:text></div></flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
