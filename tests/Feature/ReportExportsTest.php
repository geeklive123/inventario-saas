<?php

use App\Actions\Modules\SetModuleStatus;
use App\Actions\Users\ConfigureWorkerAccess;
use App\Enums\CompanyModuleStatus;
use App\Enums\ExpenseStatus;
use App\Enums\MembershipStatus;
use App\Enums\ModuleCode;
use App\Enums\PaymentStatus;
use App\Enums\SaleStatus;
use App\Models\Expense;
use App\Models\Membership;
use App\Models\Module;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\User;
use App\Services\Reports\ReportExportData;
use Database\Seeders\DatabaseSeeder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

/** @return array{owner: User, membership: Membership} */
function reportExportContext(string $name): array
{
    $context = expenseContext($name);

    foreach ([ModuleCode::Inventory, ModuleCode::Sales] as $moduleCode) {
        $module = Module::query()->where('code', $moduleCode)->firstOrFail();
        app(SetModuleStatus::class)->handle($context['membership'], $module, CompanyModuleStatus::Enabled);
    }

    return ['owner' => $context['owner'], 'membership' => $context['membership']];
}

/** @return list<string> */
function workbookValues(BinaryFileResponse $response): array
{
    $spreadsheet = IOFactory::load($response->getFile()->getPathname());

    return collect($spreadsheet->getAllSheets())
        ->flatMap(fn ($sheet): array => collect($sheet->toArray())->flatten()->filter()->map(
            fn (mixed $value): string => (string) $value,
        )->values()->all())
        ->values()
        ->all();
}

test('owner exports complete professional xlsx and pdf reports', function () {
    $context = reportExportContext('Florería exportaciones');

    $xlsx = $this->actingAs($context['owner'])
        ->withSession(['current_membership_id' => $context['membership']->getKey()])
        ->get(route('reports.export', ['format' => 'xlsx', 'period' => 'month', 'scope' => 'full', 'section' => 'summary']))
        ->assertOk()
        ->assertDownload();

    expect($xlsx->baseResponse)->toBeInstanceOf(BinaryFileResponse::class);
    $spreadsheet = IOFactory::load($xlsx->baseResponse->getFile()->getPathname());

    expect($spreadsheet->getSheetNames())->toBe(['Resumen', 'Ventas', 'Pedidos y reservas', 'Ramos', 'Extras vendidos', 'Inventario', 'Gastos', 'Ganancias'])
        ->and($spreadsheet->getSheetByName('Ventas')?->getFreezePane())->not->toBeNull()
        ->and($spreadsheet->getSheetByName('Ventas')?->getAutoFilter()->getRange())->not->toBe('');

    $pdf = $this->actingAs($context['owner'])
        ->withSession(['current_membership_id' => $context['membership']->getKey()])
        ->get(route('reports.export', ['format' => 'pdf', 'period' => 'month', 'scope' => 'full', 'section' => 'summary']))
        ->assertOk()
        ->assertDownload();

    expect($pdf->headers->get('content-type'))->toContain('application/pdf')
        ->and($pdf->getContent())->toStartWith('%PDF');
});

test('pdf and xlsx include cash qr and total collection breakdowns', function () {
    $context = reportExportContext('Florería desglose cobros');
    $companyId = $context['membership']->company_id;
    $methods = PaymentMethod::query()->withoutGlobalScope('company')
        ->where('company_id', $companyId)->get()->keyBy('code');
    $sale = Sale::factory()->create([
        'company_id' => $companyId,
        'confirmed_by_membership_id' => $context['membership']->getKey(),
        'total_base' => 300,
        'paid_total_base' => 300,
        'balance_due_base' => 0,
        'payment_status' => PaymentStatus::Paid,
    ]);
    foreach ([['cash', 100], ['qr', 200]] as [$code, $amount]) {
        SalePayment::factory()->create([
            'company_id' => $companyId,
            'sale_id' => $sale->getKey(),
            'payment_method_id' => $methods[$code]->getKey(),
            'payment_method_name' => $methods[$code]->name,
            'received_by_membership_id' => $context['membership']->getKey(),
            'amount_base' => $amount,
            'occurred_at' => now(),
        ]);
    }

    $document = app(ReportExportData::class)->build(
        $context['membership'], 'today', null, null, 'current', 'sales',
    );
    $pdfHtml = view('reports.pdf', compact('document'))->render();

    expect($pdfHtml)->toContain('Cobros en efectivo', 'Cobros por QR', 'Total cobrado')
        ->toContain('100,00', '200,00', '300,00');

    $xlsx = $this->actingAs($context['owner'])
        ->withSession(['current_membership_id' => $context['membership']->getKey()])
        ->get(route('reports.export', [
            'format' => 'xlsx', 'period' => 'today', 'scope' => 'current', 'section' => 'sales',
        ]))->assertOk();
    $values = workbookValues($xlsx->baseResponse);

    expect($values)->toContain('Cobros en efectivo', 'Cobros por QR', 'Total cobrado')
        ->and(collect($values)->map(fn (string $value): float => (float) $value)->all())
        ->toContain(100.0, 200.0, 300.0);
});

test('current section export contains only the selected authorized sheet', function () {
    $context = reportExportContext('Florería sección actual');

    $response = $this->actingAs($context['owner'])
        ->withSession(['current_membership_id' => $context['membership']->getKey()])
        ->get(route('reports.export', ['format' => 'xlsx', 'period' => 'today', 'scope' => 'current', 'section' => 'inventory']))
        ->assertOk();

    $spreadsheet = IOFactory::load($response->baseResponse->getFile()->getPathname());

    expect($spreadsheet->getSheetNames())->toBe(['Inventario']);
});

test('exports respect dates statuses partial payments and company isolation', function () {
    $companyA = reportExportContext('Florería A');
    $companyB = reportExportContext('Florería B');
    $sale = Sale::factory()->create([
        'company_id' => $companyA['membership']->company_id,
        'confirmed_by_membership_id' => $companyA['membership']->getKey(),
        'number' => 'VENTA-PARCIAL-A',
        'occurred_at' => now(),
        'total_base' => 100,
        'paid_total_base' => 40,
        'balance_due_base' => 60,
        'payment_status' => PaymentStatus::Partial,
    ]);
    SaleItem::factory()->create([
        'company_id' => $companyA['membership']->company_id,
        'sale_id' => $sale->getKey(),
        'product_name' => 'Ramo con costo histórico',
        'total_cost_base' => 35,
        'gross_margin_base' => 65,
    ]);
    SalePayment::factory()->create([
        'company_id' => $companyA['membership']->company_id,
        'sale_id' => $sale->getKey(),
        'received_by_membership_id' => $companyA['membership']->getKey(),
        'amount_base' => 40,
        'occurred_at' => now(),
    ]);
    Sale::factory()->create([
        'company_id' => $companyA['membership']->company_id,
        'confirmed_by_membership_id' => $companyA['membership']->getKey(),
        'number' => 'VENTA-ANULADA-A',
        'status' => SaleStatus::Voided,
        'occurred_at' => now(),
    ]);
    Sale::factory()->create([
        'company_id' => $companyA['membership']->company_id,
        'confirmed_by_membership_id' => $companyA['membership']->getKey(),
        'number' => 'VENTA-ANTIGUA-A',
        'occurred_at' => now()->subMonths(2),
    ]);
    Sale::factory()->create([
        'company_id' => $companyB['membership']->company_id,
        'confirmed_by_membership_id' => $companyB['membership']->getKey(),
        'number' => 'VENTA-EMPRESA-B',
        'occurred_at' => now(),
    ]);
    Expense::factory()->create([
        'company_id' => $companyA['membership']->company_id,
        'membership_id' => $companyA['membership']->getKey(),
        'concept' => 'Gasto confirmado visible',
        'status' => ExpenseStatus::Confirmed,
        'occurred_at' => now(),
    ]);
    Expense::factory()->create([
        'company_id' => $companyA['membership']->company_id,
        'membership_id' => $companyA['membership']->getKey(),
        'concept' => 'Gasto anulado oculto',
        'status' => ExpenseStatus::Cancelled,
        'cancelled_by_membership_id' => $companyA['membership']->getKey(),
        'cancelled_at' => now(),
        'cancellation_reason' => 'Prueba',
        'occurred_at' => now(),
    ]);

    $companyDate = now($companyA['membership']->company->timezone)->toDateString();

    $response = $this->actingAs($companyA['owner'])
        ->withSession(['current_membership_id' => $companyA['membership']->getKey()])
        ->get(route('reports.export', [
            'format' => 'xlsx',
            'period' => 'custom',
            'date_from' => $companyDate,
            'date_to' => $companyDate,
            'scope' => 'full',
            'section' => 'summary',
        ]))->assertOk();

    $values = workbookValues($response->baseResponse);
    $text = implode('|', $values);

    expect($text)->toContain('VENTA-PARCIAL-A')
        ->toContain('Ramo con costo histórico')
        ->toContain('Gasto confirmado visible')
        ->not->toContain('VENTA-ANULADA-A')
        ->not->toContain('VENTA-ANTIGUA-A')
        ->not->toContain('VENTA-EMPRESA-B')
        ->not->toContain('Gasto anulado oculto')
        ->and($values)->toContain('100', '40', '60', '35');
});

test('worker exports only authorized non financial report data', function () {
    $context = reportExportContext('Florería permisos exportación');
    $workerUser = User::factory()->create();
    $worker = Membership::factory()->create([
        'company_id' => $context['membership']->company_id,
        'user_id' => $workerUser->getKey(),
        'status' => MembershipStatus::Active,
        'is_owner' => false,
    ]);
    app(ConfigureWorkerAccess::class)->handle(
        $context['membership'],
        $worker,
        'Personalizado',
        ['reports.sales.view'],
    );

    $this->actingAs($workerUser)
        ->withSession(['current_membership_id' => $worker->getKey()])
        ->get(route('reports.export', ['format' => 'xlsx', 'period' => 'month', 'scope' => 'current', 'section' => 'profit']))
        ->assertForbidden();

    $response = $this->actingAs($workerUser)
        ->withSession(['current_membership_id' => $worker->getKey()])
        ->get(route('reports.export', ['format' => 'xlsx', 'period' => 'month', 'scope' => 'current', 'section' => 'sales']))
        ->assertOk();

    $spreadsheet = IOFactory::load($response->baseResponse->getFile()->getPathname());
    $values = collect($spreadsheet->getActiveSheet()->toArray())->flatten()->filter()->all();

    expect($spreadsheet->getSheetNames())->toBe(['Ventas'])
        ->and($values)->not->toContain('Monto vendido', 'Monto cobrado', 'Saldo pendiente', 'Total', 'Pagado', 'Saldo');
});
