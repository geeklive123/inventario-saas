<?php

use App\Actions\Sales\RegularizeSaleInventory;
use App\Enums\InventoryBehavior;
use App\Enums\ProductItemType;
use App\Enums\SaleInventoryPendingStatus;
use App\Models\Product;
use App\Models\SaleInventoryPending;
use App\Models\StockBalance;
use App\Support\Tenancy\CurrentCompany;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Regularizar inventario de venta')] class extends Component
{
    public int $pendingId;

    /** @var array<int, array{product_id: int|null, quantity: string}> */
    public array $allocations = [];

    public function mount(int $pendingId): void
    {
        $this->pendingId = $pendingId;
        Gate::authorize('view', $this->pending);
        $this->allocations = [['product_id' => null, 'quantity' => '']];
    }

    public function addAllocation(): void
    {
        $this->allocations[] = ['product_id' => null, 'quantity' => ''];
    }

    public function removeAllocation(int $index): void
    {
        if (count($this->allocations) > 1) {
            unset($this->allocations[$index]);
            $this->allocations = array_values($this->allocations);
        }
    }

    public function regularize(): void
    {
        Gate::authorize('update', $this->pending);
        $companyId = app(CurrentCompany::class)->id();
        $data = $this->validate([
            'allocations' => ['required', 'array', 'min:1'],
            'allocations.*.product_id' => ['required', 'distinct', Rule::exists('products', 'id')
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->where('item_type', ProductItemType::Physical->value)
                ->where('inventory_behavior', InventoryBehavior::Self->value)],
            'allocations.*.quantity' => ['required', 'numeric', 'gt:0'],
        ], messages: [
            'allocations.*.product_id.required' => 'Selecciona el insumo utilizado.',
            'allocations.*.product_id.distinct' => 'No repitas el mismo insumo.',
            'allocations.*.quantity.gt' => 'La cantidad debe ser mayor que cero.',
        ]);
        $products = Product::query()->whereIn('id', collect($data['allocations'])->pluck('product_id'))->get()->keyBy('id');

        try {
            app(RegularizeSaleInventory::class)->handle(
                app(CurrentCompany::class)->membership(),
                $this->pending,
                collect($data['allocations'])->map(fn (array $line): array => [
                    'product' => $products->get($line['product_id']),
                    'quantity' => $line['quantity'],
                ])->all(),
            );
        } catch (\DomainException $exception) {
            $this->addError('allocations', $exception->getMessage());

            return;
        }

        $saleId = $this->pending->sale_id;
        unset($this->pending, $this->products, $this->balances);
        Flux::toast(variant: 'success', text: 'Inventario regularizado correctamente.');
        $this->redirectRoute('sales.show', ['saleId' => $saleId], navigate: true);
    }

    public function formatQuantity(int|float|string $quantity): string
    {
        return Number::format((float) $quantity, maxPrecision: 6, locale: 'es');
    }

    #[Computed]
    public function pending(): SaleInventoryPending
    {
        return SaleInventoryPending::query()->with([
            'sale', 'saleItem', 'warehouse', 'regularizationLines.actualProduct', 'regularizedBy.user',
        ])->findOrFail($this->pendingId);
    }

    #[Computed]
    public function products(): Collection
    {
        return Product::query()->with('unit:id,symbol')->where('is_active', true)
            ->where('item_type', ProductItemType::Physical)
            ->where('inventory_behavior', InventoryBehavior::Self)
            ->orderBy('name')->get();
    }

    #[Computed]
    public function balances(): Collection
    {
        return StockBalance::query()->where('warehouse_id', $this->pending->warehouse_id)
            ->whereIn('product_id', $this->products->pluck('id'))->get()->keyBy('product_id');
    }

    #[Computed]
    public function assignedQuantity(): string
    {
        return collect($this->allocations)->reduce(
            fn (string $total, array $line): string => bcadd($total, is_numeric($line['quantity']) ? $line['quantity'] : '0', 6),
            '0.000000',
        );
    }
};
?>

<div class="flex w-full flex-col gap-6">
    <div><flux:button variant="subtle" icon="arrow-left" :href="route('inventory.regularizations')" wire:navigate class="mb-3">Volver a pendientes</flux:button><flux:heading size="xl">Regularizar inventario · Venta {{ $this->pending->sale->number }}</flux:heading><flux:text>{{ $this->pending->warehouse->name }} · {{ $this->pending->sale->customer_name ?: 'Consumidor final' }}</flux:text></div>

    <flux:card class="space-y-2"><flux:text size="sm">Componente solicitado</flux:text><flux:heading size="lg">{{ $this->pending->original_component_name }}</flux:heading><flux:text>{{ $this->pending->original_component_sku }} · Cantidad pendiente: <strong>{{ $this->formatQuantity($this->pending->required_quantity) }} {{ $this->pending->unit_symbol }}</strong></flux:text><flux:callout icon="information-circle">La selección aplica solo a esta venta. La receta original no se modificará.</flux:callout></flux:card>

    @if($this->pending->status === SaleInventoryPendingStatus::Cancelled)
        <flux:callout icon="x-circle" heading="Pendiente cancelado">La venta fue anulada. No se descontó inventario por este pendiente.</flux:callout>
    @elseif($this->pending->status === SaleInventoryPendingStatus::Completed)
        <flux:callout variant="success" icon="check-circle" heading="Pendiente completado">Regularizado por {{ $this->pending->regularizedBy?->user?->name }} el {{ $this->pending->regularized_at?->format('d/m/Y H:i') }}.</flux:callout>
        <flux:card><flux:heading size="sm" class="mb-3">Insumos registrados</flux:heading>@foreach($this->pending->regularizationLines as $line)<div class="flex justify-between gap-3 py-2"><span>{{ $line->actual_product_name }}</span><strong>{{ $this->formatQuantity($line->quantity) }} {{ $line->unit_symbol }}</strong></div>@endforeach</flux:card>
    @else
        <form wire:submit="regularize" class="space-y-5">
            @error('allocations')<flux:callout variant="danger" icon="x-circle" :heading="$message" />@enderror
            <flux:card class="space-y-4">
                <div class="flex items-center justify-between gap-3"><div><flux:heading size="sm">Utilizado realmente</flux:heading><flux:text size="sm">Puedes dividir la cantidad entre varios insumos.</flux:text></div><flux:button type="button" size="sm" icon="plus" wire:click="addAllocation">Agregar otro insumo</flux:button></div>
                @foreach($allocations as $index => $allocation)
                    @php($balance = $this->balances->get((int) $allocation['product_id']))
                    <div class="grid items-end gap-3 md:grid-cols-[2fr_1fr_1fr_auto]" wire:key="allocation-{{ $index }}">
                        <flux:select wire:model.live="allocations.{{ $index }}.product_id" label="Insumo" required><flux:select.option value="">Seleccionar</flux:select.option>@foreach($this->products as $product)<flux:select.option :value="$product->id">{{ $product->name }} · {{ $product->sku }}</flux:select.option>@endforeach</flux:select>
                        <flux:input wire:model.live.debounce.250ms="allocations.{{ $index }}.quantity" type="number" min="0.000001" step="0.000001" label="Cantidad" required />
                        <div><flux:text size="sm">Stock disponible</flux:text><div class="mt-2 font-semibold">{{ $this->formatQuantity($balance?->quantity ?? 0) }} {{ optional($this->products->firstWhere('id', (int) $allocation['product_id']))->unit?->symbol }}</div></div>
                        <flux:button type="button" variant="ghost" icon="trash" wire:click="removeAllocation({{ $index }})" :disabled="count($allocations) === 1" aria-label="Quitar insumo" />
                    </div>
                @endforeach
                <div class="flex justify-between border-t border-zinc-200 pt-4 text-lg dark:border-zinc-700"><span>Total asignado</span><strong>{{ $this->formatQuantity($this->assignedQuantity) }} / {{ $this->formatQuantity($this->pending->required_quantity) }}</strong></div>
            </flux:card>
            <div class="flex justify-end gap-3"><flux:button variant="ghost" :href="route('inventory.regularizations')" wire:navigate>Cancelar</flux:button><flux:button type="submit" variant="primary" :disabled="bccomp($this->assignedQuantity, $this->pending->required_quantity, 6) !== 0" wire:confirm="¿Confirmas los insumos utilizados? Esta operación descontará inventario.">Confirmar regularización</flux:button></div>
        </form>
    @endif
</div>
