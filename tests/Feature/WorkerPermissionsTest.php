<?php

use App\Actions\Expenses\CreateExpense;
use App\Actions\Users\ConfigureWorkerAccess;
use App\Actions\Users\CreateWorker;
use App\Models\ExpenseCategory;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Authorization\WorkerPermissionCatalog;
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\DatabaseSeeder;
use Livewire\Livewire;

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

test('custom worker access uses a company role and grants only selected expense permissions', function () {
    $context = expenseContext('Permisos');
    $baseRole = Role::query()->withoutGlobalScope('company')->where('company_id', $context['company']->getKey())->where('name', 'Trabajador')->firstOrFail();
    $worker = app(CreateWorker::class)->handle($context['membership'], $baseRole, [
        'name' => 'Ana', 'email' => 'ana.permissions@example.com', 'password' => 'Temporary-Password-123!',
    ]);
    app(ConfigureWorkerAccess::class)->handle($context['membership'], $worker, 'Personalizado', [
        'finance.expenses.view', 'finance.expenses.create',
    ]);
    $worker->user->update(['must_change_password' => false]);

    $category = ExpenseCategory::query()->withoutGlobalScope('company')->where('company_id', $context['company']->getKey())->firstOrFail();
    $expense = app(CreateExpense::class)->handle($worker->refresh(), $category, 30, 'Transporte');

    expect($expense->membership_id)->toBe($worker->getKey())
        ->and($worker->roles()->sole()->is_system)->toBeFalse()
        ->and($worker->roles()->sole()->permissions()->where('code', 'finance.expenses.cancel')->exists())->toBeFalse();

    $this->actingAs($worker->user)->withSession(['current_membership_id' => $worker->getKey()])
        ->get(route('finance.expenses'))->assertOk();
});

test('a worker without expense permission cannot register expenses', function () {
    $context = expenseContext('Sin permiso');
    $seller = Role::query()->withoutGlobalScope('company')->where('company_id', $context['company']->getKey())->where('name', 'Vendedor')->firstOrFail();
    $worker = app(CreateWorker::class)->handle($context['membership'], $seller, [
        'name' => 'Beto', 'email' => 'beto.permissions@example.com', 'password' => 'Temporary-Password-123!',
    ]);
    $worker->user->update(['must_change_password' => false]);
    $category = ExpenseCategory::query()->withoutGlobalScope('company')->where('company_id', $context['company']->getKey())->firstOrFail();

    expect(fn () => app(CreateExpense::class)->handle($worker, $category, 30, 'No autorizado'))
        ->toThrow(DomainException::class, 'no puede registrar gastos');

    $this->actingAs($worker->user)->withSession(['current_membership_id' => $worker->getKey()])
        ->get(route('finance.expenses'))->assertForbidden();
});

test('custom permission catalog exposes only business labels including finance', function () {
    $catalog = app(WorkerPermissionCatalog::class);
    $labels = collect($catalog->groups())
        ->flatMap(fn (array $group): array => collect($group['capabilities'])->pluck('label')->all());

    expect(array_column($catalog->groups(), 'label'))->toBe([
        'VENTAS', 'RAMOS E INSUMOS', 'INVENTARIO', 'FINANZAS', 'REPORTES', 'USUARIOS', 'CONFIGURACIÓN',
    ])->and($labels)->toContain(
        'Ver ventas', 'Crear insumos', 'Crear ramos', 'Registrar compras',
        'Editar gastos', 'Ver ganancias / resultado estimado', 'Cambiar permisos',
        'Administrar unidades', 'Ver reportes de ventas', 'Ver reportes financieros',
    )->and($labels->contains(fn (string $label): bool => str_contains($label, '.')))->toBeFalse()
        ->and($catalog->permissionCodes(['register_expenses']))->toBe(['finance.expenses.view', 'finance.expenses.create'])
        ->and($catalog->permissionCodes(['view_sales_reports']))->toBe(['reports.sales.view'])
        ->and($catalog->permissionCodes(['view_inventory_reports']))->toBe(['reports.inventory.view'])
        ->and($catalog->permissionCodes(['view_expense_reports']))->toBe(['reports.expenses.view'])
        ->and($catalog->permissionCodes(['view_financial_reports']))->toBe(['reports.financial.view'])
        ->and(Permission::query()->where('code', 'finance.expenses.update')->exists())->toBeTrue();
});

test('worker form renders friendly grouped permissions and never technical codes', function () {
    $context = expenseContext('UX permisos');
    $seller = Role::query()->withoutGlobalScope('company')->where('company_id', $context['company']->getKey())->where('name', 'Vendedor')->firstOrFail();
    $worker = app(CreateWorker::class)->handle($context['membership'], $seller, [
        'name' => 'Carmen', 'email' => 'carmen.permissions@example.com', 'password' => 'Temporary-Password-123!',
    ]);
    app(CurrentCompany::class)->set($context['membership']);

    Livewire::actingAs($context['owner'])->test('pages::users')
        ->call('edit', $worker->getKey())
        ->set('profile', 'Personalizado')
        ->assertSee('VENTAS')
        ->assertSee('FINANZAS')
        ->assertSee('REPORTES')
        ->assertSee('Ver reportes de gastos')
        ->assertSee('Registrar gastos')
        ->assertSee('Administrar sucursales')
        ->assertDontSee('core.branches.manage')
        ->assertDontSee('finance.expenses.create')
        ->assertDontSee('inventory.adjust');
});

test('quick profiles keep finance and administration out of seller and inventory roles', function () {
    $context = expenseContext('Perfiles rápidos');
    $roles = Role::query()->withoutGlobalScope('company')->where('company_id', $context['company']->getKey())
        ->whereIn('name', ['Vendedor', 'Inventario', 'Administrador'])->get()->keyBy('name');
    $sellerCodes = $roles['Vendedor']->permissions()->pluck('code');
    $inventoryCodes = $roles['Inventario']->permissions()->pluck('code');
    $administratorCodes = $roles['Administrador']->permissions()->pluck('code');

    expect($sellerCodes)->toContain('sales.view', 'sales.create', 'catalog.view', 'inventory.view')
        ->not->toContain('finance.expenses.view', 'core.users.view', 'sales.costs.view')
        ->and($inventoryCodes)->toContain('catalog.view', 'inventory.view', 'inventory.adjust', 'inventory.waste')
        ->not->toContain('finance.expenses.view', 'core.users.view', 'sales.view')
        ->and($administratorCodes->count())->toBe(Permission::query()->where('is_active', true)->count());
});

test('owner assigns persists and removes friendly report permissions from the worker form', function () {
    $context = expenseContext('Permisos de reportes');
    $seller = Role::query()->withoutGlobalScope('company')->where('company_id', $context['company']->getKey())->where('name', 'Vendedor')->firstOrFail();
    $worker = app(CreateWorker::class)->handle($context['membership'], $seller, [
        'name' => 'Daniela', 'email' => 'daniela.reports@example.com', 'password' => 'Temporary-Password-123!',
    ]);
    app(CurrentCompany::class)->set($context['membership']);
    $reportCapabilities = [
        'view_sales_reports', 'view_inventory_reports', 'view_expense_reports', 'view_financial_reports',
    ];

    Livewire::actingAs($context['owner'])->test('pages::users')
        ->call('edit', $worker->getKey())
        ->set('profile', 'Personalizado')
        ->set('selectedCapabilities', $reportCapabilities)
        ->call('save')
        ->assertHasNoErrors()
        ->call('edit', $worker->getKey())
        ->assertSet('profile', 'Personalizado')
        ->assertSet('selectedCapabilities', fn (array $selected): bool => collect($reportCapabilities)->every(
            fn (string $capability): bool => in_array($capability, $selected, true),
        ))
        ->set('selectedCapabilities', ['view_sales_reports'])
        ->call('save')
        ->assertHasNoErrors();

    $assignedCodes = $worker->refresh()->roles()->sole()->permissions()->pluck('code');

    expect($assignedCodes)->toContain('reports.sales.view')
        ->not->toContain('reports.inventory.view', 'reports.expenses.view', 'reports.financial.view');
});

test('worker with one report permission sees the sidebar and only the authorized report sections', function () {
    $context = expenseContext('Reportes parciales');
    $seller = Role::query()->withoutGlobalScope('company')->where('company_id', $context['company']->getKey())->where('name', 'Vendedor')->firstOrFail();
    $worker = app(CreateWorker::class)->handle($context['membership'], $seller, [
        'name' => 'Esteban', 'email' => 'esteban.reports@example.com', 'password' => 'Temporary-Password-123!',
    ]);
    app(ConfigureWorkerAccess::class)->handle($context['membership'], $worker, 'Personalizado', ['reports.sales.view']);
    $worker->user->update(['must_change_password' => false]);

    $this->actingAs($worker->user)->withSession(['current_membership_id' => $worker->getKey()])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Reportes');

    $this->actingAs($worker->user)->withSession(['current_membership_id' => $worker->getKey()])
        ->get(route('reports.index'))
        ->assertOk()
        ->assertSee('Ventas')
        ->assertDontSee('Ganancias')
        ->assertDontSee('Gastos operativos');
});

test('worker without report permissions neither sees nor enters reports while owner has complete access', function () {
    $context = expenseContext('Reportes protegidos');
    $seller = Role::query()->withoutGlobalScope('company')->where('company_id', $context['company']->getKey())->where('name', 'Vendedor')->firstOrFail();
    $worker = app(CreateWorker::class)->handle($context['membership'], $seller, [
        'name' => 'Fernanda', 'email' => 'fernanda.reports@example.com', 'password' => 'Temporary-Password-123!',
    ]);
    $worker->user->update(['must_change_password' => false]);

    $this->actingAs($worker->user)->withSession(['current_membership_id' => $worker->getKey()])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee(route('reports.index'), false);
    $this->actingAs($worker->user)->withSession(['current_membership_id' => $worker->getKey()])
        ->get(route('reports.index'))
        ->assertForbidden();

    $this->actingAs($context['owner'])->withSession(['current_membership_id' => $context['membership']->getKey()])
        ->get(route('reports.index'))
        ->assertOk()
        ->assertSee('Ventas')
        ->assertSee('Inventario')
        ->assertSee('Gastos')
        ->assertSee('Ganancias');
});

test('report access configuration cannot cross company boundaries', function () {
    $first = expenseContext('Empresa de la administradora');
    $second = expenseContext('Empresa del trabajador');
    $seller = Role::query()->withoutGlobalScope('company')->where('company_id', $second['company']->getKey())->where('name', 'Vendedor')->firstOrFail();
    $foreignWorker = app(CreateWorker::class)->handle($second['membership'], $seller, [
        'name' => 'Gabriel', 'email' => 'gabriel.reports@example.com', 'password' => 'Temporary-Password-123!',
    ]);

    expect(fn () => app(ConfigureWorkerAccess::class)->handle(
        $first['membership'], $foreignWorker, 'Personalizado', ['reports.sales.view'],
    ))->toThrow(DomainException::class, 'empresa actual');

    expect($foreignWorker->refresh()->roles()->sole()->permissions()->where('code', 'reports.sales.view')->exists())->toBeFalse();
});
