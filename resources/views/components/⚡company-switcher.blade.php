<?php

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    #[Computed]
    public function memberships(): Collection
    {
        return Membership::query()
            ->withoutGlobalScope('company')
            ->with('company:id,name')
            ->where('user_id', Auth::id())
            ->where('status', MembershipStatus::Active)
            ->orderByDesc('is_owner')
            ->get();
    }

    public function selectCompany(int $membershipId): void
    {
        $membership = Membership::query()
            ->withoutGlobalScope('company')
            ->whereKey($membershipId)
            ->where('user_id', Auth::id())
            ->where('status', MembershipStatus::Active)
            ->first();

        abort_if($membership === null, 404);

        session()->put('current_membership_id', $membership->getKey());
        session()->forget(['current_warehouse_id']);

        $this->redirectRoute('dashboard', navigate: true);
    }
}; ?>

@php($current = app(CurrentCompany::class))

<div>
@if ($this->memberships->count() > 1)
<div class="px-2 pb-3">
    <flux:dropdown position="bottom" align="start" class="w-full">
        <flux:button variant="subtle" icon="building-office-2" icon:trailing="chevron-up-down" class="w-full justify-between">
            <span class="truncate">{{ $current->isResolved() ? $current->company()->name : 'Seleccionar empresa' }}</span>
        </flux:button>

        <flux:menu class="min-w-64">
            <flux:menu.radio.group>
                @foreach ($this->memberships as $membership)
                    <flux:menu.item
                        wire:key="company-{{ $membership->id }}"
                        wire:click="selectCompany({{ $membership->id }})"
                        icon="building-office"
                    >
                        <span class="flex w-full items-center justify-between gap-3">
                            <span class="truncate">{{ $membership->company->name }}</span>
                            @if ($membership->is_owner)
                                <flux:badge size="sm" color="amber">Propietario</flux:badge>
                            @endif
                        </span>
                    </flux:menu.item>
                @endforeach
            </flux:menu.radio.group>
        </flux:menu>
    </flux:dropdown>
</div>
@endif
</div>
