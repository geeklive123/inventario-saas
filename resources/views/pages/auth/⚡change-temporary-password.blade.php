<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.auth')] #[Title('Crear nueva contraseña')] class extends Component
{
    public string $password = '';
    public string $passwordConfirmation = '';

    public function mount(): void
    {
        if (! Auth::user()->must_change_password) {
            $this->redirectRoute('dashboard', navigate: true);
        }
    }

    public function save(): void
    {
        $validated = $this->validate([
            'password' => ['required', 'string', Password::defaults(), 'confirmed:passwordConfirmation'],
            'passwordConfirmation' => ['required', 'string'],
        ], messages: [
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ]);

        Auth::user()->update([
            'password' => $validated['password'],
            'must_change_password' => false,
        ]);
        if (request()->hasSession()) {
            request()->session()->regenerate();
        }

        $this->redirectRoute('dashboard', navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header
        title="Crea tu nueva contraseña"
        description="La contraseña entregada por el administrador es temporal. Debes reemplazarla antes de continuar."
    />

    <form wire:submit="save" class="flex flex-col gap-5">
        <flux:input
            wire:model="password"
            type="password"
            label="Nueva contraseña"
            autocomplete="new-password"
            viewable
            required
        />
        <flux:input
            wire:model="passwordConfirmation"
            type="password"
            label="Confirmar nueva contraseña"
            autocomplete="new-password"
            viewable
            required
        />
        <flux:button type="submit" variant="primary" class="w-full" wire:loading.attr="disabled">
            Guardar y continuar
        </flux:button>
    </form>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <flux:button type="submit" variant="ghost" class="w-full">Cerrar sesión</flux:button>
    </form>
</div>
