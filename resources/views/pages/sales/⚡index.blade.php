<?php

use App\Actions\Sales\ConfirmSale;
use App\Actions\Sales\DeactivateSaleExtra;
use App\Actions\Sales\SaveSaleExtra;
use App\Enums\InventoryBehavior;
use App\Enums\ModuleCode;
use App\Enums\ProductItemType;
use App\Enums\SaleExtraType;
use App\Enums\SaleOrderStatus;
use App\Enums\SaleStatus;
use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleExtra;
use App\Models\Warehouse;
use App\Support\Authorization\CompanyAccess;
use App\Support\Tenancy\CurrentCompany;
use Carbon\Carbon;
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

new #[Title('Ventas')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $dateFilter = '';

    public string $statusFilter = '';

    public ?int $paymentMethodFilter = null;

    public string $customerName = '';

    public ?int $branchId = null;

    public ?int $warehouseId = null;

    /** @var array<int, array{product_id: int|null, quantity: string, customizations: array<int, array{product_id: int|null, quantity: string}>}> */
    public array $saleLines = [];

    /** @var array<int, array{payment_method_id: int|null, amount_base: string}> */
    public array $paymentLines = [];

    /** @var array<int, array{extra_id: int|null, quantity: string, unit_price_base: string}> */
    public array $extraLines = [];

    public string $orderStatus = 'reserved';

    public string $deliveryAt = '';

    public string $extraName = '';

    public string $extraPrice = '';

    public string $extraType = 'service';

    public ?int $editingExtraId = null;

    public bool $showSaleModal = false;

    public function mount(): void
    {
        $this->branchId = Branch::query()->where('is_active', true)->orderBy('name')->value('id');
        $this->selectDefaultWarehouse();
    }

    public function updatedSearch(): void { $this->resetPage(); }

    public function updatedDateFilter(): void { $this->resetPage(); }

    public function updatedStatusFilter(): void { $this->resetPage(); }

    public function updatedPaymentMethodFilter(): void { $this->resetPage(); }

    public function updatedBranchId(): void
    {
        $this->selectDefaultWarehouse();
    }

    public function openSale(): void
    {
        Gate::authorize('create', Sale::class);
        $this->resetValidation();
        $this->customerName = '';
        $this->branchId = Branch::query()->where('is_active', true)->orderBy('name')->value('id');
        $this->selectDefaultWarehouse();
        $this->saleLines = [['product_id' => null, 'quantity' => '1', 'customizations' => []]];
        $this->paymentLines = [];
        $this->extraLines = [];
        $this->orderStatus = SaleOrderStatus::Reserved->value;
        $this->deliveryAt = now(app(CurrentCompany::class)->company()->timezone)->addDay()->setTime(9, 0)->format('Y-m-d\TH:i');
        $this->showSaleModal = true;
        Flux::modal('new-sale')->show();
    }

    public function addSaleLine(): void
    {
        $this->saleLines[] = ['product_id' => null, 'quantity' => '1', 'customizations' => []];
    }

    public function addCustomization(int $lineIndex): void
    {
        $this->saleLines[$lineIndex]['customizations'][] = ['product_id' => null, 'quantity' => '1'];
    }

    public function removeCustomization(int $lineIndex, int $customizationIndex): void
    {
        unset($this->saleLines[$lineIndex]['customizations'][$customizationIndex]);
        $this->saleLines[$lineIndex]['customizations'] = array_values($this->saleLines[$lineIndex]['customizations']);
    }

    public function removeSaleLine(int $index): void
    {
        if (count($this->saleLines) > 1) {
            unset($this->saleLines[$index]);
            $this->saleLines = array_values($this->saleLines);
        }
    }

    public function addPaymentLine(): void
    {
        $remaining = bcsub($this->total, $this->paidTotal, 4);
        $this->paymentLines[] = [
            'payment_method_id' => null,
            'amount_base' => bccomp($remaining, '0', 4) > 0 ? $remaining : '',
        ];
    }

    public function removePaymentLine(int $index): void
    {
        unset($this->paymentLines[$index]);
        $this->paymentLines = array_values($this->paymentLines);
    }

    public function addExtraLine(): void
    {
        $this->extraLines[] = ['extra_id' => null, 'quantity' => '1', 'unit_price_base' => ''];
    }

    public function updatedExtraLines(mixed $value, string $key): void
    {
        if (! str_ends_with($key, '.extra_id')) {
            return;
        }

        $index = (int) explode('.', $key)[0];
        $extra = $this->extras->firstWhere('id', (int) $value);

        if ($extra instanceof SaleExtra) {
            $this->extraLines[$index]['unit_price_base'] = $extra->default_price_base;
        }
    }

    public function removeExtraLine(int $index): void
    {
        unset($this->extraLines[$index]);
        $this->extraLines = array_values($this->extraLines);
    }

    public function fillRemainingPayment(int $index): void
    {
        $otherPayments = collect($this->paymentLines)
            ->except($index)
            ->sum(fn (array $payment): float => is_numeric($payment['amount_base']) ? (float) $payment['amount_base'] : 0);
        $remaining = bcsub($this->total, (string) $otherPayments, 4);
        $this->paymentLines[$index]['amount_base'] = bccomp($remaining, '0', 4) > 0 ? $remaining : '0.0000';
    }

    public function confirmSale(): void
    {
        Gate::authorize('create', Sale::class);
        $companyId = app(CurrentCompany::class)->id();
        $data = $this->validate([
            'customerName' => ['nullable', 'string', 'max:255'],
            'branchId' => ['required', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'warehouseId' => ['required', Rule::exists('warehouses', 'id')->where('company_id', $companyId)],
            'saleLines' => ['required', 'array', 'min:1'],
            'saleLines.*.product_id' => ['required', Rule::exists('products', 'id')->where('company_id', $companyId)],
            'saleLines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'saleLines.*.customizations' => ['array'],
            'saleLines.*.customizations.*.product_id' => ['required', Rule::exists('products', 'id')->where('company_id', $companyId)->where('is_active', true)->where('is_sellable', false)],
            'saleLines.*.customizations.*.quantity' => ['required', 'numeric', 'gt:0'],
            'paymentLines' => ['array'],
            'paymentLines.*.payment_method_id' => ['required', Rule::exists('payment_methods', 'id')->where('company_id', $companyId)],
            'paymentLines.*.amount_base' => ['required', 'numeric', 'gt:0'],
            'extraLines' => ['array'],
            'extraLines.*.extra_id' => ['required', Rule::exists('sale_extras', 'id')->where('company_id', $companyId)->where('is_active', true)],
            'extraLines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'extraLines.*.unit_price_base' => ['required', 'numeric', 'gte:0'],
            'orderStatus' => ['required', Rule::enum(SaleOrderStatus::class)],
            'deliveryAt' => ['nullable', 'date', Rule::requiredIf($this->orderStatus === SaleOrderStatus::Reserved->value)],
        ], messages: [
            'saleLines.*.product_id.required' => 'Selecciona un ramo.',
            'saleLines.*.quantity.gt' => 'La cantidad debe ser mayor que cero.',
            'paymentLines.*.payment_method_id.required' => 'Selecciona el método de pago.',
            'paymentLines.*.amount_base.gt' => 'El importe debe ser mayor que cero.',
            'deliveryAt.required' => 'Indica la fecha y hora de entrega de la reserva.',
        ]);

        $branch = Branch::query()->whereKey($data['branchId'])->where('is_active', true)->firstOrFail();
        $warehouse = Warehouse::query()->whereKey($data['warehouseId'])->where('is_active', true)->firstOrFail();
        $products = Product::query()->whereIn('id', collect($data['saleLines'])->pluck('product_id'))->get()->keyBy('id');
        $customizationProducts = Product::query()->whereIn(
            'id',
            collect($data['saleLines'])->flatMap(fn (array $line): array => collect($line['customizations'] ?? [])->pluck('product_id')->all()),
        )->get()->keyBy('id');
        $methods = PaymentMethod::query()->whereIn('id', collect($data['paymentLines'])->pluck('payment_method_id'))->get()->keyBy('id');
        $extras = SaleExtra::query()->whereIn('id', collect($data['extraLines'])->pluck('extra_id'))->get()->keyBy('id');

        try {
            $sale = app(ConfirmSale::class)->handle(
                app(CurrentCompany::class)->membership(),
                $branch,
                $warehouse,
                collect($data['saleLines'])->map(fn (array $line): array => [
                    'product' => $products->get($line['product_id']),
                    'quantity' => $line['quantity'],
                    'customizations' => collect($line['customizations'] ?? [])->map(fn (array $customization): array => [
                        'product' => $customizationProducts->get($customization['product_id']),
                        'quantity' => $customization['quantity'],
                    ])->all(),
                ])->all(),
                collect($data['paymentLines'])->map(fn (array $payment): array => [
                    'payment_method' => $methods->get($payment['payment_method_id']),
                    'amount_base' => $payment['amount_base'],
                ])->all(),
                $data['customerName'],
                null,
                collect($data['extraLines'])->map(fn (array $line): array => [
                    'extra' => $extras->get($line['extra_id']),
                    'quantity' => $line['quantity'],
                    'unit_price_base' => $line['unit_price_base'],
                ])->all(),
                SaleOrderStatus::from($data['orderStatus']),
                $data['deliveryAt']
                    ? Carbon::parse($data['deliveryAt'], app(CurrentCompany::class)->company()->timezone)->utc()
                    : null,
            );
        } catch (\DomainException $exception) {
            $this->addError('sale', $exception->getMessage());

            return;
        }

        Flux::modal('new-sale')->close();
        Flux::toast(variant: 'success', text: "Venta {$sale->number} confirmada.");
        $this->redirectRoute('sales.show', ['saleId' => $sale->getKey()], navigate: true);
    }

    public function formatMoney(int|float|string $amount): string
    {
        return Number::format((float) $amount, precision: 2, locale: 'es');
    }

    public function formatQuantity(int|float|string $quantity): string
    {
        return Number::format((float) $quantity, maxPrecision: 6, locale: 'es');
    }

    #[Computed]
    public function canCreate(): bool
    {
        return app(CompanyAccess::class)->allowsCurrent(auth()->user(), 'sales.create')
            && app(CompanyAccess::class)->moduleEnabledCurrent(ModuleCode::Inventory);
    }

    #[Computed]
    public function canManageExtras(): bool
    {
        return app(CompanyAccess::class)->allowsCurrent(auth()->user(), 'sales.extras.manage');
    }

    #[Computed]
    public function canOverrideExtraPrice(): bool
    {
        return app(CompanyAccess::class)->allowsCurrent(auth()->user(), 'sales.extras.price.update');
    }

    #[Computed]
    public function canRegisterPayments(): bool
    {
        return app(CompanyAccess::class)->allowsCurrent(auth()->user(), 'sales.payments.create');
    }

    #[Computed]
    public function branches(): Collection
    {
        return Branch::query()->where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function warehouses(): Collection
    {
        return Warehouse::query()
            ->where('branch_id', $this->branchId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function bouquets(): Collection
    {
        return Product::query()
            ->where('is_active', true)
            ->where('is_sellable', true)
            ->where('item_type', ProductItemType::Physical)
            ->where('inventory_behavior', InventoryBehavior::Components)
            ->whereHas('recipes', fn ($query) => $query->where('active_slot', 1))
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function supplies(): Collection
    {
        return Product::query()
            ->with('unit:id,symbol')
            ->where('is_active', true)
            ->where('is_sellable', false)
            ->where('item_type', ProductItemType::Physical)
            ->where('inventory_behavior', InventoryBehavior::Self)
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function paymentMethods(): Collection
    {
        return PaymentMethod::query()->where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function extras(): Collection
    {
        return SaleExtra::query()->where('is_active', true)->orderBy('name')->get();
    }

    /** @return array<int, array{name: string, quantity: string, unit_price_base: string, subtotal_base: string}> */
    #[Computed]
    public function extraSummaryLines(): array
    {
        $extras = $this->extras->keyBy('id');

        return collect($this->extraLines)->map(function (array $line) use ($extras): ?array {
            $extra = $extras->get($line['extra_id']);

            if (! $extra instanceof SaleExtra || ! is_numeric($line['quantity']) || ! is_numeric($line['unit_price_base'])) {
                return null;
            }

            return [
                'name' => $extra->name,
                'quantity' => (string) $line['quantity'],
                'unit_price_base' => (string) $line['unit_price_base'],
                'subtotal_base' => bcadd(bcmul((string) $line['quantity'], (string) $line['unit_price_base'], 4), '0', 4),
            ];
        })->filter()->values()->all();
    }

    /** @return array<int, array{name: string, quantity: string, unit_price_base: string, subtotal_base: string}> */
    #[Computed]
    public function summaryLines(): array
    {
        $bouquets = $this->bouquets->keyBy('id');

        return collect($this->saleLines)->map(function (array $line) use ($bouquets): ?array {
            $bouquet = $bouquets->get($line['product_id']);

            if (! $bouquet instanceof Product || ! is_numeric($line['quantity']) || (float) $line['quantity'] <= 0) {
                return null;
            }

            return [
                'name' => $bouquet->name,
                'quantity' => (string) $line['quantity'],
                'unit_price_base' => $bouquet->sale_price_base,
                'subtotal_base' => bcadd(bcmul((string) $line['quantity'], $bouquet->sale_price_base, 4), '0', 4),
            ];
        })->filter()->values()->all();
    }

    #[Computed]
    public function total(): string
    {
        return collect([...$this->summaryLines, ...$this->extraSummaryLines])->reduce(
            fn (string $total, array $line): string => bcadd($total, $line['subtotal_base'], 4),
            '0.0000',
        );
    }

    #[Computed]
    public function paidTotal(): string
    {
        return collect($this->paymentLines)->reduce(
            fn (string $total, array $payment): string => is_numeric($payment['amount_base'])
                ? bcadd($total, (string) $payment['amount_base'], 4)
                : $total,
            '0.0000',
        );
    }

    #[Computed]
    public function sales(): LengthAwarePaginator
    {
        return Sale::query()
            ->with(['items:id,sale_id,product_name,quantity', 'payments:id,sale_id,payment_method_name', 'confirmedBy.user:id,name'])
            ->when($this->search, fn ($query) => $query->where(function ($search) {
                $search->where('number', 'like', '%'.$this->search.'%')
                    ->orWhere('customer_name', 'like', '%'.$this->search.'%')
                    ->orWhereHas('items', fn ($items) => $items->where('product_name', 'like', '%'.$this->search.'%'));
            }))
            ->when($this->dateFilter, fn ($query) => $query->whereDate('occurred_at', $this->dateFilter))
            ->when($this->statusFilter, fn ($query) => $query->where('status', $this->statusFilter))
            ->when($this->paymentMethodFilter, fn ($query) => $query->whereHas(
                'payments', fn ($payments) => $payments->where('payment_method_id', $this->paymentMethodFilter),
            ))
            ->latest('occurred_at')
            ->paginate(15);
    }

    public function openExtraManager(): void
    {
        Gate::authorize('create', SaleExtra::class);
        $this->reset(['extraName', 'extraPrice', 'editingExtraId']);
        $this->extraType = SaleExtraType::Service->value;
        Flux::modal('extra-manager')->show();
    }

    public function editExtra(int $extraId): void
    {
        $extra = SaleExtra::query()->findOrFail($extraId);
        Gate::authorize('update', $extra);
        $this->editingExtraId = $extra->getKey();
        $this->extraName = $extra->name;
        $this->extraPrice = $extra->default_price_base;
        $this->extraType = $extra->type->value;
    }

    public function saveExtra(): void
    {
        $extra = $this->editingExtraId ? SaleExtra::query()->findOrFail($this->editingExtraId) : null;
        $extra ? Gate::authorize('update', $extra) : Gate::authorize('create', SaleExtra::class);
        $data = $this->validate([
            'extraName' => ['required', 'string', 'max:255', Rule::unique('sale_extras', 'name')->where('company_id', app(CurrentCompany::class)->id())->ignore($this->editingExtraId)],
            'extraPrice' => ['required', 'numeric', 'gte:0'],
            'extraType' => ['required', Rule::enum(SaleExtraType::class)],
        ]);
        app(SaveSaleExtra::class)->handle(app(CurrentCompany::class)->membership(), $data['extraName'], $data['extraPrice'], SaleExtraType::from($data['extraType']), $extra);
        $this->reset(['extraName', 'extraPrice', 'editingExtraId']);
        $this->extraType = SaleExtraType::Service->value;
        unset($this->extras);
        Flux::toast(variant: 'success', text: $extra ? 'Extra actualizado.' : 'Extra creado.');
    }

    public function deactivateExtra(int $extraId): void
    {
        $extra = SaleExtra::query()->findOrFail($extraId);
        Gate::authorize('deactivate', $extra);
        app(DeactivateSaleExtra::class)->handle(app(CurrentCompany::class)->membership(), $extra);
        unset($this->extras);
        Flux::toast(variant: 'success', text: 'Extra desactivado. El historial no cambió.');
    }

    private function selectDefaultWarehouse(): void
    {
        $this->warehouseId = Warehouse::query()
            ->where('branch_id', $this->branchId)
            ->where('is_active', true)
            ->orderBy('name')
            ->value('id');
    }
};

?>

@php($company = app(CurrentCompany::class)->company())
@php($currency = $company->baseCurrency)

<div class="flex w-full flex-col gap-6">
    <div class="flex flex-col justify-between gap-4 md:flex-row md:items-center">
        <div><flux:heading size="xl">Ventas</flux:heading><flux:text>Registra las ventas de tus ramos y descuenta automáticamente los insumos utilizados.</flux:text></div>
        <div class="flex gap-2">@if ($this->canManageExtras)<flux:button wire:click="openExtraManager">Configurar extras</flux:button>@endif @if ($this->canCreate)<flux:button variant="primary" icon="plus" wire:click="openSale">Nueva venta</flux:button>@endif</div>
    </div>

    @if (! $this->canCreate)
        <flux:callout icon="information-circle" heading="Modo histórico">El módulo está deshabilitado o tu acceso solo permite consultar ventas anteriores.</flux:callout>
    @endif

    <flux:card class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
        <flux:input wire:model.live.debounce.300ms="search" label="Buscar" icon="magnifying-glass" placeholder="Número, cliente o ramo" />
        <flux:input wire:model.live="dateFilter" type="date" label="Fecha" />
        <flux:select wire:model.live="statusFilter" label="Estado"><flux:select.option value="">Todos</flux:select.option><flux:select.option value="confirmed">Confirmada</flux:select.option><flux:select.option value="voided">Anulada</flux:select.option></flux:select>
        <flux:select wire:model.live="paymentMethodFilter" label="Método de pago"><flux:select.option value="">Todos</flux:select.option>@foreach($this->paymentMethods as $method)<flux:select.option :value="$method->id">{{ $method->name }}</flux:select.option>@endforeach</flux:select>
    </flux:card>

    <flux:card class="overflow-hidden p-0!">
        <div class="overflow-x-auto"><flux:table :paginate="$this->sales"><flux:table.columns><flux:table.column>N.º venta</flux:table.column><flux:table.column>Fecha</flux:table.column><flux:table.column>Cliente</flux:table.column><flux:table.column>Ramos</flux:table.column><flux:table.column align="end">Total</flux:table.column><flux:table.column align="end">Pagado</flux:table.column><flux:table.column align="end">Saldo</flux:table.column><flux:table.column>Pedido</flux:table.column><flux:table.column>Entrega</flux:table.column><flux:table.column>Responsable</flux:table.column><flux:table.column></flux:table.column></flux:table.columns><flux:table.rows>
            @forelse($this->sales as $sale)
                <flux:table.row :key="$sale->id" class="{{ $sale->status === SaleStatus::Voided ? 'opacity-60' : '' }}"><flux:table.cell variant="strong">{{ $sale->number }} @if($sale->status === SaleStatus::Voided)<flux:badge color="red">Anulada</flux:badge>@endif</flux:table.cell><flux:table.cell>{{ $sale->occurred_at->timezone($company->timezone)->format('d/m/Y H:i') }}</flux:table.cell><flux:table.cell>{{ $sale->customer_name ?: 'Consumidor final' }}</flux:table.cell><flux:table.cell>{{ $sale->items->map(fn($item) => $this->formatQuantity($item->quantity).' '.$item->product_name)->join(', ') }}</flux:table.cell><flux:table.cell align="end">{{ $currency->symbol }} {{ $this->formatMoney($sale->total_base) }}</flux:table.cell><flux:table.cell align="end">{{ $currency->symbol }} {{ $this->formatMoney($sale->paid_total_base) }}<div><flux:badge :color="$sale->payment_status->color()">{{ $sale->payment_status->label() }}</flux:badge></div></flux:table.cell><flux:table.cell align="end">{{ $currency->symbol }} {{ $this->formatMoney(max(0, (float) $sale->balance_due_base)) }}</flux:table.cell><flux:table.cell>{{ $sale->order_status->label() }}</flux:table.cell><flux:table.cell>{{ $sale->delivery_at?->timezone($company->timezone)->format('d/m/Y H:i') ?? '—' }}</flux:table.cell><flux:table.cell>{{ $sale->confirmedBy->user->name }}</flux:table.cell><flux:table.cell><flux:button size="sm" variant="subtle" icon="eye" :href="route('sales.show', ['saleId' => $sale->id])" wire:navigate>Ver</flux:button></flux:table.cell></flux:table.row>
            @empty
                <flux:table.row><flux:table.cell colspan="11"><div class="py-12 text-center"><flux:heading>No hay ventas registradas</flux:heading><flux:text>Las ventas confirmadas aparecerán aquí.</flux:text></div></flux:table.cell></flux:table.row>
            @endforelse
        </flux:table.rows></flux:table></div>
    </flux:card>

    @if ($this->canCreate)
        <flux:modal name="new-sale" wire:model.self="showSaleModal" class="max-w-5xl">
            <form wire:submit="confirmSale" class="space-y-6">
                <div><flux:heading size="lg">Nueva venta</flux:heading><flux:text>Selecciona los ramos, confirma el pago y el sistema descontará sus insumos.</flux:text></div>
                @error('sale')<flux:callout variant="danger" icon="x-circle" heading="No se pudo confirmar la venta"><div class="whitespace-pre-line">{{ $message }}</div></flux:callout>@enderror
                <div class="grid gap-4 md:grid-cols-4"><flux:input wire:model="customerName" label="Cliente (opcional)" placeholder="Consumidor final" /><flux:select wire:model.live="branchId" label="Sucursal" required>@foreach($this->branches as $branch)<flux:select.option :value="$branch->id">{{ $branch->name }}</flux:select.option>@endforeach</flux:select><flux:select wire:model="warehouseId" label="Almacén" required>@foreach($this->warehouses as $warehouse)<flux:select.option :value="$warehouse->id">{{ $warehouse->name }}</flux:select.option>@endforeach</flux:select><flux:select wire:model.live="orderStatus" label="Estado del pedido" required>@foreach(SaleOrderStatus::cases() as $state)@if($state !== SaleOrderStatus::Cancelled)<flux:select.option :value="$state->value">{{ $state->label() }}</flux:select.option>@endif @endforeach</flux:select></div>
                @if($orderStatus === SaleOrderStatus::Reserved->value)<flux:input wire:model="deliveryAt" type="datetime-local" label="Fecha y hora de entrega" description="Obligatoria para pedidos reservados." required />@endif

                <div class="space-y-3"><div class="flex items-center justify-between"><flux:heading size="sm">Ramos</flux:heading><flux:button type="button" size="sm" icon="plus" wire:click="addSaleLine">Agregar otro ramo</flux:button></div>
                    @foreach($saleLines as $index => $line)
                        @php($summary = collect($this->summaryLines)->firstWhere('name', optional($this->bouquets->firstWhere('id', $line['product_id']))->name))
                        <flux:card class="space-y-4" wire:key="sale-line-{{ $index }}"><div class="grid items-end gap-3 md:grid-cols-[2fr_1fr_1fr_1fr_auto]"><flux:select wire:model.live="saleLines.{{ $index }}.product_id" label="Ramo" required><flux:select.option value="">Seleccionar ramo</flux:select.option>@foreach($this->bouquets as $bouquet)<flux:select.option :value="$bouquet->id">{{ $bouquet->name }}</flux:select.option>@endforeach</flux:select><flux:input wire:model.live.debounce.250ms="saleLines.{{ $index }}.quantity" type="number" min="0.000001" step="0.000001" label="Cantidad" required /><div><flux:text size="sm">Precio unitario</flux:text><div class="mt-2 font-semibold">{{ $currency->symbol }} {{ $this->formatMoney(optional($this->bouquets->firstWhere('id', $line['product_id']))->sale_price_base ?? 0) }}</div></div><div><flux:text size="sm">Subtotal</flux:text><div class="mt-2 font-semibold">{{ $currency->symbol }} {{ $this->formatMoney($summary['subtotal_base'] ?? 0) }}</div></div><flux:button type="button" variant="ghost" icon="trash" wire:click="removeSaleLine({{ $index }})" :disabled="count($saleLines) === 1" aria-label="Quitar ramo" /></div><div class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800/60"><div class="flex items-center justify-between"><div><flux:heading size="sm">Personalización del ramo</flux:heading><flux:text size="sm">Agrega insumos adicionales por cada ramo de esta línea. La receta original no cambiará.</flux:text></div><flux:button type="button" size="sm" variant="subtle" icon="plus" wire:click="addCustomization({{ $index }})">Agregar insumo</flux:button></div>@foreach(($line['customizations'] ?? []) as $customizationIndex => $customization)<div class="mt-3 grid items-end gap-3 sm:grid-cols-[2fr_1fr_auto]" wire:key="customization-{{ $index }}-{{ $customizationIndex }}"><flux:select wire:model="saleLines.{{ $index }}.customizations.{{ $customizationIndex }}.product_id" label="Insumo adicional" required><flux:select.option value="">Seleccionar</flux:select.option>@foreach($this->supplies as $supply)<flux:select.option :value="$supply->id">{{ $supply->name }} · {{ $supply->unit->symbol }}</flux:select.option>@endforeach</flux:select><flux:input wire:model="saleLines.{{ $index }}.customizations.{{ $customizationIndex }}.quantity" type="number" min="0.000001" step="0.000001" label="Cantidad por ramo" required /><flux:button type="button" variant="ghost" icon="trash" wire:click="removeCustomization({{ $index }}, {{ $customizationIndex }})" aria-label="Quitar personalización" /></div>@endforeach</div></flux:card>
                    @endforeach
                </div>

                <div class="space-y-3"><div class="flex items-center justify-between"><div><flux:heading size="sm">Extras</flux:heading><flux:text size="sm">Delivery, tarjetas, globos u otros adicionales.</flux:text></div><flux:button type="button" size="sm" icon="plus" wire:click="addExtraLine">Agregar extra</flux:button></div>@foreach($extraLines as $index => $line)<div class="grid items-end gap-3 md:grid-cols-[2fr_1fr_1fr_auto]" wire:key="extra-line-{{ $index }}"><flux:select wire:model.live="extraLines.{{ $index }}.extra_id" label="Extra" required><flux:select.option value="">Seleccionar</flux:select.option>@foreach($this->extras as $extra)<flux:select.option :value="$extra->id">{{ $extra->name }}</flux:select.option>@endforeach</flux:select><flux:input wire:model.live.debounce.250ms="extraLines.{{ $index }}.quantity" type="number" min="0.000001" step="0.000001" label="Cantidad" required /><flux:input wire:model.live.debounce.250ms="extraLines.{{ $index }}.unit_price_base" type="number" min="0" step="0.0001" label="Precio" :readonly="! $this->canOverrideExtraPrice" required /><flux:button type="button" variant="ghost" icon="trash" wire:click="removeExtraLine({{ $index }})" /></div>@endforeach</div>

                <div class="grid gap-6 lg:grid-cols-2"><div class="space-y-3"><div class="flex items-center justify-between"><div><flux:heading size="sm">Pago inicial (opcional)</flux:heading><flux:text size="sm">{{ $this->canRegisterPayments ? 'Puedes dejar la venta pendiente o cobrar una parte.' : 'La venta quedará pendiente; no tienes permiso para registrar cobros.' }}</flux:text></div>@if($this->canRegisterPayments)<flux:button type="button" size="sm" icon="plus" wire:click="addPaymentLine">Agregar pago</flux:button>@endif</div>@foreach($paymentLines as $index => $payment)<div class="grid items-end gap-3 sm:grid-cols-[1fr_1fr_auto]" wire:key="payment-line-{{ $index }}"><flux:select wire:model="paymentLines.{{ $index }}.payment_method_id" label="Método" required><flux:select.option value="">Seleccionar</flux:select.option>@foreach($this->paymentMethods as $method)<flux:select.option :value="$method->id">{{ $method->name }}</flux:select.option>@endforeach</flux:select><flux:input wire:model.live.debounce.250ms="paymentLines.{{ $index }}.amount_base" type="number" min="0.0001" step="0.0001" label="Importe" required><x-slot name="append"><flux:button type="button" size="sm" variant="subtle" wire:click="fillRemainingPayment({{ $index }})">Completar</flux:button></x-slot></flux:input><flux:button type="button" variant="ghost" icon="trash" wire:click="removePaymentLine({{ $index }})" /></div>@endforeach</div>
                    <flux:card class="space-y-3 bg-zinc-50 dark:bg-zinc-800/60"><flux:heading size="sm">Resumen</flux:heading>@foreach([...$this->summaryLines, ...$this->extraSummaryLines] as $line)<div class="flex justify-between gap-3"><span>{{ $line['name'] }} · {{ $this->formatQuantity($line['quantity']) }} × {{ $currency->symbol }} {{ $this->formatMoney($line['unit_price_base']) }}</span><strong>{{ $currency->symbol }} {{ $this->formatMoney($line['subtotal_base']) }}</strong></div>@endforeach<div class="flex justify-between border-t border-zinc-200 pt-3 text-lg dark:border-zinc-700"><strong>Total</strong><strong>{{ $currency->symbol }} {{ $this->formatMoney($this->total) }}</strong></div><div class="flex justify-between"><span>Pagado ahora</span><span>{{ $currency->symbol }} {{ $this->formatMoney($this->paidTotal) }}</span></div><div class="flex justify-between"><span>Saldo pendiente</span><strong>{{ $currency->symbol }} {{ $this->formatMoney(max(0, (float) $this->total - (float) $this->paidTotal)) }}</strong></div>@if(bccomp($this->paidTotal, $this->total, 4) === 1)<flux:text class="text-red-600 dark:text-red-400">El pago no puede superar el total.</flux:text>@endif</flux:card></div>
                <div class="flex justify-end gap-3"><flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close><flux:button type="submit" variant="primary" :disabled="bccomp($this->total, '0', 4) <= 0 || bccomp($this->paidTotal, $this->total, 4) === 1">Confirmar venta</flux:button></div>
            </form>
        </flux:modal>
    @endif

    @if ($this->canManageExtras)<flux:modal name="extra-manager" class="max-w-2xl"><div class="space-y-5"><div><flux:heading size="lg">Extras de venta</flux:heading><flux:text>Configura servicios y productos adicionales. Por ahora los extras tipo producto no descuentan inventario.</flux:text></div><div class="max-h-64 space-y-2 overflow-y-auto">@forelse($this->extras as $extra)<div class="flex items-center justify-between rounded-lg border border-zinc-200 p-3 dark:border-zinc-700"><div><strong>{{ $extra->name }}</strong><flux:text size="sm">{{ $extra->type->label() }} · {{ $currency->symbol }} {{ $this->formatMoney($extra->default_price_base) }}</flux:text></div><div class="flex gap-1"><flux:button size="sm" variant="ghost" wire:click="editExtra({{ $extra->id }})">Editar</flux:button><flux:button size="sm" variant="ghost" wire:click="deactivateExtra({{ $extra->id }})" wire:confirm="¿Desactivar este extra?">Desactivar</flux:button></div></div>@empty<flux:text>No hay extras configurados.</flux:text>@endforelse</div><form wire:submit="saveExtra" class="grid items-end gap-3 md:grid-cols-[2fr_1fr_1fr_auto]"><flux:input wire:model="extraName" label="Nombre" required /><flux:input wire:model="extraPrice" type="number" min="0" step="0.0001" label="Precio" required /><flux:select wire:model="extraType" label="Tipo" required>@foreach(SaleExtraType::cases() as $type)<flux:select.option :value="$type->value">{{ $type->label() }}</flux:select.option>@endforeach</flux:select><flux:button type="submit" variant="primary">{{ $editingExtraId ? 'Guardar' : 'Agregar' }}</flux:button></form></div></flux:modal>@endif
</div>
