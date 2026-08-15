<?php

use App\Actions\Inventory\RecordWaste;
use App\Enums\InventoryBehavior;
use App\Enums\ProductItemType;
use App\Enums\StockMovementType;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Support\Authorization\CompanyAccess;
use App\Support\Tenancy\CurrentCompany;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Mermas')] class extends Component
{
    use WithPagination;
    public ?int $warehouseId = null;
    public ?int $productId = null;
    public string $quantity = '';
    public string $reasonType = '';
    public string $otherReason = '';

    public function mount(): void { $this->warehouseId = Warehouse::query()->where('is_active', true)->orderBy('name')->value('id'); }
    public function openForm(): void { abort_unless($this->canRegister, 403); $this->reset(['productId', 'quantity', 'reasonType', 'otherReason']); Flux::modal('waste-form')->show(); }
    public function save(): void
    {
        abort_unless($this->canRegister, 403);
        $data = $this->validate([
            'warehouseId' => ['required', 'integer'], 'productId' => ['required', 'integer'], 'quantity' => ['required', 'numeric', 'gt:0'],
            'reasonType' => ['required', 'in:Marchita,Dañada,Error de preparación,Otro'], 'otherReason' => ['nullable', 'required_if:reasonType,Otro', 'string', 'max:500'],
        ]);
        $warehouse = Warehouse::query()->whereKey($data['warehouseId'])->where('is_active', true)->firstOrFail();
        $product = Product::query()->whereKey($data['productId'])->where('item_type', ProductItemType::Physical)->where('inventory_behavior', InventoryBehavior::Self)->firstOrFail();
        $reason = $data['reasonType'] === 'Otro' ? 'Otro: '.$data['otherReason'] : $data['reasonType'];
        try { app(RecordWaste::class)->handle(app(CurrentCompany::class)->membership(), $warehouse, $product, $data['quantity'], $reason); }
        catch (\DomainException $exception) { $this->addError('quantity', $exception->getMessage()); return; }
        Flux::modal('waste-form')->close(); Flux::toast(variant: 'success', text: 'Merma registrada correctamente.');
    }

    #[Computed] public function canRegister(): bool { return app(CompanyAccess::class)->allowsCurrent(auth()->user(), 'inventory.waste'); }
    #[Computed] public function warehouses(): Collection { return Warehouse::query()->with('branch:id,name')->where('is_active', true)->orderBy('name')->get(); }
    #[Computed] public function products(): Collection { return Product::query()->with('unit:id,symbol')->where('is_active', true)->where('item_type', ProductItemType::Physical)->where('inventory_behavior', InventoryBehavior::Self)->orderBy('name')->get(['id', 'unit_id', 'name', 'sku']); }
    #[Computed] public function wastes(): LengthAwarePaginator { return StockMovement::query()->with(['warehouse:id,name', 'createdBy.user:id,name', 'lines.product.unit'])->where('type', StockMovementType::Waste)->latest('occurred_at')->paginate(15); }
    public function formatQuantity(int|float|string $value): string { return Number::format(abs((float) $value), maxPrecision: 6, locale: 'es'); }
}; ?>
@php($company = app(CurrentCompany::class)->company())
<div class="flex w-full flex-col gap-6"><div class="flex flex-col justify-between gap-4 md:flex-row md:items-center"><div><flux:heading size="xl">Mermas</flux:heading><flux:text>Registra flores o materiales perdidos y conserva su trazabilidad.</flux:text></div>@if($this->canRegister)<flux:button variant="primary" icon="plus" wire:click="openForm">Registrar merma</flux:button>@endif</div><flux:card class="overflow-hidden p-0!"><div class="overflow-x-auto"><flux:table :paginate="$this->wastes"><flux:table.columns><flux:table.column>Fecha</flux:table.column><flux:table.column>Insumo</flux:table.column><flux:table.column align="end">Cantidad</flux:table.column><flux:table.column>Almacén</flux:table.column><flux:table.column>Motivo</flux:table.column><flux:table.column>Registrado por</flux:table.column></flux:table.columns><flux:table.rows>@forelse($this->wastes as $waste)<flux:table.row :key="$waste->id">@php($line=$waste->lines->first())<flux:table.cell>{{ $waste->occurred_at->timezone($company->timezone)->format('d/m/Y H:i') }}</flux:table.cell><flux:table.cell variant="strong">{{ $line?->product?->name }}</flux:table.cell><flux:table.cell align="end">{{ $line ? $this->formatQuantity($line->quantity).' '.$line->product->unit->symbol : '—' }}</flux:table.cell><flux:table.cell>{{ $waste->warehouse->name }}</flux:table.cell><flux:table.cell>{{ $waste->reason }}</flux:table.cell><flux:table.cell>{{ $waste->createdBy->user->name }}</flux:table.cell></flux:table.row>@empty<flux:table.row><flux:table.cell colspan="6"><div class="py-12 text-center"><flux:heading>No hay mermas registradas</flux:heading><flux:text>Las pérdidas por flores marchitas, daños o preparación aparecerán aquí.</flux:text></div></flux:table.cell></flux:table.row>@endforelse</flux:table.rows></flux:table></div></flux:card>@if($this->canRegister)<flux:modal name="waste-form" class="max-w-xl"><form wire:submit="save" class="space-y-5"><div><flux:heading size="lg">Registrar merma</flux:heading><flux:text>La existencia disminuirá y quedará registrado quién realizó la operación.</flux:text></div><flux:select wire:model="warehouseId" label="Almacén" required>@foreach($this->warehouses as $warehouse)<flux:select.option :value="$warehouse->id">{{ $warehouse->branch->name }} · {{ $warehouse->name }}</flux:select.option>@endforeach</flux:select><flux:select wire:model="productId" label="Insumo" required><flux:select.option value="">Seleccionar</flux:select.option>@foreach($this->products as $product)<flux:select.option :value="$product->id">{{ $product->name }} · {{ $product->sku }} ({{ $product->unit->symbol }})</flux:select.option>@endforeach</flux:select><flux:input wire:model="quantity" type="number" step="0.000001" min="0.000001" label="Cantidad perdida" required /><flux:select wire:model.live="reasonType" label="Motivo" required><flux:select.option value="">Seleccionar</flux:select.option>@foreach(['Marchita','Dañada','Error de preparación','Otro'] as $reason)<flux:select.option :value="$reason">{{ $reason }}</flux:select.option>@endforeach</flux:select>@if($reasonType==='Otro')<flux:textarea wire:model="otherReason" label="Describe el motivo" required />@endif<div class="flex justify-end gap-3"><flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close><flux:button type="submit" variant="primary">Registrar merma</flux:button></div></form></flux:modal>@endif</div>
