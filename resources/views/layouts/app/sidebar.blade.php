<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>@include('partials.head')</head>
<body class="min-h-screen bg-white dark:bg-zinc-800">
@php($currentCompany = app(App\Support\Tenancy\CurrentCompany::class))
<flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
    <flux:sidebar.header><x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate /><flux:sidebar.collapse class="lg:hidden" /></flux:sidebar.header>
    <livewire:company-switcher />
    <flux:sidebar.nav>
        <flux:sidebar.group heading="Principal" class="grid">
            <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>Inicio</flux:sidebar.item>
        </flux:sidebar.group>
        @can('viewAny', App\Models\Sale::class)
            <flux:sidebar.group heading="Operación" class="grid">
                <flux:sidebar.item icon="shopping-bag" :href="route('sales.index')" :current="request()->routeIs('sales.*')" wire:navigate>Ventas</flux:sidebar.item>
            </flux:sidebar.group>
        @endcan
        @can('viewAny', App\Models\Expense::class)
            <flux:sidebar.group heading="Finanzas" class="grid">
                <flux:sidebar.item icon="banknotes" :href="route('finance.expenses')" :current="request()->routeIs('finance.*')" wire:navigate>Gastos</flux:sidebar.item>
            </flux:sidebar.group>
        @endcan
        @if(app(App\Support\Authorization\ReportAccess::class)->canViewAnyCurrent(auth()->user()))
            <flux:sidebar.group heading="Análisis" class="grid">
                <flux:sidebar.item icon="chart-bar-square" :href="route('reports.index')" :current="request()->routeIs('reports.*')" wire:navigate>Reportes</flux:sidebar.item>
            </flux:sidebar.group>
        @endif
        @can('viewAny', App\Models\Product::class)
            <flux:sidebar.group heading="Productos" class="grid">
                <flux:sidebar.item icon="archive-box" :href="route('supplies')" :current="request()->routeIs('supplies')" wire:navigate>Insumos</flux:sidebar.item>
                <flux:sidebar.item icon="gift" :href="route('bouquets')" :current="request()->routeIs('bouquets') || request()->routeIs('catalog.recipes')" wire:navigate>Ramos</flux:sidebar.item>
            </flux:sidebar.group>
        @endcan
        @can('viewAny', App\Models\StockMovement::class)
            <flux:sidebar.group heading="Inventario" class="grid">
                <flux:sidebar.item icon="building-storefront" :href="route('inventory.stock')" :current="request()->routeIs('inventory.stock')" wire:navigate>Inventario</flux:sidebar.item>
                <flux:sidebar.item icon="arrows-right-left" :href="route('inventory.movements')" :current="request()->routeIs('inventory.movements')" wire:navigate>Movimientos</flux:sidebar.item>
                <flux:sidebar.item icon="trash" :href="route('inventory.waste')" :current="request()->routeIs('inventory.waste')" wire:navigate>Mermas</flux:sidebar.item>
                @can('viewAny', App\Models\SaleInventoryPending::class)
                    <flux:sidebar.item icon="exclamation-triangle" :href="route('inventory.regularizations')" :current="request()->routeIs('inventory.regularizations*')" wire:navigate>Pendientes de regularización</flux:sidebar.item>
                @endcan
            </flux:sidebar.group>
        @endcan
        @can('viewAny', App\Models\Membership::class)
            <flux:sidebar.group heading="Equipo" class="grid"><flux:sidebar.item icon="users" :href="route('users.index')" :current="request()->routeIs('users.index')" wire:navigate>Usuarios</flux:sidebar.item></flux:sidebar.group>
        @endcan
        @if($currentCompany->isResolved() && (auth()->user()->can('manageSalesSettings', $currentCompany->company()) || auth()->user()->can('viewAny', App\Models\Branch::class) || auth()->user()->can('viewAny', App\Models\Warehouse::class)))
            <flux:sidebar.group heading="Configuración" class="grid">
                <flux:sidebar.item icon="tag" :href="route('catalog.categories')" :current="request()->routeIs('catalog.categories')" wire:navigate>Categorías</flux:sidebar.item>
                <flux:sidebar.item icon="scale" :href="route('catalog.units')" :current="request()->routeIs('catalog.units')" wire:navigate>Unidades</flux:sidebar.item>
                @can('manageSalesSettings', $currentCompany->company())<flux:sidebar.item icon="shopping-bag" :href="route('configuration.sales')" :current="request()->routeIs('configuration.sales')" wire:navigate>Ventas</flux:sidebar.item>@endcan
                @can('viewAny', App\Models\Branch::class)<flux:sidebar.item icon="building-office" :href="route('configuration.branches')" :current="request()->routeIs('configuration.branches')" wire:navigate>Sucursales</flux:sidebar.item>@endcan
                @can('viewAny', App\Models\Warehouse::class)<flux:sidebar.item icon="building-storefront" :href="route('configuration.warehouses')" :current="request()->routeIs('configuration.warehouses')" wire:navigate>Almacenes</flux:sidebar.item>@endcan
            </flux:sidebar.group>
        @endif
    </flux:sidebar.nav>
    <flux:spacer /><x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
</flux:sidebar>
<flux:header class="lg:hidden"><flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" /><flux:spacer /><flux:dropdown position="top" align="end"><flux:profile :initials="auth()->user()->initials()" icon-trailing="chevron-down" /><flux:menu><div class="p-3"><flux:heading>{{ auth()->user()->name }}</flux:heading><flux:text>{{ auth()->user()->email }}</flux:text></div><flux:menu.separator /><flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>Mi cuenta</flux:menu.item><flux:menu.separator /><form method="POST" action="{{ route('logout') }}">@csrf<flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle">Cerrar sesión</flux:menu.item></form></flux:menu></flux:dropdown></flux:header>
{{ $slot }}
@persist('toast')<flux:toast.group><flux:toast /></flux:toast.group>@endpersist
@fluxScripts
</body></html>
