<?php

namespace App\Support\Authorization;

use DomainException;

class WorkerPermissionCatalog
{
    /**
     * @return array<string, array{label: string, permissions: list<string>}>
     */
    public function capabilities(): array
    {
        return collect($this->groups())->flatMap(fn (array $group): array => $group['capabilities'])->all();
    }

    /**
     * @return array<string, array{label: string, capabilities: array<string, array{label: string, permissions: list<string>}>}>
     */
    public function groups(): array
    {
        return [
            'sales' => ['label' => 'VENTAS', 'capabilities' => [
                'view_sales' => $this->capability('Ver ventas', ['sales.view']),
                'register_sales' => $this->capability('Registrar ventas', ['sales.view', 'sales.create']),
                'void_sales' => $this->capability('Anular ventas', ['sales.view', 'sales.void']),
                'view_sale_balances' => $this->capability('Ver saldos pendientes', ['sales.view', 'sales.balances.view']),
                'register_sale_payments' => $this->capability('Registrar pagos', ['sales.view', 'sales.balances.view', 'sales.payments.create']),
                'manage_sale_extras' => $this->capability('Gestionar extras', ['sales.view', 'sales.extras.manage']),
                'change_sale_extra_prices' => $this->capability('Modificar precio de extras', ['sales.view', 'sales.extras.price.update']),
                'view_sales_income' => $this->capability('Ver ingresos de ventas', ['finance.sales_income.view']),
            ]],
            'catalog' => ['label' => 'RAMOS E INSUMOS', 'capabilities' => [
                'view_catalog' => $this->capability('Ver insumos y ramos', ['catalog.view']),
                'create_supplies' => $this->capability('Crear insumos', ['catalog.view', 'catalog.create']),
                'update_supplies' => $this->capability('Editar insumos', ['catalog.view', 'catalog.update']),
                'create_bouquets' => $this->capability('Crear ramos', ['catalog.view', 'catalog.create']),
                'update_bouquets' => $this->capability('Editar ramos', ['catalog.view', 'catalog.update']),
                'change_bouquet_prices' => $this->capability('Cambiar precio de ramos', ['catalog.view', 'catalog.update', 'catalog.bouquets.price.update']),
                'change_recipes' => $this->capability('Cambiar recetas', ['catalog.view', 'catalog.recipes.manage']),
            ]],
            'inventory' => ['label' => 'INVENTARIO', 'capabilities' => [
                'view_inventory' => $this->capability('Ver existencias', ['inventory.view']),
                'register_inbound' => $this->capability('Registrar compras', ['inventory.view', 'inventory.adjust']),
                'register_outbound' => $this->capability('Registrar salidas', ['inventory.view', 'inventory.adjust']),
                'correct_inventory' => $this->capability('Ajustar stock', ['inventory.view', 'inventory.adjust']),
                'register_waste' => $this->capability('Registrar mermas', ['inventory.view', 'inventory.waste']),
                'reverse_inventory' => $this->capability('Revertir movimientos', ['inventory.view', 'inventory.reverse']),
            ]],
            'finance' => ['label' => 'FINANZAS', 'capabilities' => [
                'view_financial_income' => $this->capability('Ver ingresos por ventas', ['finance.sales_income.view']),
                'view_expenses' => $this->capability('Ver gastos', ['finance.expenses.view']),
                'register_expenses' => $this->capability('Registrar gastos', ['finance.expenses.view', 'finance.expenses.create']),
                'update_expenses' => $this->capability('Editar gastos', ['finance.expenses.view', 'finance.expenses.update']),
                'cancel_expenses' => $this->capability('Anular gastos', ['finance.expenses.view', 'finance.expenses.cancel']),
                'view_costs' => $this->capability('Ver costos', ['sales.costs.view']),
                'view_profits' => $this->capability('Ver ganancias / resultado estimado', ['sales.profits.view']),
            ]],
            'reports' => ['label' => 'REPORTES', 'capabilities' => [
                'view_sales_reports' => $this->capability('Ver reportes de ventas', ['reports.sales.view']),
                'view_inventory_reports' => $this->capability('Ver reportes de inventario', ['reports.inventory.view']),
                'view_expense_reports' => $this->capability('Ver reportes de gastos', ['reports.expenses.view']),
                'view_financial_reports' => $this->capability('Ver reportes financieros', ['reports.financial.view']),
            ]],
            'users' => ['label' => 'USUARIOS', 'capabilities' => [
                'view_workers' => $this->capability('Ver trabajadores', ['core.users.view']),
                'create_workers' => $this->capability('Crear trabajadores', ['core.users.view', 'core.users.create']),
                'update_workers' => $this->capability('Editar trabajadores', ['core.users.view', 'core.users.update']),
                'suspend_workers' => $this->capability('Suspender trabajadores', ['core.users.view', 'core.users.suspend']),
                'change_worker_access' => $this->capability('Cambiar permisos', ['core.users.view', 'core.users.update', 'core.users.assign_roles']),
            ]],
            'configuration' => ['label' => 'CONFIGURACIÓN', 'capabilities' => [
                'manage_branches' => $this->capability('Administrar sucursales', ['core.branches.view', 'core.branches.manage']),
                'manage_warehouses' => $this->capability('Administrar almacenes', ['core.warehouses.view', 'core.warehouses.manage']),
                'manage_categories' => $this->capability('Administrar categorías', ['catalog.view', 'catalog.create', 'catalog.update', 'catalog.deactivate']),
                'manage_units' => $this->capability('Administrar unidades', ['catalog.view', 'catalog.create', 'catalog.update', 'catalog.deactivate']),
            ]],
        ];
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->capabilities());
    }

    /**
     * @param  list<string>  $selectedCapabilities
     * @return list<string>
     */
    public function permissionCodes(array $selectedCapabilities): array
    {
        $capabilities = $this->capabilities();
        $unknown = array_diff($selectedCapabilities, array_keys($capabilities));

        if ($unknown !== []) {
            throw new DomainException('La selección contiene accesos no reconocidos.');
        }

        $permissionCodes = collect($selectedCapabilities)
            ->flatMap(fn (string $key): array => $capabilities[$key]['permissions'])
            ->unique()->values()->all();

        return array_values($permissionCodes);
    }

    /**
     * @param  iterable<string>  $permissionCodes
     * @return list<string>
     */
    public function selectedCapabilities(iterable $permissionCodes): array
    {
        $assigned = collect($permissionCodes);

        $selectedCapabilities = collect($this->capabilities())
            ->filter(fn (array $capability): bool => collect($capability['permissions'])->every(
                fn (string $code): bool => $assigned->containsStrict($code),
            ))
            ->keys()->values()->all();

        return array_values($selectedCapabilities);
    }

    /**
     * @param  list<string>  $permissions
     * @return array{label: string, permissions: list<string>}
     */
    private function capability(string $label, array $permissions): array
    {
        return compact('label', 'permissions');
    }
}
