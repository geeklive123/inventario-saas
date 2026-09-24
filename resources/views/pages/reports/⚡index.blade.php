<?php

use App\Services\Reports\BusinessReports;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Reportes')] class extends Component
{
    public string $period = 'today';

    public string $dateFrom = '';

    public string $dateTo = '';

    public string $exportScope = 'current';

    public string $exportSection = 'summary';

    public function mount(): void
    {
        $today = now(app(CurrentCompany::class)->company()->timezone);
        $this->dateFrom = $today->startOfMonth()->toDateString();
        $this->dateTo = $today->toDateString();
    }

    public function updatedPeriod(): void
    {
        $this->validateOnly('period', ['period' => ['required', 'in:today,week,month,custom']]);
        unset($this->report);
    }

    public function updatedDateFrom(): void
    {
        $this->validateDates();
    }

    public function updatedDateTo(): void
    {
        $this->validateDates();
    }

    /** @return array<string, mixed> */
    #[Computed]
    public function report(): array
    {
        return app(BusinessReports::class)->summary(
            app(CurrentCompany::class)->membership(),
            $this->period,
            $this->dateFrom,
            $this->dateTo,
        );
    }

    public function money(int|float|string|null $amount): string
    {
        if ($amount === null) {
            return 'Pendiente de regularizar';
        }

        $currency = app(CurrentCompany::class)->company()->baseCurrency;

        return $currency->symbol.' '.Number::format(
            (float) $amount,
            precision: $currency->decimal_places,
            locale: 'es',
        );
    }

    public function quantity(int|float|string $quantity): string
    {
        return Number::format((float) $quantity, maxPrecision: 6, locale: 'es');
    }

    public function exportUrl(string $format): string
    {
        return route('reports.export', [
            'format' => $format,
            'period' => $this->period,
            'date_from' => $this->period === 'custom' ? $this->dateFrom : null,
            'date_to' => $this->period === 'custom' ? $this->dateTo : null,
            'scope' => $this->exportScope,
            'section' => $this->exportSection,
        ]);
    }

    private function validateDates(): void
    {
        $this->validate([
            'dateFrom' => ['required', 'date_format:Y-m-d', 'before_or_equal:dateTo'],
            'dateTo' => ['required', 'date_format:Y-m-d', 'after_or_equal:dateFrom'],
        ], messages: [
            'dateFrom.before_or_equal' => 'La fecha inicial no puede ser posterior a la final.',
            'dateTo.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
        ]);
        unset($this->report);
    }
};
?>

<div class="flex w-full flex-col gap-8">
    <div class="flex flex-col justify-between gap-4 xl:flex-row xl:items-end">
        <div>
            <flux:heading size="xl">Reportes</flux:heading>
            <flux:text>Entiende cómo se movieron tus ventas, insumos y gastos en el período elegido.</flux:text>
        </div>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <flux:select wire:model.live="period" label="Período">
                <flux:select.option value="today">Hoy</flux:select.option>
                <flux:select.option value="week">Esta semana</flux:select.option>
                <flux:select.option value="month">Este mes</flux:select.option>
                <flux:select.option value="custom">Rango personalizado</flux:select.option>
            </flux:select>
            @if ($period === 'custom')
                <flux:input wire:model.live.blur="dateFrom" type="date" label="Desde" />
                <flux:input wire:model.live.blur="dateTo" type="date" label="Hasta" />
            @endif
        </div>
    </div>

    @php($report = $this->report)
    @php($capabilities = $report['capabilities'])
    @php($companyTimezone = app(CurrentCompany::class)->company()->timezone)

    <section class="space-y-4">
        <div>
            <flux:heading size="lg">Resumen general</flux:heading>
            <flux:text>Una vista rápida de la actividad disponible según tus permisos.</flux:text>
        </div>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            @if ($capabilities['sales'] || $capabilities['financial'])
                <flux:card><flux:text>Ventas realizadas</flux:text><div class="mt-2 text-2xl font-semibold">{{ $report['general']['sales_count'] }}</div><flux:text size="sm" class="mt-1">Ventas confirmadas dentro del período.</flux:text></flux:card>
            @endif
            @if ($capabilities['financial'])
                <flux:card><flux:text>Total vendido</flux:text><div class="mt-2 text-2xl font-semibold">{{ $this->money($report['general']['total_sold_base']) }}</div><flux:text size="sm" class="mt-1">Valor completo de las ventas confirmadas, aunque aún tengan saldo.</flux:text></flux:card>
                <flux:card><flux:text>Dinero cobrado</flux:text><div class="mt-2 text-2xl font-semibold text-green-700 dark:text-green-400">{{ $this->money($report['general']['collected_base']) }}</div><flux:text size="sm" class="mt-1">Pagos realmente recibidos durante este período.</flux:text></flux:card>
                <flux:card><flux:text>Pendiente por cobrar</flux:text><div class="mt-2 text-2xl font-semibold text-amber-700 dark:text-amber-400">{{ $this->money($report['general']['pending_base']) }}</div><flux:text size="sm" class="mt-1">Saldo actual de las ventas realizadas en este período.</flux:text></flux:card>
                <flux:card><flux:text>Costo de ramos vendidos</flux:text><div class="mt-2 text-2xl font-semibold">{{ $this->money($report['general']['historical_cost_base']) }}</div><flux:text size="sm" class="mt-1">Cuánto costaron los insumos utilizados en los ramos vendidos durante este período.</flux:text></flux:card>
                <flux:card class="ring-1 ring-green-200 dark:ring-green-900"><flux:text>Resultado estimado</flux:text><div class="mt-2 text-2xl font-semibold">{{ $this->money($report['general']['estimated_result_base']) }}</div><flux:text size="sm" class="mt-1">Ventas menos costo de los ramos y gastos registrados.</flux:text></flux:card>
            @endif
            @if ($capabilities['expenses'] || $capabilities['financial'])
                <flux:card><flux:text>Gastos</flux:text><div class="mt-2 text-2xl font-semibold">{{ $this->money($report['general']['expenses_base']) }}</div><flux:text size="sm" class="mt-1">Gastos operativos confirmados durante el período.</flux:text></flux:card>
            @endif
        </div>
    </section>

    @if ($report['sales'])
        <section class="space-y-4">
            <div><flux:heading size="lg">Ventas</flux:heading><flux:text>Actividad comercial confirmada y ventas anuladas por separado.</flux:text></div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <flux:card><flux:text>Número de ventas</flux:text><div class="mt-2 text-xl font-semibold">{{ $report['sales']['count'] }}</div></flux:card>
                <flux:card><flux:text>Ventas anuladas</flux:text><div class="mt-2 text-xl font-semibold">{{ $report['sales']['voided_count'] }}</div></flux:card>
                @if ($capabilities['financial'])
                    <flux:card><flux:text>Monto vendido</flux:text><div class="mt-2 text-xl font-semibold">{{ $this->money($report['sales']['total_sold_base']) }}</div></flux:card>
                    <flux:card><flux:text>Cobros en efectivo</flux:text><div class="mt-2 text-xl font-semibold text-green-700 dark:text-green-400">{{ $this->money($report['sales']['cash_collected_base']) }}</div><flux:text size="sm">Pagos recibidos mediante el método Efectivo.</flux:text></flux:card>
                    <flux:card><flux:text>Cobros por QR</flux:text><div class="mt-2 text-xl font-semibold text-blue-700 dark:text-blue-400">{{ $this->money($report['sales']['qr_collected_base']) }}</div><flux:text size="sm">Pagos recibidos mediante QR.</flux:text></flux:card>
                    @if(bccomp($report['sales']['other_collected_base'], '0', 4) === 1)<flux:card><flux:text>Otros métodos</flux:text><div class="mt-2 text-xl font-semibold">{{ $this->money($report['sales']['other_collected_base']) }}</div><flux:text size="sm">Transferencias u otras formas de pago.</flux:text></flux:card>@endif
                    <flux:card class="ring-1 ring-green-200 dark:ring-green-900"><flux:text>Total cobrado</flux:text><div class="mt-2 text-xl font-semibold">{{ $this->money($report['sales']['collected_base']) }}</div><flux:text size="sm">Suma de todos los pagos reales del período.</flux:text></flux:card>
                    <flux:card><flux:text>Saldo pendiente</flux:text><div class="mt-2 text-xl font-semibold">{{ $this->money($report['sales']['pending_base']) }}</div></flux:card>
                    <flux:card><flux:text>Ticket promedio</flux:text><div class="mt-2 text-xl font-semibold">{{ $report['sales']['average_ticket_base'] === null ? '—' : $this->money($report['sales']['average_ticket_base']) }}</div></flux:card>
                @endif
            </div>
            <div class="grid gap-4 lg:grid-cols-2">
                @if ($capabilities['financial'])
                    <flux:card><flux:heading>Métodos de pago</flux:heading><div class="mt-4 divide-y divide-zinc-200 dark:divide-zinc-700">@forelse($report['sales']['by_payment_method'] as $method)<div class="flex justify-between gap-4 py-3"><span>{{ $method['name'] }}</span><strong>{{ $this->money($method['amount_base']) }}</strong></div>@empty<flux:text class="py-3">Sin pagos en el período.</flux:text>@endforelse</div></flux:card>
                @endif
                <flux:card><flux:heading>Ventas por responsable</flux:heading><div class="mt-4 divide-y divide-zinc-200 dark:divide-zinc-700">@forelse($report['sales']['by_responsible'] as $responsible)<div class="flex justify-between gap-4 py-3"><span>{{ $responsible['name'] }}</span><strong>{{ $responsible['count'] }} ventas</strong></div>@empty<flux:text class="py-3">Sin ventas en el período.</flux:text>@endforelse</div></flux:card>
            </div>
        </section>
    @endif

    @if ($report['orders'] !== null)
        <section class="space-y-4">
            <div><flux:heading size="lg">Pedidos / Reservas</flux:heading><flux:text>Fechas y estado actual de los pedidos registrados en el período.</flux:text></div>
            <flux:card class="overflow-hidden p-0!">
                <div class="overflow-x-auto"><flux:table><flux:table.columns><flux:table.column>Nro. venta</flux:table.column><flux:table.column>Cliente</flux:table.column><flux:table.column>Fecha de reserva / venta</flux:table.column><flux:table.column>Fecha de entrega</flux:table.column><flux:table.column>Estado del pedido</flux:table.column>@if($capabilities['financial'])<flux:table.column align="end">Total</flux:table.column>@endif<flux:table.column>Estado de pago</flux:table.column></flux:table.columns><flux:table.rows>@forelse($report['orders'] as $order)<flux:table.row :key="$order['number']"><flux:table.cell variant="strong">{{ $order['number'] }}</flux:table.cell><flux:table.cell>{{ $order['customer'] }}</flux:table.cell><flux:table.cell>{{ $order['occurred_at']->timezone($companyTimezone)->format('d/m/Y H:i') }}</flux:table.cell><flux:table.cell>{{ $order['delivery_at']?->timezone($companyTimezone)->format('d/m/Y H:i') ?? 'No indicada' }}</flux:table.cell><flux:table.cell>{{ $order['order_status'] }}</flux:table.cell>@if($capabilities['financial'])<flux:table.cell align="end">{{ $this->money($order['total_base']) }}</flux:table.cell>@endif<flux:table.cell>{{ $order['payment_status'] }}</flux:table.cell></flux:table.row>@empty<flux:table.row><flux:table.cell :colspan="$capabilities['financial'] ? 7 : 6"><div class="py-8 text-center"><flux:text>No hay pedidos en este período.</flux:text></div></flux:table.cell></flux:table.row>@endforelse</flux:table.rows></flux:table></div>
            </flux:card>
        </section>
    @endif

    @if ($report['extras'] !== null)
        <section class="space-y-4">
            <div><flux:heading size="lg">Extras vendidos</flux:heading><flux:text>Resumen basado en el nombre y precio guardados en cada venta histórica.</flux:text></div>
            <flux:card class="overflow-hidden p-0!">
                <div class="overflow-x-auto"><flux:table><flux:table.columns><flux:table.column>Extra</flux:table.column><flux:table.column align="end">Cantidad</flux:table.column>@if($capabilities['financial'])<flux:table.column align="end">Importe total</flux:table.column>@endif</flux:table.columns><flux:table.rows>@forelse($report['extras'] as $extra)<flux:table.row :key="$extra['name']"><flux:table.cell variant="strong">{{ $extra['name'] }}</flux:table.cell><flux:table.cell align="end">{{ $this->quantity($extra['quantity']) }}</flux:table.cell>@if($capabilities['financial'])<flux:table.cell align="end">{{ $this->money($extra['amount_base']) }}</flux:table.cell>@endif</flux:table.row>@empty<flux:table.row><flux:table.cell :colspan="$capabilities['financial'] ? 3 : 2"><div class="py-8 text-center"><flux:text>No hay extras vendidos en este período.</flux:text></div></flux:table.cell></flux:table.row>@endforelse</flux:table.rows></flux:table></div>
            </flux:card>
        </section>
    @endif

    @if ($report['bouquets'])
        <section class="space-y-4">
            <div><flux:heading size="lg">Ramos</flux:heading><flux:text>Los costos corresponden al momento de cada venta, no al costo actual de los insumos.</flux:text></div>
            <flux:card class="overflow-hidden p-0!">
                <div class="overflow-x-auto"><flux:table><flux:table.columns><flux:table.column>Ramo</flux:table.column><flux:table.column align="end">Cantidad vendida</flux:table.column>@if($capabilities['financial'])<flux:table.column align="end">Ingreso generado</flux:table.column><flux:table.column align="end">Costo histórico</flux:table.column><flux:table.column align="end">Margen bruto estimado</flux:table.column>@endif</flux:table.columns><flux:table.rows>@forelse($report['bouquets']['top'] as $bouquet)<flux:table.row><flux:table.cell variant="strong">{{ $bouquet['name'] }}</flux:table.cell><flux:table.cell align="end">{{ $this->quantity($bouquet['quantity']) }}</flux:table.cell>@if($capabilities['financial'])<flux:table.cell align="end">{{ $this->money($bouquet['income_base']) }}</flux:table.cell><flux:table.cell align="end">{{ $this->money($bouquet['cost_base']) }}</flux:table.cell><flux:table.cell align="end">{{ $this->money($bouquet['margin_base']) }}</flux:table.cell>@endif</flux:table.row>@empty<flux:table.row><flux:table.cell :colspan="$capabilities['financial'] ? 5 : 2"><div class="py-8 text-center"><flux:text>No hay ramos vendidos en este período.</flux:text></div></flux:table.cell></flux:table.row>@endforelse</flux:table.rows></flux:table></div>
            </flux:card>
        </section>
    @endif

    @if ($report['inventory'])
        <section class="space-y-4">
            <div><flux:heading size="lg">Inventario</flux:heading><flux:text>Estado actual del stock y movimientos registrados durante el período.</flux:text></div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @if ($capabilities['financial'])<flux:card><flux:text>Valor total actual</flux:text><div class="mt-2 text-xl font-semibold">{{ $this->money($report['inventory']['current_value_base']) }}</div><flux:text size="sm">Valor vigente de todos los insumos disponibles.</flux:text></flux:card>@endif
                <flux:card><flux:text>Insumos con stock</flux:text><div class="mt-2 text-xl font-semibold">{{ $report['inventory']['products_with_stock'] }}</div></flux:card>
                <flux:card><flux:text>Insumos sin stock</flux:text><div class="mt-2 text-xl font-semibold">{{ $report['inventory']['products_without_stock'] }}</div></flux:card>
                <flux:card><flux:text>Stock bajo</flux:text><div class="mt-2 text-xl font-semibold">No configurado</div><flux:text size="sm">Se mostrará cuando exista un nivel mínimo por insumo.</flux:text></flux:card>
                <flux:card><flux:text>Compras / entradas registradas</flux:text><div class="mt-2 text-xl font-semibold">{{ $report['inventory']['purchase_movements'] }}</div><flux:text size="sm">{{ $this->quantity($report['inventory']['purchased_quantity']) }} unidades ingresadas.</flux:text></flux:card>
                <flux:card><flux:text>Salidas de inventario</flux:text><div class="mt-2 text-xl font-semibold">{{ $this->quantity($report['inventory']['outbound_quantity']) }}</div><flux:text size="sm">Incluye consumo por ventas y salidas manuales.</flux:text></flux:card>
                <flux:card><flux:text>Mermas</flux:text><div class="mt-2 text-xl font-semibold">{{ $this->quantity($report['inventory']['waste_quantity']) }}</div></flux:card>
                @if ($capabilities['financial'])<flux:card><flux:text>Costo de compras / entradas</flux:text><div class="mt-2 text-xl font-semibold">{{ $this->money($report['inventory']['purchase_cost_base']) }}</div></flux:card>@endif
            </div>
            <flux:card><flux:heading>Insumos más consumidos</flux:heading><div class="mt-4 divide-y divide-zinc-200 dark:divide-zinc-700">@forelse($report['inventory']['most_consumed'] as $supply)<div class="flex justify-between gap-4 py-3"><span>{{ $supply['name'] }}</span><strong>{{ $this->quantity($supply['quantity']) }}</strong></div>@empty<flux:text class="py-3">Sin consumos en el período.</flux:text>@endforelse</div></flux:card>
        </section>
    @endif

    @if ($report['expenses'])
        <section class="space-y-4">
            <div><flux:heading size="lg">Gastos</flux:heading><flux:text>Solo se consideran gastos confirmados; los anulados quedan fuera.</flux:text></div>
            <div class="grid gap-4 sm:grid-cols-3"><flux:card><flux:text>Total de gastos</flux:text><div class="mt-2 text-xl font-semibold">{{ $this->money($report['expenses']['total_base']) }}</div></flux:card><flux:card><flux:text>Con factura</flux:text><div class="mt-2 text-xl font-semibold">{{ $this->money($report['expenses']['with_invoice_base']) }}</div></flux:card><flux:card><flux:text>Sin factura</flux:text><div class="mt-2 text-xl font-semibold">{{ $this->money($report['expenses']['without_invoice_base']) }}</div></flux:card></div>
            <div class="grid gap-4 lg:grid-cols-3">
                @foreach(['by_category' => 'Por categoría', 'by_payment_method' => 'Por método de pago', 'by_responsible' => 'Por responsable'] as $key => $title)
                    <flux:card><flux:heading>{{ $title }}</flux:heading><div class="mt-4 divide-y divide-zinc-200 dark:divide-zinc-700">@forelse($report['expenses'][$key] as $row)<div class="flex justify-between gap-4 py-3"><span>{{ $row['name'] }}</span><strong>{{ $this->money($row['amount_base']) }}</strong></div>@empty<flux:text class="py-3">Sin gastos en el período.</flux:text>@endforelse</div></flux:card>
                @endforeach
            </div>
        </section>
    @endif

    @if ($report['profit'])
        <section class="space-y-4">
            <div><flux:heading size="lg">Ganancias</flux:heading><flux:text>Estimación operativa para tomar decisiones; no sustituye una utilidad contable oficial.</flux:text></div>
            <flux:card class="max-w-3xl space-y-4">
                <div class="flex justify-between gap-4"><span>Total vendido</span><strong>{{ $this->money($report['profit']['total_sold_base']) }}</strong></div>
                <div class="flex justify-between gap-4"><span>− Costo histórico de ramos vendidos</span><strong>{{ $this->money($report['profit']['historical_cost_base']) }}</strong></div>
                <div class="flex justify-between gap-4 border-t border-zinc-200 pt-4 dark:border-zinc-700"><span>Margen bruto</span><strong>{{ $this->money($report['profit']['gross_margin_base']) }}</strong></div>
                <div class="flex justify-between gap-4"><span>− Gastos operativos</span><strong>{{ $this->money($report['profit']['expenses_base']) }}</strong></div>
                <div class="flex justify-between gap-4 border-t border-zinc-200 pt-4 text-lg dark:border-zinc-700"><span>Resultado estimado</span><strong>{{ $this->money($report['profit']['estimated_result_base']) }}</strong></div>
                <flux:text>Ventas menos costo de los ramos y gastos registrados.</flux:text>
            </flux:card>
        </section>
    @endif

    <flux:card class="space-y-4">
        <div><flux:heading size="lg">Exportar reportes</flux:heading><flux:text>La descarga respeta el período seleccionado y solo incluye la información autorizada para tu usuario.</flux:text></div>
        <div class="grid items-end gap-3 md:grid-cols-2 xl:grid-cols-4">
            <flux:select wire:model.live="exportScope" label="Exportar">
                <flux:select.option value="current">Sección actual</flux:select.option>
                <flux:select.option value="full">Reporte completo</flux:select.option>
            </flux:select>
            @if($exportScope === 'current')
                <flux:select wire:model="exportSection" label="Sección">
                    <flux:select.option value="summary">Resumen</flux:select.option>
                    @if($capabilities['sales'])<flux:select.option value="sales">Ventas</flux:select.option><flux:select.option value="orders">Pedidos y reservas</flux:select.option><flux:select.option value="bouquets">Ramos</flux:select.option><flux:select.option value="extras">Extras vendidos</flux:select.option>@endif
                    @if($capabilities['inventory'])<flux:select.option value="inventory">Inventario</flux:select.option>@endif
                    @if($capabilities['expenses'])<flux:select.option value="expenses">Gastos</flux:select.option>@endif
                    @if($capabilities['financial'])<flux:select.option value="profit">Ganancias</flux:select.option>@endif
                </flux:select>
            @endif
            <flux:button icon="document-arrow-down" :href="$this->exportUrl('pdf')">Exportar PDF</flux:button>
            <flux:button variant="primary" icon="table-cells" :href="$this->exportUrl('xlsx')">Exportar Excel</flux:button>
        </div>
    </flux:card>
</div>
