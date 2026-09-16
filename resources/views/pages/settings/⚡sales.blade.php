<?php

use App\Actions\Companies\UpdateSalesSettings;
use App\Enums\ModuleCode;
use App\Models\CompanyModule;
use App\Support\Tenancy\CurrentCompany;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Configuración de ventas')] class extends Component
{
    public bool $allowBackdatedSales = false;

    public function mount(): void
    {
        $company = app(CurrentCompany::class)->company();
        Gate::authorize('manageSalesSettings', $company);
        $this->allowBackdatedSales = CompanyModule::query()
            ->where('company_id', $company->getKey())
            ->whereHas('module', fn ($query) => $query->where('code', ModuleCode::Sales))
            ->firstOrFail()
            ->allowsBackdatedSales();
    }

    public function save(): void
    {
        $currentCompany = app(CurrentCompany::class);
        Gate::authorize('manageSalesSettings', $currentCompany->company());
        $this->validate(['allowBackdatedSales' => ['boolean']]);
        app(UpdateSalesSettings::class)->handle($currentCompany->membership(), $this->allowBackdatedSales);
        Flux::toast(variant: 'success', text: 'Configuración de ventas actualizada.');
    }
};
?>

<div class="flex w-full max-w-3xl flex-col gap-6">
    <div>
        <flux:heading size="xl">Configuración de ventas</flux:heading>
        <flux:text>Controla las opciones disponibles al registrar ventas.</flux:text>
    </div>

    <form wire:submit="save">
        <flux:card class="space-y-5">
            <div>
                <flux:heading size="lg">Ventas atrasadas</flux:heading>
                <flux:text class="mt-1">Permite registrar ventas realizadas hoy o hasta 2 días calendario atrás.</flux:text>
            </div>
            <flux:switch wire:model="allowBackdatedSales" label="{{ $allowBackdatedSales ? 'Activado' : 'Desactivado' }}" />
            <div class="flex justify-end">
                <flux:button type="submit" variant="primary">Guardar configuración</flux:button>
            </div>
        </flux:card>
    </form>
</div>
