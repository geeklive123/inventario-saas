<?php

use App\Actions\Sales\VoidSale;
use App\Enums\ModuleCode;
use App\Enums\SaleStatus;
use App\Models\Sale;
use App\Support\Authorization\CompanyAccess;
use App\Support\Tenancy\CurrentCompany;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Detalle de venta')] class extends Component
{
    public int $saleId;

    public string $voidReason = '';

    public function mount(int $saleId): void
    {
        $this->saleId = $saleId;
        Gate::authorize('view', $this->sale);
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
            'items.components', 'payments', 'confirmedBy.user', 'voidedBy.user',
            'stockMovementLinks.stockMovement.lines.product',
        ])->findOrFail($this->saleId);
    }

    #[Computed]
    public function canVoid(): bool
    {
        return $this->sale->status === SaleStatus::Confirmed
            && app(CompanyAccess::class)->allowsCurrent(auth()->user(), 'sales.void')
            && app(CompanyAccess::class)->moduleEnabledCurrent(ModuleCode::Inventory);
    }

    /** @return array<int, array{name: string, sku: string, unit_symbol: string, quantity: numeric-string, cost: numeric-string}> */
    #[Computed]
    public function consumedComponents(): array
    {
        return $this->sale->items->flatMap->components
            ->groupBy('product_id')
            ->map(function ($components): array {
                $first = $components->first();

                return [
                    'name' => $first->product_name,
                    'sku' => $first->product_sku,
                    'unit_symbol' => $first->unit_symbol,
                    'quantity' => $components->reduce(fn (string $total, $component): string => bcadd($total, $component->quantity_consumed, 6), '0.000000'),
                    'cost' => $components->reduce(fn (string $total, $component): string => bcadd($total, $component->total_cost_base, 4), '0.0000'),
                ];
            })->values()->all();
    }
};

?>

@php($sale = $this->sale)
@php($company = app(CurrentCompany::class)->company())
@php($currency = $company->baseCurrency)

<div class="flex w-full flex-col gap-6">
    <div class="flex flex-col justify-between gap-4 md:flex-row md:items-center"><div><flux:button variant="subtle" icon="arrow-left" :href="route('sales.index')" wire:navigate class="mb-3">Volver a ventas</flux:button><flux:heading size="xl">Venta {{ $sale->number }}</flux:heading><flux:text>{{ $sale->status === SaleStatus::Confirmed ? 'Venta confirmada' : 'Venta anulada' }}</flux:text></div>@if($this->canVoid)<flux:button variant="danger" icon="x-circle" wire:click="openVoid">Anular venta</flux:button>@endif</div>

    @if($sale->status === SaleStatus::Voided)<flux:callout variant="danger" icon="x-circle" heading="Venta anulada">{{ $sale->void_reason }} · {{ $sale->voided_at?->timezone($company->timezone)->format('d/m/Y H:i') }} · {{ $sale->voidedBy?->user?->name }}</flux:callout>@endif

    <flux:card class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3"><div><flux:text size="sm">Fecha</flux:text><div class="font-semibold">{{ $sale->occurred_at->timezone($company->timezone)->format('d/m/Y H:i') }}</div></div><div><flux:text size="sm">Responsable</flux:text><div class="font-semibold">{{ $sale->confirmedBy->user->name }}</div></div><div><flux:text size="sm">Cliente</flux:text><div class="font-semibold">{{ $sale->customer_name ?: 'Consumidor final' }}</div></div><div><flux:text size="sm">Sucursal</flux:text><div class="font-semibold">{{ $sale->branch_name }}</div></div><div><flux:text size="sm">Almacén</flux:text><div class="font-semibold">{{ $sale->warehouse_name }}</div></div><div><flux:text size="sm">Estado</flux:text><flux:badge :color="$sale->status === SaleStatus::Confirmed ? 'green' : 'red'">{{ $sale->status === SaleStatus::Confirmed ? 'Confirmada' : 'Anulada' }}</flux:badge></div></flux:card>

    <div class="grid gap-6 xl:grid-cols-2"><flux:card><flux:heading size="sm" class="mb-4">Ramos</flux:heading><div class="space-y-3">@foreach($sale->items as $item)<div class="flex justify-between gap-3 border-b border-zinc-200 pb-3 last:border-0 dark:border-zinc-700"><div><strong>{{ $item->product_name }}</strong><flux:text size="sm">{{ $this->formatQuantity($item->quantity) }} × {{ $currency->symbol }} {{ $this->formatMoney($item->unit_price_base) }}</flux:text></div><strong>{{ $currency->symbol }} {{ $this->formatMoney($item->subtotal_base) }}</strong></div>@endforeach<div class="flex justify-between pt-2 text-lg"><strong>Total</strong><strong>{{ $currency->symbol }} {{ $this->formatMoney($sale->total_base) }}</strong></div></div></flux:card>
        <flux:card><flux:heading size="sm" class="mb-4">Pagos</flux:heading><div class="space-y-3">@foreach($sale->payments as $payment)<div class="flex justify-between"><span>{{ $payment->payment_method_name }}</span><strong>{{ $currency->symbol }} {{ $this->formatMoney($payment->amount_base) }}</strong></div>@endforeach</div><div class="mt-5 grid gap-4 border-t border-zinc-200 pt-4 sm:grid-cols-2 dark:border-zinc-700"><div><flux:text size="sm">Costo total de insumos</flux:text><strong>{{ $currency->symbol }} {{ $this->formatMoney($sale->total_cost_base) }}</strong></div><div><flux:text size="sm">Ganancia estimada</flux:text><strong class="text-green-700 dark:text-green-400">{{ $currency->symbol }} {{ $this->formatMoney($sale->gross_margin_base) }}</strong></div></div></flux:card></div>

    <flux:card><flux:heading size="sm" class="mb-4">Insumos utilizados</flux:heading><div class="overflow-x-auto"><flux:table><flux:table.columns><flux:table.column>Insumo</flux:table.column><flux:table.column align="end">Cantidad</flux:table.column><flux:table.column align="end">Costo consumido</flux:table.column></flux:table.columns><flux:table.rows>@foreach($this->consumedComponents as $component)<flux:table.row><flux:table.cell variant="strong">{{ $component['name'] }}<flux:text size="sm">{{ $component['sku'] }}</flux:text></flux:table.cell><flux:table.cell align="end">{{ $this->formatQuantity($component['quantity']) }} {{ $component['unit_symbol'] }}</flux:table.cell><flux:table.cell align="end">{{ $currency->symbol }} {{ $this->formatMoney($component['cost']) }}</flux:table.cell></flux:table.row>@endforeach</flux:table.rows></flux:table></div></flux:card>

    @if($this->canVoid)<flux:modal name="void-sale" class="max-w-lg"><form wire:submit="voidSale" class="space-y-5"><div><flux:heading size="lg">Anular venta {{ $sale->number }}</flux:heading><flux:text>Se generará una reversión y los insumos volverán al inventario. La venta seguirá visible.</flux:text></div><flux:textarea wire:model="voidReason" label="Motivo" required /><div class="flex justify-end gap-3"><flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close><flux:button type="submit" variant="danger">Anular venta</flux:button></div></form></flux:modal>@endif
</div>
