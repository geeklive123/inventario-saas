<?php

namespace App\Services\Reports;

use App\Enums\ExpenseStatus;
use App\Enums\SaleStatus;
use App\Models\Expense;
use App\Models\Membership;
use App\Models\Sale;
use App\Models\StockBalance;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * @phpstan-type ReportMetric array{label: string, value: mixed, type: string}
 * @phpstan-type ReportColumn array{key: string, label: string, type: string}
 * @phpstan-type ReportSection array{key: string, title: string, metrics: list<ReportMetric>, columns: list<ReportColumn>, rows: list<array<string, mixed>>}
 */
class ReportExportData
{
    public function __construct(private BusinessReports $reports) {}

    /**
     * @return array{
     *     company: string,
     *     currency: string,
     *     report_name: string,
     *     period: string,
     *     filters: string,
     *     generated_at: CarbonInterface,
     *     sections: list<array{key: string, title: string, metrics: list<array{label: string, value: mixed, type: string}>, columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>}>
     * }
     */
    public function build(
        Membership $membership,
        string $period,
        ?string $dateFrom,
        ?string $dateTo,
        string $scope,
        string $section,
    ): array {
        $report = $this->reports->summary($membership, $period, $dateFrom, $dateTo);
        $membership->loadMissing('company.baseCurrency');
        $sections = $this->sections($membership, $report);

        if ($scope === 'current') {
            if (! isset($sections[$section])) {
                throw new AuthorizationException('No tienes permiso para exportar esta sección.');
            }

            $sections = [$section => $sections[$section]];
        }

        $timezone = $membership->company->timezone;
        $from = $report['from']->timezone($timezone);
        $to = $report['to']->timezone($timezone);

        return [
            'company' => $membership->company->name,
            'currency' => $membership->company->baseCurrency->code,
            'report_name' => $scope === 'full' ? 'Reporte completo' : $sections[array_key_first($sections)]['title'],
            'period' => $this->periodLabel($period, $from->format('d/m/Y'), $to->format('d/m/Y')),
            'filters' => $period === 'custom' ? "Desde {$from->format('d/m/Y')} hasta {$to->format('d/m/Y')}" : 'Período: '.$this->periodName($period),
            'generated_at' => now($timezone),
            'sections' => array_values($sections),
        ];
    }

    /** @param array<string, mixed> $report
     * @return array<string, ReportSection>
     */
    private function sections(Membership $membership, array $report): array
    {
        $capabilities = $report['capabilities'];
        $financial = $capabilities['financial'];
        $sections = ['summary' => $this->summarySection($report)];

        if ($capabilities['sales']) {
            $sections['sales'] = $this->salesSection($membership, $report, $financial);
            $sections['bouquets'] = $this->bouquetsSection($report, $financial);
        }

        if ($capabilities['inventory']) {
            $sections['inventory'] = $this->inventorySection($membership, $report, $financial);
        }

        if ($capabilities['expenses']) {
            $sections['expenses'] = $this->expensesSection($membership, $report);
        }

        if ($financial) {
            $sections['profit'] = $this->profitSection($report);
        }

        return $sections;
    }

    /** @param array<string, mixed> $report
     * @return ReportSection
     */
    private function summarySection(array $report): array
    {
        $metrics = [];
        $labels = [
            'sales_count' => ['Ventas realizadas', 'integer'],
            'total_sold_base' => ['Total vendido', 'money'],
            'collected_base' => ['Dinero cobrado', 'money'],
            'pending_base' => ['Pendiente por cobrar', 'money'],
            'expenses_base' => ['Gastos', 'money'],
            'historical_cost_base' => ['Costo de ramos vendidos', 'money'],
            'estimated_result_base' => ['Resultado estimado', 'money'],
        ];

        foreach ($labels as $key => [$label, $type]) {
            if ($report['general'][$key] !== null) {
                $metrics[] = compact('label', 'type') + ['value' => $report['general'][$key]];
            }
        }

        return $this->section('summary', 'Resumen', $metrics, [], []);
    }

    /** @param array<string, mixed> $report
     * @return ReportSection
     */
    private function salesSection(Membership $membership, array $report, bool $financial): array
    {
        $metrics = [
            ['label' => 'Número de ventas', 'value' => $report['sales']['count'], 'type' => 'integer'],
            ['label' => 'Ventas anuladas', 'value' => $report['sales']['voided_count'], 'type' => 'integer'],
        ];
        $columns = [
            ['key' => 'date', 'label' => 'Fecha', 'type' => 'date'],
            ['key' => 'number', 'label' => 'Número', 'type' => 'text'],
            ['key' => 'customer', 'label' => 'Cliente', 'type' => 'text'],
            ['key' => 'bouquets', 'label' => 'Ramos', 'type' => 'text'],
            ['key' => 'payment_status', 'label' => 'Pago', 'type' => 'text'],
            ['key' => 'responsible', 'label' => 'Responsable', 'type' => 'text'],
        ];

        if ($financial) {
            array_push($metrics,
                ['label' => 'Monto vendido', 'value' => $report['sales']['total_sold_base'], 'type' => 'money'],
                ['label' => 'Monto cobrado', 'value' => $report['sales']['collected_base'], 'type' => 'money'],
                ['label' => 'Saldo pendiente', 'value' => $report['sales']['pending_base'], 'type' => 'money'],
            );
            array_push($columns,
                ['key' => 'total', 'label' => 'Total', 'type' => 'money'],
                ['key' => 'paid', 'label' => 'Pagado', 'type' => 'money'],
                ['key' => 'balance', 'label' => 'Saldo', 'type' => 'money'],
            );
        }

        $rows = Sale::query()->where('company_id', $membership->company_id)
            ->where('status', SaleStatus::Confirmed)
            ->whereBetween('occurred_at', [$report['from'], $report['to']])
            ->with(['items:id,sale_id,product_name,quantity', 'confirmedBy.user:id,name'])
            ->latest('occurred_at')->get()->map(function (Sale $sale) use ($financial): array {
                $row = [
                    'date' => $sale->occurred_at,
                    'number' => $sale->number,
                    'customer' => $sale->customer_name ?: 'Consumidor final',
                    'bouquets' => $sale->items->map(fn ($item): string => "{$item->quantity} {$item->product_name}")->join(', '),
                    'payment_status' => $sale->payment_status->label(),
                    'responsible' => $sale->confirmedBy->user->name,
                ];

                if ($financial) {
                    $row += ['total' => $sale->total_base, 'paid' => $sale->paid_total_base, 'balance' => $sale->balance_due_base];
                }

                return $row;
            })->all();

        $rows = array_values($rows);

        return $this->section('sales', 'Ventas', $metrics, $columns, $rows);
    }

    /** @param array<string, mixed> $report
     * @return ReportSection
     */
    private function bouquetsSection(array $report, bool $financial): array
    {
        $columns = [
            ['key' => 'name', 'label' => 'Ramo', 'type' => 'text'],
            ['key' => 'quantity', 'label' => 'Cantidad vendida', 'type' => 'quantity'],
        ];

        if ($financial) {
            array_push($columns,
                ['key' => 'income_base', 'label' => 'Ingreso generado', 'type' => 'money'],
                ['key' => 'cost_base', 'label' => 'Costo histórico', 'type' => 'money'],
                ['key' => 'margin_base', 'label' => 'Margen bruto estimado', 'type' => 'money'],
            );
        }

        return $this->section(
            'bouquets',
            'Ramos',
            [['label' => 'Ramos vendidos', 'value' => $report['bouquets']['quantity'], 'type' => 'quantity']],
            $columns,
            $report['bouquets']['top'],
        );
    }

    /** @param array<string, mixed> $report
     * @return ReportSection
     */
    private function inventorySection(Membership $membership, array $report, bool $financial): array
    {
        $metrics = [
            ['label' => 'Insumos con stock', 'value' => $report['inventory']['products_with_stock'], 'type' => 'integer'],
            ['label' => 'Insumos sin stock', 'value' => $report['inventory']['products_without_stock'], 'type' => 'integer'],
            ['label' => 'Compras / entradas', 'value' => $report['inventory']['purchase_movements'], 'type' => 'integer'],
            ['label' => 'Cantidad comprada', 'value' => $report['inventory']['purchased_quantity'], 'type' => 'quantity'],
            ['label' => 'Salidas', 'value' => $report['inventory']['outbound_quantity'], 'type' => 'quantity'],
            ['label' => 'Mermas', 'value' => $report['inventory']['waste_quantity'], 'type' => 'quantity'],
        ];
        $columns = [
            ['key' => 'product', 'label' => 'Insumo', 'type' => 'text'],
            ['key' => 'warehouse', 'label' => 'Almacén', 'type' => 'text'],
            ['key' => 'quantity', 'label' => 'Cantidad disponible', 'type' => 'quantity'],
            ['key' => 'unit', 'label' => 'Unidad', 'type' => 'text'],
        ];

        if ($financial) {
            array_unshift($metrics, ['label' => 'Valor total actual', 'value' => $report['inventory']['current_value_base'], 'type' => 'money']);
            array_push($columns,
                ['key' => 'average_cost', 'label' => 'Costo promedio', 'type' => 'money'],
                ['key' => 'inventory_value', 'label' => 'Valor en stock', 'type' => 'money'],
            );
        }

        $rows = StockBalance::query()->where('company_id', $membership->company_id)
            ->with(['product.unit:id,symbol', 'warehouse:id,name'])->orderBy('product_id')->get()
            ->map(function (StockBalance $balance) use ($financial): array {
                $row = [
                    'product' => $balance->product->name,
                    'warehouse' => $balance->warehouse->name,
                    'quantity' => $balance->quantity,
                    'unit' => $balance->product->unit->symbol,
                ];

                if ($financial) {
                    $row += ['average_cost' => $balance->average_unit_cost_base, 'inventory_value' => $balance->inventory_value_base];
                }

                return $row;
            })->all();

        $rows = array_values($rows);

        return $this->section('inventory', 'Inventario', $metrics, $columns, $rows);
    }

    /** @param array<string, mixed> $report
     * @return ReportSection
     */
    private function expensesSection(Membership $membership, array $report): array
    {
        $metrics = [
            ['label' => 'Total de gastos', 'value' => $report['expenses']['total_base'], 'type' => 'money'],
            ['label' => 'Con factura', 'value' => $report['expenses']['with_invoice_base'], 'type' => 'money'],
            ['label' => 'Sin factura', 'value' => $report['expenses']['without_invoice_base'], 'type' => 'money'],
        ];
        $columns = [
            ['key' => 'date', 'label' => 'Fecha', 'type' => 'date'],
            ['key' => 'number', 'label' => 'Número', 'type' => 'text'],
            ['key' => 'concept', 'label' => 'Concepto', 'type' => 'text'],
            ['key' => 'category', 'label' => 'Categoría', 'type' => 'text'],
            ['key' => 'payment_method', 'label' => 'Método de pago', 'type' => 'text'],
            ['key' => 'responsible', 'label' => 'Responsable', 'type' => 'text'],
            ['key' => 'amount', 'label' => 'Importe', 'type' => 'money'],
        ];
        $rows = Expense::query()->where('company_id', $membership->company_id)
            ->where('status', ExpenseStatus::Confirmed)
            ->whereBetween('occurred_at', [$report['from'], $report['to']])
            ->with('responsible.user:id,name')->latest('occurred_at')->get()
            ->map(fn (Expense $expense): array => [
                'date' => $expense->occurred_at,
                'number' => $expense->number,
                'concept' => $expense->concept,
                'category' => $expense->category_name,
                'payment_method' => $expense->payment_method_name ?: 'No indicado',
                'responsible' => $expense->responsible->user->name,
                'amount' => $expense->amount_base,
            ])->all();

        $rows = array_values($rows);

        return $this->section('expenses', 'Gastos', $metrics, $columns, $rows);
    }

    /** @param array<string, mixed> $report
     * @return ReportSection
     */
    private function profitSection(array $report): array
    {
        $metrics = [];

        foreach ([
            'total_sold_base' => 'Total vendido',
            'historical_cost_base' => 'Costo histórico de ramos vendidos',
            'gross_margin_base' => 'Margen bruto',
            'expenses_base' => 'Gastos operativos',
            'estimated_result_base' => 'Resultado estimado',
        ] as $key => $label) {
            if ($report['profit'][$key] !== null) {
                $metrics[] = ['label' => $label, 'value' => $report['profit'][$key], 'type' => 'money'];
            }
        }

        return $this->section('profit', 'Ganancias', $metrics, [], []);
    }

    /** @param list<ReportMetric> $metrics
     * @param  list<ReportColumn>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @return ReportSection
     */
    private function section(string $key, string $title, array $metrics, array $columns, array $rows): array
    {
        return compact('key', 'title', 'metrics', 'columns', 'rows');
    }

    private function periodLabel(string $period, string $from, string $to): string
    {
        return $period === 'custom' ? "{$from} - {$to}" : $this->periodName($period)." ({$from} - {$to})";
    }

    private function periodName(string $period): string
    {
        return match ($period) {
            'today' => 'Hoy',
            'week' => 'Esta semana',
            'month' => 'Este mes',
            'custom' => 'Rango personalizado',
            default => throw new DomainException('El período seleccionado no es válido.'),
        };
    }
}
