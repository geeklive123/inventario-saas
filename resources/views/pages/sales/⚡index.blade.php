<?php

use App\Actions\Sales\ConfirmSale;
use App\Enums\InventoryBehavior;
use App\Enums\ModuleCode;
use App\Enums\ProductItemType;
use App\Enums\SaleStatus;
use App\Models\Branch;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Warehouse;
use App\Support\Authorization\CompanyAccess;
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

    /** @var array<int, array{product_id: int|null, quantity: string}> */
    public array $saleLines = [];

    /** @var array<int, array{payment_method_id: int|null, amount_base: string}> */
    public array $paymentLines = [];

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
        $this->saleLines = [['product_id' => null, 'quantity' => '1']];
        $this->paymentLines = [[
            'payment_method_id' => $this->paymentMethods->first()?->getKey(),
            'amount_base' => '',
        ]];
        $this->showSaleModal = true;
        Flux::modal('new-sale')->show();
    }

    public function addSaleLine(): void
    {
        $this->saleLines[] = ['product_id' => null, 'quantity' => '1'];
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
        if (count($this->paymentLines) > 1) {
            unset($this->paymentLines[$index]);
            $this->paymentLines = array_values($this->paymentLines);
        }
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
            'paymentLines' => ['required', 'array', 'min:1'],
            'paymentLines.*.payment_method_id' => ['required', Rule::exists('payment_methods', 'id')->where('company_id', $companyId)],
            'paymentLines.*.amount_base' => ['required', 'numeric', 'gt:0'],
        ], messages: [
            'saleLines.*.product_id.required' => 'Selecciona un ramo.',
            'saleLines.*.quantity.gt' => 'La cantidad debe ser mayor que cero.',
            'paymentLines.*.payment_method_id.required' => 'Selecciona el método de pago.',
            'paymentLines.*.amount_base.gt' => 'El importe debe ser mayor que cero.',
        ]);

        $branch = Branch::query()->whereKey($data['branchId'])->where('is_active', true)->firstOrFail();
        $warehouse = Warehouse::query()->whereKey($data['warehouseId'])->where('is_active', true)->firstOrFail();
        $products = Product::query()->whereIn('id', collect($data['saleLines'])->pluck('product_id'))->get()->keyBy('id');
        $methods = PaymentMethod::query()->whereIn('id', collect($data['paymentLines'])->pluck('payment_method_id'))->get()->keyBy('id');

        try {
            $sale = app(ConfirmSale::class)->handle(
                app(CurrentCompany::class)->membership(),
                $branch,
                $warehouse,
                collect($data['saleLines'])->map(fn (array $line): array => [
                    'product' => $products->get($line['product_id']),
                    'quantity' => $line['quantity'],
                ])->all(),
                collect($data['paymentLines'])->map(fn (array $payment): array => [
                    'payment_method' => $methods->get($payment['payment_method_id']),
                    'amount_base' => $payment['amount_base'],
                ])->all(),
                $data['customerName'],
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
    public function paymentMethods(): Collection
    {
        return PaymentMethod::query()->where('is_active', true)->orderBy('name')->get();
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
        return collect($this->summaryLines)->reduce(
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
        @if ($this->canCreate)<flux:button variant="primary" icon="plus" wire:click="openSale">Nueva venta</flux:button>@endif
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
        <div class="overflow-x-auto"><flux:table :paginate="$this->sales"><flux:table.columns><flux:table.column>N.º venta</flux:table.column><flux:table.column>Fecha</flux:table.column><flux:table.column>Cliente</flux:table.column><flux:table.column>Ramos</flux:table.column><flux:table.column align="end">Total</flux:table.column><flux:table.column>Pago</flux:table.column><flux:table.column>Estado</flux:table.column><flux:table.column>Responsable</flux:table.column><flux:table.column></flux:table.column></flux:table.columns><flux:table.rows>
            @forelse($this->sales as $sale)
                <flux:table.row :key="$sale->id"><flux:table.cell variant="strong">{{ $sale->number }}</flux:table.cell><flux:table.cell>{{ $sale->occurred_at->timezone($company->timezone)->format('d/m/Y H:i') }}</flux:table.cell><flux:table.cell>{{ $sale->customer_name ?: 'Consumidor final' }}</flux:table.cell><flux:table.cell>{{ $sale->items->map(fn($item) => $this->formatQuantity($item->quantity).' '.$item->product_name)->join(', ') }}</flux:table.cell><flux:table.cell align="end">{{ $currency->symbol }} {{ $this->formatMoney($sale->total_base) }}</flux:table.cell><flux:table.cell>{{ $sale->payments->count() > 1 ? 'Mixto' : $sale->payments->first()?->payment_method_name }}</flux:table.cell><flux:table.cell><flux:badge :color="$sale->status === SaleStatus::Confirmed ? 'green' : 'red'">{{ $sale->status === SaleStatus::Confirmed ? 'Confirmada' : 'Anulada' }}</flux:badge></flux:table.cell><flux:table.cell>{{ $sale->confirmedBy->user->name }}</flux:table.cell><flux:table.cell><flux:button size="sm" variant="subtle" icon="eye" :href="route('sales.show', ['saleId' => $sale->id])" wire:navigate>Ver</flux:button></flux:table.cell></flux:table.row>
            @empty
                <flux:table.row><flux:table.cell colspan="9"><div class="py-12 text-center"><flux:heading>No hay ventas registradas</flux:heading><flux:text>Las ventas confirmadas aparecerán aquí.</flux:text></div></flux:table.cell></flux:table.row>
            @endforelse
        </flux:table.rows></flux:table></div>
    </flux:card>

    @if ($this->canCreate)
        <flux:modal name="new-sale" wire:model.self="showSaleModal" class="max-w-5xl">
            <form wire:submit="confirmSale" class="space-y-6">
                <div><flux:heading size="lg">Nueva venta</flux:heading><flux:text>Selecciona los ramos, confirma el pago y el sistema descontará sus insumos.</flux:text></div>
                @error('sale')<flux:callout variant="danger" icon="x-circle" heading="No se pudo confirmar la venta"><div class="whitespace-pre-line">{{ $message }}</div></flux:callout>@enderror
                <div class="grid gap-4 md:grid-cols-3"><flux:input wire:model="customerName" label="Cliente (opcional)" placeholder="Consumidor final" /><flux:select wire:model.live="branchId" label="Sucursal" required>@foreach($this->branches as $branch)<flux:select.option :value="$branch->id">{{ $branch->name }}</flux:select.option>@endforeach</flux:select><flux:select wire:model="warehouseId" label="Almacén" required>@foreach($this->warehouses as $warehouse)<flux:select.option :value="$warehouse->id">{{ $warehouse->name }}</flux:select.option>@endforeach</flux:select></div>

                <div class="space-y-3"><div class="flex items-center justify-between"><flux:heading size="sm">Ramos</flux:heading><flux:button type="button" size="sm" icon="plus" wire:click="addSaleLine">Agregar otro ramo</flux:button></div>
                    @foreach($saleLines as $index => $line)
                        @php($summary = collect($this->summaryLines)->firstWhere('name', optional($this->bouquets->firstWhere('id', $line['product_id']))->name))
                        <flux:card class="grid items-end gap-3 md:grid-cols-[2fr_1fr_1fr_1fr_auto]" wire:key="sale-line-{{ $index }}"><flux:select wire:model.live="saleLines.{{ $index }}.product_id" label="Ramo" required><flux:select.option value="">Seleccionar ramo</flux:select.option>@foreach($this->bouquets as $bouquet)<flux:select.option :value="$bouquet->id">{{ $bouquet->name }}</flux:select.option>@endforeach</flux:select><flux:input wire:model.live.debounce.250ms="saleLines.{{ $index }}.quantity" type="number" min="0.000001" step="0.000001" label="Cantidad" required /><div><flux:text size="sm">Precio unitario</flux:text><div class="mt-2 font-semibold">{{ $currency->symbol }} {{ $this->formatMoney(optional($this->bouquets->firstWhere('id', $line['product_id']))->sale_price_base ?? 0) }}</div></div><div><flux:text size="sm">Subtotal</flux:text><div class="mt-2 font-semibold">{{ $currency->symbol }} {{ $this->formatMoney($summary['subtotal_base'] ?? 0) }}</div></div><flux:button type="button" variant="ghost" icon="trash" wire:click="removeSaleLine({{ $index }})" :disabled="count($saleLines) === 1" /></flux:card>
                    @endforeach
                </div>

                <div class="grid gap-6 lg:grid-cols-2"><div class="space-y-3"><div class="flex items-center justify-between"><flux:heading size="sm">Pago</flux:heading><flux:button type="button" size="sm" icon="plus" wire:click="addPaymentLine">Dividir pago</flux:button></div>@foreach($paymentLines as $index => $payment)<div class="grid items-end gap-3 sm:grid-cols-[1fr_1fr_auto]" wire:key="payment-line-{{ $index }}"><flux:select wire:model="paymentLines.{{ $index }}.payment_method_id" label="Método" required><flux:select.option value="">Seleccionar</flux:select.option>@foreach($this->paymentMethods as $method)<flux:select.option :value="$method->id">{{ $method->name }}</flux:select.option>@endforeach</flux:select><flux:input wire:model.live.debounce.250ms="paymentLines.{{ $index }}.amount_base" type="number" min="0.0001" step="0.0001" label="Importe" required><x-slot name="append"><flux:button type="button" size="sm" variant="subtle" wire:click="fillRemainingPayment({{ $index }})">Completar</flux:button></x-slot></flux:input><flux:button type="button" variant="ghost" icon="trash" wire:click="removePaymentLine({{ $index }})" :disabled="count($paymentLines) === 1" /></div>@endforeach</div>
                    <flux:card class="space-y-3 bg-zinc-50 dark:bg-zinc-800/60"><flux:heading size="sm">Resumen</flux:heading>@foreach($this->summaryLines as $line)<div class="flex justify-between gap-3"><span>{{ $line['name'] }} · {{ $this->formatQuantity($line['quantity']) }} × {{ $currency->symbol }} {{ $this->formatMoney($line['unit_price_base']) }}</span><strong>{{ $currency->symbol }} {{ $this->formatMoney($line['subtotal_base']) }}</strong></div>@endforeach<div class="flex justify-between border-t border-zinc-200 pt-3 text-lg dark:border-zinc-700"><strong>Total</strong><strong>{{ $currency->symbol }} {{ $this->formatMoney($this->total) }}</strong></div><div class="flex justify-between"><span>Pagado</span><span>{{ $currency->symbol }} {{ $this->formatMoney($this->paidTotal) }}</span></div>@if(bccomp($this->paidTotal, $this->total, 4) !== 0)<flux:text class="text-amber-600 dark:text-amber-400">Los pagos deben sumar exactamente el total.</flux:text>@endif</flux:card></div>
                <div class="flex justify-end gap-3"><flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close><flux:button type="submit" variant="primary" :disabled="bccomp($this->total, '0', 4) <= 0 || bccomp($this->paidTotal, $this->total, 4) !== 0">Confirmar venta</flux:button></div>
            </form>
        </flux:modal>
    @endif
</div>
