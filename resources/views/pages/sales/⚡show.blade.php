<?php

use App\Actions\Sales\RegisterSalePayment;
use App\Actions\Sales\UpdateSaleOrderStatus;
use App\Actions\Sales\VoidSale;
use App\Enums\SaleOrderStatus;
use App\Enums\SaleInventoryPendingStatus;
use App\Enums\SaleInventoryStatus;
use App\Enums\SaleStatus;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Support\Tenancy\CurrentCompany;
use Carbon\Carbon;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Detalle de venta')] class extends Component
{
    public int $saleId;

    public string $voidReason = '';

    public ?int $paymentMethodId = null;

    public string $paymentAmount = '';

    public string $paymentOccurredAt = '';

    public string $orderStatus = '';

    public string $deliveryAt = '';

    public function mount(int $saleId): void
    {
        $this->saleId = $saleId;
        Gate::authorize('view', $this->sale);
        $this->orderStatus = $this->sale->order_status->value;
        $this->deliveryAt = $this->sale->delivery_at?->timezone(app(CurrentCompany::class)->company()->timezone)->format('Y-m-d\TH:i') ?? '';
    }

    public function openVoid(): void
    {
        Gate::authorize('void', $this->sale);
        $this->voidReason = '';
        Flux::modal('void-sale')->show();
    }

    public function voidSale(): void
    {
        $sale = $this->sale;
        Gate::authorize('void', $sale);
        $this->validate(['voidReason' => ['required', 'string', 'max:1000']]);

        try {
            app(VoidSale::class)->handle(app(CurrentCompany::class)->membership(), $sale, $this->voidReason);
        } catch (\DomainException $exception) {
            $this->addError('voidReason', $exception->getMessage());

            return;
        }

        unset($this->sale);
        Flux::modal('void-sale')->close();
        Flux::toast(variant: 'success', text: 'Venta anulada y existencias recuperadas.');
    }

    public function openPayment(): void
    {
        Gate::authorize('registerPayment', $this->sale);
        $this->resetValidation();
        $this->paymentMethodId = $this->paymentMethods->first()?->getKey();
        $this->paymentAmount = $this->sale->balance_due_base;
        $this->paymentOccurredAt = now(app(CurrentCompany::class)->company()->timezone)->format('Y-m-d\TH:i');
        Flux::modal('register-payment')->show();
    }

    public function registerPayment(): void
    {
        $sale = $this->sale;
        Gate::authorize('registerPayment', $sale);
        $company = app(CurrentCompany::class)->company();
        $data = $this->validate([
            'paymentMethodId' => ['required', Rule::exists('payment_methods', 'id')->where('company_id', $company->getKey())->where('is_active', true)],
            'paymentAmount' => ['required', 'numeric', 'gt:0'],
            'paymentOccurredAt' => ['required', 'date'],
        ]);

        try {
            app(RegisterSalePayment::class)->handle(
                app(CurrentCompany::class)->membership(),
                $sale,
                PaymentMethod::query()->findOrFail($data['paymentMethodId']),
                $data['paymentAmount'],
                Carbon::parse($data['paymentOccurredAt'], $company->timezone)->utc(),
            );
        } catch (\DomainException $exception) {
            $field = $exception->getMessage() === 'La fecha y hora del pago no puede ser futura.'
                ? 'paymentOccurredAt'
                : 'paymentAmount';
            $this->addError($field, $exception->getMessage());

            return;
        }

        unset($this->sale);
        Flux::modal('register-payment')->close();
        Flux::toast(variant: 'success', text: 'Pago registrado correctamente.');
    }

    public function updateOrderStatus(): void
    {
        $sale = $this->sale;

        try {
            Gate::authorize('updateOrderStatus', $sale);
            $this->validate([
                'orderStatus' => ['required', Rule::enum(SaleOrderStatus::class)],
                'deliveryAt' => ['nullable', 'date', Rule::requiredIf($this->orderStatus === SaleOrderStatus::Reserved->value)],
            ], ['deliveryAt.required' => 'Indica la fecha y hora de entrega de la reserva.']);
            app(UpdateSaleOrderStatus::class)->handle(
                app(CurrentCompany::class)->membership(),
                $sale,
                SaleOrderStatus::from($this->orderStatus),
                $this->deliveryAt === '' ? null : Carbon::parse($this->deliveryAt, app(CurrentCompany::class)->company()->timezone)->utc(),
            );
        } catch (AuthorizationException) {
            $this->addError('orderStatus', 'No tienes permiso para cambiar el estado de este pedido.');

            return;
        } catch (\DomainException $exception) {
            $message = $exception->getMessage() === 'Sale payment totals are inconsistent.'
                ? 'No se pudo actualizar el pedido porque sus importes pagados y pendientes no coinciden. Revisa los pagos registrados o solicita ayuda al administrador.'
                : $exception->getMessage();
            $this->addError('orderStatus', $message);

            return;
        }

        unset($this->sale);
        $this->deliveryAt = $this->sale->delivery_at?->timezone(app(CurrentCompany::class)->company()->timezone)->format('Y-m-d\TH:i') ?? '';
        Flux::toast(variant: 'success', text: 'Estado del pedido actualizado.');
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
    public function sale(): Sale
    {
        return Sale::query()->with([
            'items.components', 'extraLines', 'payments.receivedBy.user', 'confirmedBy.user', 'voidedBy.user',
            'stockMovementLinks.stockMovement.lines.product',
            'inventoryPendings.regularizationLines.actualProduct',
        ])->findOrFail($this->saleId);
    }

    #[Computed]
    public function canVoid(): bool
    {
        return auth()->user()->can('void', $this->sale);
    }

    #[Computed]
    public function canRegisterPayment(): bool
    {
        return auth()->user()->can('registerPayment', $this->sale);
    }

    #[Computed]
    public function canViewBalance(): bool
    {
        return auth()->user()->can('viewBalance', $this->sale);
    }

    #[Computed]
    public function canUpdateOrderStatus(): bool
    {
        return auth()->user()->can('updateOrderStatus', $this->sale);
    }

    #[Computed]
    public function canRegularizeInventory(): bool
    {
        $pending = $this->sale->inventoryPendings
            ->firstWhere('status', SaleInventoryPendingStatus::Pending);

        return $pending !== null && auth()->user()->can('update', $pending);
    }

    #[Computed]
    public function paymentMethods(): Collection
    {
        return PaymentMethod::query()->where('is_active', true)->orderBy('name')->get();
    }

    /** @return array<int, array{name: string, sku: string, unit_symbol: string, quantity: numeric-string, cost: numeric-string}> */
    #[Computed]
    public function consumedComponents(): array
    {
        $automatic = $this->sale->items->flatMap->components
            ->filter(fn ($component): bool => bccomp($component->quantity_consumed, '0', 6) === 1)
            ->map(fn ($component): array => [
                'key' => 'product-'.$component->product_id,
                'name' => $component->product_name,
                'sku' => $component->product_sku,
                'unit_symbol' => $component->unit_symbol,
                'quantity' => $component->quantity_consumed,
                'cost' => $component->total_cost_base,
            ]);
        $regularized = $this->sale->inventoryPendings->flatMap->regularizationLines
            ->map(fn ($line): array => [
                'key' => 'product-'.$line->actual_product_id,
                'name' => $line->actual_product_name,
                'sku' => $line->actual_product_sku,
                'unit_symbol' => $line->unit_symbol,
                'quantity' => $line->quantity,
                'cost' => $line->total_cost_base,
            ]);

        return $automatic->merge($regularized)
            ->groupBy('key')
            ->map(function ($components): array {
                $first = $components->first();

                return [
                    'name' => $first['name'],
                    'sku' => $first['sku'],
                    'unit_symbol' => $first['unit_symbol'],
                    'quantity' => $components->reduce(fn (string $total, array $component): string => bcadd($total, $component['quantity'], 6), '0.000000'),
                    'cost' => $components->reduce(fn (string $total, array $component): string => bcadd($total, $component['cost'], 4), '0.0000'),
                ];
            })->values()->all();
    }
};

?>

@php($sale = $this->sale)
@php($company = app(CurrentCompany::class)->company())
@php($currency = $company->baseCurrency)
@php($saleOccurredAt = $sale->occurred_at->timezone($company->timezone))
@php($saleCreatedAt = $sale->created_at->timezone($company->timezone))

<div class="flex w-full flex-col gap-6">
    <div class="flex flex-col justify-between gap-4 md:flex-row md:items-center"><div><flux:button variant="subtle" icon="arrow-left" :href="route('sales.index')" wire:navigate class="mb-3">Volver a ventas</flux:button><flux:heading size="xl">Venta {{ $sale->number }}</flux:heading><flux:text>{{ $sale->status === SaleStatus::Confirmed ? 'Venta confirmada' : 'Venta anulada' }}</flux:text></div><div class="flex gap-2">@if($this->canRegisterPayment)<flux:button variant="primary" icon="banknotes" wire:click="openPayment">Registrar pago</flux:button>@endif @if($this->canVoid)<flux:button variant="danger" icon="x-circle" wire:click="openVoid">Anular venta</flux:button>@endif</div></div>

    @if($sale->status === SaleStatus::Voided)<flux:callout variant="danger" icon="x-circle" heading="Venta anulada">{{ $sale->void_reason }} · {{ $sale->voided_at?->timezone($company->timezone)->format('d/m/Y H:i') }} · {{ $sale->voidedBy?->user?->name }}</flux:callout>@endif

    @if($sale->inventory_status === SaleInventoryStatus::PendingRegularization)
        <flux:callout variant="warning" icon="exclamation-triangle" heading="Inventario pendiente de regularizar">
            <div class="space-y-3">
                <p>{{ $sale->items->flatMap->components->filter(fn($component) => bccomp($component->quantity_consumed, '0', 6) === 1)->count() }} insumos fueron descontados automáticamente. {{ $sale->inventoryPendings->where('status', SaleInventoryPendingStatus::Pending)->count() }} insumos están pendientes.</p>
                <div class="grid gap-2 sm:grid-cols-2">@foreach($sale->inventoryPendings->where('status', SaleInventoryPendingStatus::Pending) as $pending)<div class="flex justify-between rounded-lg bg-amber-50 p-2 dark:bg-amber-950/30"><span>{{ $pending->original_component_name }}</span><strong>{{ $this->formatQuantity($pending->required_quantity) }} {{ $pending->unit_symbol }}</strong></div>@endforeach</div>
                @if($this->canRegularizeInventory)<flux:button variant="primary" :href="route('inventory.regularizations.show', $sale->inventoryPendings->firstWhere('status', SaleInventoryPendingStatus::Pending)->id)" wire:navigate>Regularizar inventario</flux:button>@endif
            </div>
        </flux:callout>
    @endif

    <flux:card class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3"><div><flux:text size="sm">Fecha de venta</flux:text><div class="font-semibold">{{ $saleOccurredAt->format('d/m/Y H:i') }}</div>@if($saleOccurredAt->toDateString() !== $saleCreatedAt->toDateString())<flux:badge color="amber" class="mt-2">Venta registrada posteriormente</flux:badge>@endif</div><div><flux:text size="sm">Registrada el</flux:text><div class="font-semibold">{{ $saleCreatedAt->format('d/m/Y H:i') }}</div></div><div><flux:text size="sm">Registrada por</flux:text><div class="font-semibold">{{ $sale->confirmedBy->user->name }}</div></div><div><flux:text size="sm">Cliente</flux:text><div class="font-semibold">{{ $sale->customer_name ?: 'Consumidor final' }}</div></div><div><flux:text size="sm">Sucursal</flux:text><div class="font-semibold">{{ $sale->branch_name }}</div></div><div><flux:text size="sm">Estado del pago</flux:text><flux:badge :color="$sale->payment_status->color()">{{ $sale->payment_status->label() }}</flux:badge></div><div><flux:text size="sm">Estado del inventario</flux:text><flux:badge :color="$sale->inventory_status->color()">{{ $sale->inventory_status->label() }}</flux:badge></div><div>@if($this->canUpdateOrderStatus)<form wire:submit="updateOrderStatus" class="space-y-2">@error('orderStatus')<flux:callout variant="danger" icon="x-circle" :heading="$message" />@enderror<flux:select wire:model.live="orderStatus" label="Estado del pedido" required>@foreach(SaleOrderStatus::cases() as $state)@if($state !== SaleOrderStatus::Cancelled)<flux:select.option :value="$state->value">{{ $state->label() }}</flux:select.option>@endif @endforeach</flux:select><flux:input wire:model="deliveryAt" type="datetime-local" label="Fecha y hora de entrega" :required="$orderStatus === SaleOrderStatus::Reserved->value" /><flux:button size="sm" type="submit" wire:loading.attr="disabled">Actualizar</flux:button></form>@else<flux:text size="sm">Estado del pedido</flux:text><strong>{{ $sale->order_status->label() }}</strong><flux:text size="sm" class="mt-2">Entrega: {{ $sale->delivery_at?->timezone($company->timezone)->format('d/m/Y H:i') ?? 'No indicada' }}</flux:text>@endif</div></flux:card>

    <div class="grid gap-6 xl:grid-cols-2"><flux:card><flux:heading size="sm" class="mb-4">Ramos y extras</flux:heading><div class="space-y-3">@foreach($sale->items as $item)<div class="flex justify-between gap-3 border-b border-zinc-200 pb-3 dark:border-zinc-700"><div><strong>{{ $item->product_name }}</strong><flux:text size="sm">{{ $this->formatQuantity($item->quantity) }} × {{ $currency->symbol }} {{ $this->formatMoney($item->unit_price_base) }}</flux:text>@if($item->components->where('customization_quantity', '>', 0)->isNotEmpty())<div class="mt-2 space-y-2"><flux:text size="sm" class="font-medium">Personalización del ramo</flux:text>@foreach($item->components->where('customization_quantity', '>', 0) as $component)<div class="rounded-lg bg-zinc-50 p-2 dark:bg-zinc-800/60"><div class="flex flex-wrap justify-between gap-2 text-sm"><span>+ {{ $component->product_name }} × {{ $this->formatQuantity($component->customization_quantity) }} por ramo</span><strong>{{ $currency->symbol }} {{ $this->formatMoney($component->customization_unit_price_base) }} c/u · {{ $currency->symbol }} {{ $this->formatMoney($component->customization_total_price_base) }}</strong></div>@if($component->customization_note)<flux:text size="sm">“{{ $component->customization_note }}”</flux:text>@endif</div>@endforeach</div>@endif</div><strong>{{ $currency->symbol }} {{ $this->formatMoney($item->subtotal_base) }}</strong></div>@endforeach @foreach($sale->extraLines as $extra)<div class="flex justify-between gap-3 border-b border-zinc-200 pb-3 dark:border-zinc-700"><div><strong>{{ $extra->extra_name }}</strong><flux:text size="sm">Extra · {{ $this->formatQuantity($extra->quantity) }} × {{ $currency->symbol }} {{ $this->formatMoney($extra->unit_price_base) }}</flux:text></div><strong>{{ $currency->symbol }} {{ $this->formatMoney($extra->subtotal_base) }}</strong></div>@endforeach<div class="flex justify-between pt-2 text-lg"><strong>Total</strong><strong>{{ $currency->symbol }} {{ $this->formatMoney($sale->total_base) }}</strong></div></div></flux:card>
        <flux:card><div class="mb-4 flex items-center justify-between"><flux:heading size="sm">Historial de pagos</flux:heading><flux:badge :color="$sale->payment_status->color()">{{ $sale->payment_status->label() }}</flux:badge></div><div class="space-y-3">@forelse($sale->payments as $payment)<div class="flex justify-between gap-3"><div><strong>{{ $payment->payment_method_name }}</strong><flux:text size="sm">{{ $payment->occurred_at?->timezone($company->timezone)->format('d/m/Y H:i') }} · {{ $payment->receivedBy?->user?->name ?? 'Responsable histórico' }}</flux:text></div><strong>{{ $currency->symbol }} {{ $this->formatMoney($payment->amount_base) }}</strong></div>@empty<flux:text>Sin pagos registrados.</flux:text>@endforelse</div>@if($this->canViewBalance)<div class="mt-5 grid gap-4 border-t border-zinc-200 pt-4 sm:grid-cols-3 dark:border-zinc-700"><div><flux:text size="sm">Total</flux:text><strong>{{ $currency->symbol }} {{ $this->formatMoney($sale->total_base) }}</strong></div><div><flux:text size="sm">Pagado</flux:text><strong>{{ $currency->symbol }} {{ $this->formatMoney($sale->paid_total_base) }}</strong></div><div><flux:text size="sm">Saldo</flux:text><strong>{{ $currency->symbol }} {{ $this->formatMoney($sale->balance_due_base) }}</strong></div></div>@endif</flux:card></div>

    <flux:card><flux:heading size="sm" class="mb-4">Insumos utilizados</flux:heading><div class="overflow-x-auto"><flux:table><flux:table.columns><flux:table.column>Insumo</flux:table.column><flux:table.column align="end">Cantidad</flux:table.column><flux:table.column align="end">Costo consumido</flux:table.column></flux:table.columns><flux:table.rows>@foreach($this->consumedComponents as $component)<flux:table.row><flux:table.cell variant="strong">{{ $component['name'] }}<flux:text size="sm">{{ $component['sku'] }}</flux:text></flux:table.cell><flux:table.cell align="end">{{ $this->formatQuantity($component['quantity']) }} {{ $component['unit_symbol'] }}</flux:table.cell><flux:table.cell align="end">{{ $currency->symbol }} {{ $this->formatMoney($component['cost']) }}</flux:table.cell></flux:table.row>@endforeach</flux:table.rows></flux:table></div></flux:card>

    @if($this->canVoid)<flux:modal name="void-sale" class="max-w-lg"><form wire:submit="voidSale" class="space-y-5"><div><flux:heading size="lg">Anular venta {{ $sale->number }}</flux:heading><flux:text>Se generará una reversión y los insumos volverán al inventario. La venta seguirá visible.</flux:text></div><flux:textarea wire:model="voidReason" label="Motivo" required /><div class="flex justify-end gap-3"><flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close><flux:button type="submit" variant="danger">Anular venta</flux:button></div></form></flux:modal>@endif
    @if($this->canRegisterPayment)<flux:modal name="register-payment" class="max-w-lg"><form wire:submit="registerPayment" class="space-y-5"><div><flux:heading size="lg">Registrar pago</flux:heading><flux:text>Saldo pendiente: {{ $currency->symbol }} {{ $this->formatMoney($sale->balance_due_base) }}</flux:text></div><flux:select wire:model="paymentMethodId" label="Método de pago" required>@foreach($this->paymentMethods as $method)<flux:select.option :value="$method->id">{{ $method->name }}</flux:select.option>@endforeach</flux:select><flux:input wire:model="paymentAmount" type="number" min="0.0001" :max="$sale->balance_due_base" step="0.0001" label="Importe" required /><flux:input wire:model="paymentOccurredAt" type="datetime-local" label="Fecha y hora del pago" description="Indica cuándo se recibió realmente este pago." required /><div class="flex justify-end gap-3"><flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close><flux:button type="submit" variant="primary" wire:loading.attr="disabled">Guardar pago</flux:button></div></form></flux:modal>@endif
</div>
