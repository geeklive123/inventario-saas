<?php

use App\Http\Controllers\ReportExportController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login')->name('home');

Route::middleware('auth')->group(function () {
    Route::livewire('cambiar-contrasena-temporal', 'pages::auth.change-temporary-password')
        ->name('password.temporary.edit');
});

Route::middleware(['auth', 'password.changed'])->group(function () {
    Route::middleware('company')->group(function () {
        Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');

        Route::middleware(['module:catalog', 'permission:catalog.view'])->prefix('catalogo')->name('catalog.')->group(function () {
            Route::livewire('productos', 'pages::catalog.products')->name('products');
            Route::livewire('categorias', 'pages::catalog.categories')->name('categories');
            Route::livewire('unidades', 'pages::catalog.units')->name('units');
            Route::livewire('recetas', 'pages::catalog.recipes')->name('recipes');
        });

        Route::middleware(['module:catalog', 'permission:catalog.view'])->group(function () {
            Route::livewire('insumos', 'pages::supplies')->name('supplies');
            Route::livewire('ramos', 'pages::bouquets')->name('bouquets');
        });

        Route::middleware(['module:inventory', 'permission:inventory.view'])->prefix('inventario')->name('inventory.')->group(function () {
            Route::livewire('existencias', 'pages::inventory.stock')->name('stock');
            Route::livewire('movimientos', 'pages::inventory.movements')->name('movements');
            Route::livewire('mermas', 'pages::waste')->name('waste');
            Route::livewire('regularizaciones', 'pages::inventory.sale-regularizations')
                ->middleware('permission:inventory.regularize_sales')->name('regularizations');
            Route::livewire('regularizaciones/{pendingId}', 'pages::inventory.regularize-sale')
                ->whereNumber('pendingId')
                ->middleware('permission:inventory.regularize_sales')->name('regularizations.show');
        });

        Route::middleware(['module:sales', 'permission:sales.view'])->prefix('ventas')->name('sales.')->group(function () {
            Route::livewire('/', 'pages::sales.index')->name('index');
            Route::livewire('{saleId}', 'pages::sales.show')->whereNumber('saleId')->name('show');
        });

        Route::middleware(['module:finance', 'permission:finance.expenses.view'])
            ->prefix('finanzas')->name('finance.')->group(function () {
                Route::livewire('gastos', 'pages::finance.expenses')->name('expenses');
            });

        Route::livewire('reportes', 'pages::reports.index')
            ->middleware('permission.any:reports.sales.view,reports.inventory.view,reports.expenses.view,reports.financial.view')
            ->name('reports.index');
        Route::get('reportes/exportar/{format}', ReportExportController::class)
            ->whereIn('format', ['pdf', 'xlsx'])
            ->middleware('permission.any:reports.sales.view,reports.inventory.view,reports.expenses.view,reports.financial.view')
            ->name('reports.export');

        Route::livewire('usuarios', 'pages::users')
            ->middleware('permission:core.users.view')
            ->name('users.index');

        Route::prefix('configuracion')->name('configuration.')->group(function () {
            Route::livewire('ventas', 'pages::settings.sales')->name('sales');
            Route::livewire('sucursales', 'pages::settings.branches')
                ->middleware('permission:core.branches.view')->name('branches');
            Route::livewire('almacenes', 'pages::settings.warehouses')
                ->middleware('permission:core.warehouses.view')->name('warehouses');
        });
    });
});

require __DIR__.'/settings.php';
