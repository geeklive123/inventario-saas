<?php

use App\Enums\InventoryBehavior;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockBalance;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\Warehouse;
use App\Services\Dashboard\BusinessDashboard;
use App\Support\Authorization\CompanyAccess;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component
{
    public ?int $warehouseId = null;

    public string $period = 'today';

    public function mount(): void
    {
        $requested = session('current_warehouse_id');
        $this->warehouseId = Warehouse::query()->whereKey($requested)->value('id')
            ?? Warehouse::query()->where('is_active', true)->orderBy('name')->value('id');

        if ($this->warehouseId !== null) {
            session()->put('current_warehouse_id', $this->warehouseId);
        }
    }

    public function updatedWarehouseId(): void
    {
        $warehouse = Warehouse::query()->whereKey($this->warehouseId)->where('is_active', true)->firstOrFail();
        session()->put('current_warehouse_id', $warehouse->getKey());
    }

    #[Computed]
    public function warehouses(): Collection
    {
        return Warehouse::query()->with('branch:id,name')->where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function recentMovements(): Collection
    {
        if (! app(CompanyAccess::class)->allowsCurrent(Auth::user(), 'inventory.view', mutation: false)) {
            return new Collection;
        }

        return StockMovement::query()
            ->with(['warehouse:id,name', 'createdBy.user:id,name'])
            ->when($this->warehouseId, fn ($query) => $query->where('warehouse_id', $this->warehouseId))
            ->latest('occurred_at')->limit(6)->get();
    }

    #[Computed]
    public function metrics(): array
    {
        $access = app(CompanyAccess::class);
        $user = Auth::user();
        $catalog = $access->allowsCurrent($user, 'catalog.view', mutation: false);
        $inventory = $access->allowsCurrent($user, 'inventory.view', mutation: false);

        return [
            'active_products' => $catalog ? Product::query()->where('is_active', true)->count() : 0,
            'stock_products' => $inventory ? StockBalance::query()
                ->when($this->warehouseId, fn ($query) => $query->where('warehouse_id', $this->warehouseId))
                ->where('quantity', '!=', 0)->count() : 0,
            'composed_products' => $catalog ? Product::query()
                ->where('is_active', true)->where('inventory_behavior', InventoryBehavior::Components)->count() : 0,
            'inventory_value' => $inventory ? (string) StockBalance::query()
                ->when($this->warehouseId, fn ($query) => $query->where('warehouse_id', $this->warehouseId))
                ->sum('inventory_value_base') : '0',
            'categories' => $catalog ? Category::query()->where('is_active', true)->count() : 0,
            'units' => $catalog ? Unit::query()->where('is_active', true)->count() : 0,
        ];
    }

    #[Computed]
    public function business(): array
    {
        return app(BusinessDashboard::class)->summary(
            app(CurrentCompany::class)->membership(), $this->period, $this->warehouseId,
        );
    }

    #[Computed]
    public function capabilities(): array
    {
        $access = app(CompanyAccess::class);
        $user = Auth::user();

        return [
            'create_product' => $access->allowsCurrent($user, 'catalog.create'),
            'view_catalog' => $access->allowsCurrent($user, 'catalog.view', mutation: false),
            'view_inventory' => $access->allowsCurrent($user, 'inventory.view', mutation: false),
            'opening_stock' => $access->allowsCurrent($user, 'inventory.opening'),
            'adjust_stock' => $access->allowsCurrent($user, 'inventory.adjust'),
            'create_sale' => $access->allowsCurrent($user, 'sales.create'),
            'create_expense' => $access->allowsCurrent($user, 'finance.expenses.create'),
            'register_waste' => $access->allowsCurrent($user, 'inventory.waste'),
        ];
    }
}; ?>

@php
    $company = app(CurrentCompany::class)->company();
    $selectedWarehouse = $this->warehouses->firstWhere('id', $warehouseId);
    $currency = $company->baseCurrency;
@endphp

<div class="flex w-full flex-col gap-6">
    <div class="flex flex-col justify-between gap-4 md:flex-row md:items-end">
        <div>
            <flux:heading size="xl">Hola, {{ auth()->user()->name }}</flux:heading>
            <flux:text class="mt-1">Resumen de {{ $company->name }}</flux:text>
        </div>
        <div class="grid w-full gap-3 md:w-auto md:grid-cols-2">
            <flux:select wire:model.live="period" label="Período">
                <flux:select.option value="today">Hoy</flux:select.option>
                <flux:select.option value="week">Esta semana</flux:select.option>
                <flux:select.option value="month">Este mes</flux:select.option>
            </flux:select>
            <flux:select wire:model.live="warehouseId" label="Almacén seleccionado">
                @forelse ($this->warehouses as $warehouse)
                    <flux:select.option :value="$warehouse->id">{{ $warehouse->branch->name }} · {{ $warehouse->name }}</flux:select.option>
                @empty
                    <flux:select.option value="">Sin almacenes disponibles</flux:select.option>
                @endforelse
            </flux:select>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <flux:card><flux:text>Ventas realizadas</flux:text><flux:heading size="xl" class="mt-2">{{ $this->business['sales_count'] }}</flux:heading></flux:card>
        <flux:card><flux:text>Ramos vendidos</flux:text><flux:heading size="xl" class="mt-2">{{ Number::format((float) $this->business['bouquets_sold'], precision: 0, locale: 'es') }}</flux:heading></flux:card>
        @if($this->business['visibility']['canViewIncome'])<flux:card><flux:text>Ventas del período</flux:text><flux:heading size="xl" class="mt-2">{{ $currency->symbol }} {{ Number::format((float) $this->business['sales_booked_base'], precision: $currency->decimal_places, locale: 'es') }}</flux:heading><flux:text size="sm">Importe vendido, aunque quede saldo pendiente.</flux:text></flux:card><flux:card><flux:text>Cobrado en el período</flux:text><flux:heading size="xl" class="mt-2">{{ $currency->symbol }} {{ Number::format((float) $this->business['collected_base'], precision: $currency->decimal_places, locale: 'es') }}</flux:heading><flux:text size="sm">Según la fecha de cada pago recibido.</flux:text></flux:card>@endif
        @if($this->business['visibility']['canViewBalances'])<flux:card><flux:text>Por cobrar</flux:text><flux:heading size="xl" class="mt-2">{{ $currency->symbol }} {{ Number::format((float) $this->business['receivable_base'], precision: $currency->decimal_places, locale: 'es') }}</flux:heading><flux:text size="sm">Saldo pendiente actual.</flux:text></flux:card>@endif
        @if($this->business['visibility']['canViewCosts'])<flux:card><flux:text>Costo de ramos vendidos</flux:text><flux:heading size="xl" class="mt-2">{{ $currency->symbol }} {{ Number::format((float) $this->business['cost_base'], precision: $currency->decimal_places, locale: 'es') }}</flux:heading></flux:card>@endif
        @if($this->business['visibility']['canViewProfit'])<flux:card><flux:text>Margen bruto</flux:text><flux:heading size="xl" class="mt-2">{{ $currency->symbol }} {{ Number::format((float) $this->business['gross_margin_base'], precision: $currency->decimal_places, locale: 'es') }}</flux:heading></flux:card>@endif
        @if($this->business['visibility']['canViewIncome'])<flux:card><flux:text>Ticket promedio</flux:text><flux:heading size="xl" class="mt-2">{{ $currency->symbol }} {{ Number::format((float) $this->business['average_ticket_base'], precision: $currency->decimal_places, locale: 'es') }}</flux:heading></flux:card>@endif
        @if($this->business['visibility']['canViewExpenses'])<flux:card><flux:text>Gastos</flux:text><flux:heading size="xl" class="mt-2">{{ $currency->symbol }} {{ Number::format((float) $this->business['expenses_base'], precision: $currency->decimal_places, locale: 'es') }}</flux:heading></flux:card>@endif
        @if($this->business['approximate_result_base'] !== null)<flux:card><flux:text>Resultado aproximado</flux:text><flux:heading size="xl" class="mt-2">{{ $currency->symbol }} {{ Number::format((float) $this->business['approximate_result_base'], precision: $currency->decimal_places, locale: 'es') }}</flux:heading></flux:card>@endif
        @if($this->business['cash_result_base'] !== null)<flux:card><flux:text>Resultado de caja</flux:text><flux:heading size="xl" class="mt-2">{{ $currency->symbol }} {{ Number::format((float) $this->business['cash_result_base'], precision: $currency->decimal_places, locale: 'es') }}</flux:heading><flux:text size="sm">Cobrado menos gastos del período.</flux:text></flux:card>@endif
    </div>

    @if(! $this->business['visibility']['canViewIncome'])
        <flux:callout icon="user" heading="Tu actividad">Ves tus ventas y ramos registrados. Los importes, costos y resultados requieren acceso financiero.</flux:callout>
    @endif
    @if($this->business['stock_alerts'] > 0)
        <flux:callout color="amber" icon="exclamation-triangle" heading="Hay {{ $this->business['stock_alerts'] }} insumo(s) sin existencia">Revisa el almacén seleccionado antes de registrar nuevas ventas.</flux:callout>
    @endif

    @if (collect($this->capabilities)->contains(true))
        <flux:card>
            <div class="mb-4">
                <flux:heading size="lg">Acciones rápidas</flux:heading>
                <flux:text>Continúa con una tarea frecuente.</flux:text>
            </div>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                @if ($this->capabilities['create_sale'])<flux:button class="justify-start" icon="shopping-bag" :href="route('sales.index', ['nueva' => 1])" wire:navigate>Nueva venta</flux:button>@endif
                @if ($this->capabilities['create_expense'])<flux:button class="justify-start" icon="banknotes" :href="route('finance.expenses')" wire:navigate>Registrar gasto</flux:button>@endif
                @if ($this->capabilities['create_product'])
                    <flux:button class="justify-start" icon="archive-box" :href="route('supplies')" wire:navigate>Nuevo insumo</flux:button>
                    <flux:button class="justify-start" icon="gift" :href="route('bouquets')" wire:navigate>Nuevo ramo</flux:button>
                @endif
                @if ($this->capabilities['adjust_stock'])
                    <flux:button class="justify-start" icon="shopping-cart" :href="route('inventory.stock', ['operacion' => 'inbound'])" wire:navigate>Registrar compra</flux:button>
                @endif
                @if ($this->capabilities['opening_stock'])
                    <flux:button class="justify-start" icon="archive-box-arrow-down" :href="route('inventory.stock', ['operacion' => 'opening'])" wire:navigate>Cargar stock inicial</flux:button>
                @endif
                @if ($this->capabilities['view_inventory'])
                    <flux:button class="justify-start" icon="archive-box" :href="route('inventory.stock')" wire:navigate>Ver inventario</flux:button>
                @endif
                @if ($this->capabilities['register_waste'])<flux:button class="justify-start" icon="trash" :href="route('inventory.waste')" wire:navigate>Registrar merma</flux:button>@endif
            </div>
        </flux:card>
    @endif

    @if ($this->capabilities['view_catalog'] && $this->metrics['active_products'] === 0)
        <flux:card class="border-blue-200 bg-blue-50/60 dark:border-blue-900 dark:bg-blue-950/20">
            <div class="flex flex-col justify-between gap-5 lg:flex-row lg:items-start">
                <div class="max-w-2xl">
                    <flux:badge color="blue">Primeros pasos</flux:badge>
                    <flux:heading size="lg" class="mt-3">Prepara tu catálogo a tu ritmo</flux:heading>
                    <flux:text class="mt-1">Puedes completar estos pasos en orden. Nada se bloqueará si prefieres volver después.</flux:text>
                </div>
                @if ($this->capabilities['create_product'])
                    <flux:button variant="primary" :href="route('catalog.products', ['crear' => 'producto'])" wire:navigate>Crear mi primer producto</flux:button>
                @endif
            </div>
            <div class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ([
                    ['1', 'Crear unidades', 'Define cómo contarás: unidad, docena, kilo o metro.', route('catalog.units'), $this->metrics['units'] > 0],
                    ['2', 'Crear categorías', 'Agrupa tu catálogo para encontrarlo con facilidad.', route('catalog.categories'), $this->metrics['categories'] > 0],
                    ['3', 'Registrar productos e insumos', 'Agrega lo que vendes y lo que utilizas.', route('catalog.products'), false],
                    ['4', 'Crear productos preparados', 'Elige esta opción cuando uses varios insumos.', route('catalog.products', ['crear' => 'preparado']), false],
                    ['5', 'Configurar recetas', 'Indica cantidades y desperdicio estimado.', route('catalog.recipes'), false],
                    ['6', 'Registrar existencias', 'Carga las cantidades con las que comenzarás.', $this->capabilities['view_inventory'] ? route('inventory.stock') : null, false],
                ] as [$number, $title, $description, $url, $complete])
                    @if ($url)
                        <a wire:navigate href="{{ $url }}" class="rounded-xl border border-zinc-200 bg-white p-4 transition hover:border-blue-400 dark:border-zinc-700 dark:bg-zinc-900">
                    @else
                        <div class="rounded-xl border border-zinc-200 bg-zinc-100/70 p-4 opacity-70 dark:border-zinc-700 dark:bg-zinc-800/50">
                    @endif
                        <div class="flex items-start gap-3">
                            <span class="flex size-7 shrink-0 items-center justify-center rounded-full {{ $complete ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300' : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300' }} text-sm font-semibold">{{ $complete ? '✓' : $number }}</span>
                            <span><span class="block font-medium">{{ $title }}</span><span class="mt-1 block text-sm text-zinc-500 dark:text-zinc-400">{{ $description }}</span></span>
                        </div>
                    @if ($url)</a>@else</div>@endif
                @endforeach
            </div>
        </flux:card>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <flux:card>
            <flux:heading size="lg">Ramos más vendidos</flux:heading>
            <div class="mt-4 space-y-3">@forelse($this->business['top_bouquets'] as $bouquet)<div class="flex justify-between gap-3"><span>{{ $bouquet->product_name }}<flux:text size="sm">{{ Number::format((float) $bouquet->quantity, precision: 0, locale: 'es') }} vendidos</flux:text></span>@if($this->business['visibility']['canViewIncome'])<strong>{{ $currency->symbol }} {{ Number::format((float) $bouquet->total_base, precision: $currency->decimal_places, locale: 'es') }}</strong>@endif</div>@empty<flux:text>Sin ventas en el período.</flux:text>@endforelse</div>
        </flux:card>
        @if($this->business['visibility']['canViewIncome'])
            <flux:card>
                <flux:heading size="lg">Cobros por forma de pago</flux:heading>
                <div class="mt-4 space-y-3">@forelse($this->business['payments'] as $method => $total)<div class="flex justify-between gap-3"><span>{{ $method }}</span><strong>{{ $currency->symbol }} {{ Number::format((float) $total, precision: $currency->decimal_places, locale: 'es') }}</strong></div>@empty<flux:text>Sin cobros en el período.</flux:text>@endforelse @if($this->business['mixed_sales_count'])<flux:text size="sm">{{ $this->business['mixed_sales_count'] }} venta(s) tuvieron pagos divididos.</flux:text>@endif</div>
            </flux:card>
        @endif
        <flux:card>
            <flux:heading size="lg">Ventas recientes</flux:heading>
            <div class="mt-4 space-y-3">@forelse($this->business['recent_sales'] as $sale)<a class="block rounded-lg p-2 hover:bg-zinc-50 dark:hover:bg-zinc-800" href="{{ route('sales.show', $sale) }}" wire:navigate><div class="flex justify-between gap-3"><strong>{{ $sale->number }}</strong>@if($this->business['visibility']['canViewIncome'])<strong>{{ $currency->symbol }} {{ Number::format((float) $sale->total_base, precision: $currency->decimal_places, locale: 'es') }}</strong>@endif</div><flux:text size="sm">{{ $sale->occurred_at->timezone($company->timezone)->format('d/m/Y H:i') }} · {{ $sale->items->pluck('product_name')->join(', ') }} · {{ $sale->payments->pluck('payment_method_name')->join(' + ') }} · {{ $sale->confirmedBy->user->name }}</flux:text></a>@empty<flux:text>Sin ventas recientes.</flux:text>@endforelse</div>
            @can('viewAny', App\Models\Sale::class)<flux:button class="mt-4" size="sm" variant="subtle" :href="route('sales.index')" wire:navigate>Ver todas las ventas</flux:button>@endcan
        </flux:card>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <flux:card class="lg:col-span-2">
            <div class="mb-4 flex items-center justify-between gap-4">
                <div>
                    <flux:heading size="lg">Movimientos recientes</flux:heading>
                    <flux:text>{{ $selectedWarehouse?->name ?? 'Todos los almacenes' }}</flux:text>
                </div>
                @can('viewAny', App\Models\StockMovement::class)
                    <flux:button size="sm" variant="subtle" :href="route('inventory.movements')" wire:navigate>Ver historial</flux:button>
                @endcan
            </div>
            <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->recentMovements as $movement)
                    <div wire:key="movement-{{ $movement->id }}" class="flex items-center justify-between gap-4 py-3">
                        <div>
                            <div class="font-medium">#{{ $movement->id }} · {{ match($movement->type->value) { 'opening' => 'Stock inicial', 'adjustment_in' => 'Entrada', 'adjustment_out' => 'Salida', 'sale' => 'Venta', 'waste' => 'Merma', default => 'Reversión' } }}</div>
                            <flux:text size="sm">{{ $movement->warehouse->name }} · {{ $movement->occurred_at->timezone($company->timezone)->format('d/m/Y H:i') }}</flux:text>
                        </div>
                        <flux:badge :color="in_array($movement->type->value, ['adjustment_out', 'sale', 'waste'], true) ? 'red' : ($movement->type->value === 'reversal' ? 'amber' : 'green')">
                            {{ $movement->createdBy->user->name }}
                        </flux:badge>
                    </div>
                @empty
                    <div class="py-10 text-center">
                        <flux:heading>Aún no hay movimientos</flux:heading>
                        <flux:text class="mt-1">Las entradas, salidas y correcciones aparecerán aquí.</flux:text>
                        @if ($this->capabilities['opening_stock'])
                            <flux:button class="mt-4" :href="route('inventory.stock', ['operacion' => 'opening'])" wire:navigate>Cargar stock inicial</flux:button>
                        @endif
                    </div>
                @endforelse
            </div>
        </flux:card>

        <flux:card>
            <flux:heading size="lg">Contexto actual</flux:heading>
            <div class="mt-4 grid gap-4">
                <div><flux:text size="sm">Empresa</flux:text><div class="font-medium">{{ $company->name }}</div></div>
                <div><flux:text size="sm">Sucursal</flux:text><div class="font-medium">{{ $selectedWarehouse?->branch?->name ?? 'Sin seleccionar' }}</div></div>
                <div><flux:text size="sm">Almacén</flux:text><div class="font-medium">{{ $selectedWarehouse?->name ?? 'Sin seleccionar' }}</div></div>
                <div><flux:text size="sm">Moneda base</flux:text><div class="font-medium">{{ $currency->code }} · {{ $currency->name }}</div></div>
            </div>
        </flux:card>
    </div>
</div>
