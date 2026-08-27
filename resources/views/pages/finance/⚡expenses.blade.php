<?php

use App\Actions\Expenses\CancelExpense;
use App\Actions\Expenses\CreateExpense;
use App\Actions\Expenses\DeactivateExpenseCategory;
use App\Actions\Expenses\SaveExpenseCategory;
use App\Enums\ExpenseStatus;
use App\Enums\ExpenseReceiptType;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Membership;
use App\Models\PaymentMethod;
use App\Support\Tenancy\CurrentCompany;
use Carbon\Carbon;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Gastos')] class extends Component
{
    use WithPagination;

    public string $search = '';
    public string $dateFrom = '';
    public string $dateTo = '';
    public ?int $categoryId = null;
    public ?int $branchId = null;
    public ?int $paymentMethodId = null;
    public ?int $filterPaymentMethodId = null;
    public ?int $filterMembershipId = null;
    public string $filterStatus = '';
    public string $amountBase = '';
    public string $concept = '';
    public string $reference = '';
    public string $notes = '';
    public string $receiptType = 'without_invoice';
    public string $invoiceNumber = '';
    public string $occurredOn = '';
    public string $categoryName = '';
    public string $categoryDescription = '';
    public ?int $editingCategoryId = null;
    public ?int $expenseToCancel = null;
    public string $cancellationReason = '';

    public function mount(): void
    {
        $timezone = app(CurrentCompany::class)->company()->timezone;
        $this->occurredOn = now($timezone)->toDateString();
        $this->dateFrom = now($timezone)->startOfMonth()->toDateString();
        $this->dateTo = now($timezone)->toDateString();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'dateFrom', 'dateTo', 'categoryId', 'filterPaymentMethodId', 'filterMembershipId', 'filterStatus'], true)) {
            $this->resetPage();
        }
    }

    public function openCreate(): void
    {
        Gate::authorize('create', Expense::class);
        $this->reset(['categoryId', 'branchId', 'paymentMethodId', 'amountBase', 'concept', 'reference', 'notes', 'invoiceNumber']);
        $this->receiptType = ExpenseReceiptType::WithoutInvoice->value;
        Flux::modal('expense-form')->show();
    }

    public function saveExpense(): void
    {
        Gate::authorize('create', Expense::class);
        $company = app(CurrentCompany::class)->company();
        $data = $this->validate([
            'categoryId' => ['required', Rule::exists('expense_categories', 'id')->where('company_id', $company->getKey())->where('is_active', true)],
            'branchId' => ['nullable', Rule::exists('branches', 'id')->where('company_id', $company->getKey())->where('is_active', true)],
            'paymentMethodId' => ['nullable', Rule::exists('payment_methods', 'id')->where('company_id', $company->getKey())->where('is_active', true)],
            'amountBase' => ['required', 'numeric', 'gt:0'],
            'concept' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'occurredOn' => ['required', 'date'],
            'receiptType' => ['required', Rule::enum(ExpenseReceiptType::class)],
            'invoiceNumber' => ['nullable', 'string', 'max:100', Rule::requiredIf($this->receiptType === ExpenseReceiptType::WithInvoice->value)],
        ]);

        app(CreateExpense::class)->handle(
            app(CurrentCompany::class)->membership(),
            ExpenseCategory::query()->findOrFail($data['categoryId']),
            $data['amountBase'],
            $data['concept'],
            $data['branchId'] ? Branch::query()->findOrFail($data['branchId']) : null,
            $data['paymentMethodId'] ? PaymentMethod::query()->findOrFail($data['paymentMethodId']) : null,
            $data['reference'] ?: null,
            $data['notes'] ?: null,
            Carbon::parse($data['occurredOn'], $company->timezone)->startOfDay()->utc(),
            ExpenseReceiptType::from($data['receiptType']),
            $data['invoiceNumber'] ?: null,
        );

        Flux::modal('expense-form')->close();
        Flux::toast('Gasto registrado correctamente.', variant: 'success');
    }

    public function saveCategory(): void
    {
        Gate::authorize('create', ExpenseCategory::class);
        $companyId = app(CurrentCompany::class)->id();
        $data = $this->validate(['categoryName' => ['required', 'string', 'max:255', Rule::unique('expense_categories', 'name')->where('company_id', $companyId)->ignore($this->editingCategoryId)], 'categoryDescription' => ['nullable', 'string']]);
        $category = $this->editingCategoryId ? ExpenseCategory::query()->findOrFail($this->editingCategoryId) : null;
        $category ? Gate::authorize('update', $category) : Gate::authorize('create', ExpenseCategory::class);
        app(SaveExpenseCategory::class)->handle(app(CurrentCompany::class)->membership(), $data['categoryName'], $data['categoryDescription'] ?: null, $category);
        $this->reset(['categoryName', 'categoryDescription', 'editingCategoryId']);
        Flux::modal('category-form')->close();
        Flux::toast($category ? 'Categoría actualizada.' : 'Categoría creada.', variant: 'success');
    }

    public function editCategory(int $categoryId): void
    {
        $category = ExpenseCategory::query()->findOrFail($categoryId);
        Gate::authorize('update', $category);
        $this->editingCategoryId = $category->getKey();
        $this->categoryName = $category->name;
        $this->categoryDescription = $category->description ?? '';
    }

    public function deactivateCategory(int $categoryId): void
    {
        $category = ExpenseCategory::query()->findOrFail($categoryId);
        Gate::authorize('deactivate', $category);
        app(DeactivateExpenseCategory::class)->handle(app(CurrentCompany::class)->membership(), $category);
        Flux::toast('Categoría desactivada. Los gastos históricos no cambiaron.', variant: 'success');
    }

    public function openCancel(int $expenseId): void
    {
        $expense = Expense::query()->findOrFail($expenseId);
        Gate::authorize('cancel', $expense);
        $this->expenseToCancel = $expense->getKey();
        $this->cancellationReason = '';
        Flux::modal('cancel-expense')->show();
    }

    public function cancelExpense(): void
    {
        $this->validate(['cancellationReason' => ['required', 'string', 'max:1000']]);
        $expense = Expense::query()->findOrFail($this->expenseToCancel);
        Gate::authorize('cancel', $expense);
        app(CancelExpense::class)->handle(app(CurrentCompany::class)->membership(), $expense, $this->cancellationReason);
        Flux::modal('cancel-expense')->close();
        Flux::toast('Gasto anulado; el registro se conserva.', variant: 'success');
    }

    #[Computed]
    public function expenses(): LengthAwarePaginator
    {
        $timezone = app(CurrentCompany::class)->company()->timezone;

        return Expense::query()->with('responsible.user:id,name')
            ->when($this->search, fn ($query) => $query->where(fn ($nested) => $nested->where('number', 'like', '%'.$this->search.'%')->orWhere('concept', 'like', '%'.$this->search.'%')->orWhere('reference', 'like', '%'.$this->search.'%')))
            ->when($this->categoryId, fn ($query) => $query->where('expense_category_id', $this->categoryId))
            ->when($this->filterPaymentMethodId, fn ($query) => $query->where('payment_method_id', $this->filterPaymentMethodId))
            ->when($this->filterMembershipId, fn ($query) => $query->where('membership_id', $this->filterMembershipId))
            ->when($this->filterStatus, fn ($query) => $query->where('status', $this->filterStatus))
            ->when($this->dateFrom, fn ($query) => $query->where('occurred_at', '>=', Carbon::parse($this->dateFrom, $timezone)->startOfDay()->utc()))
            ->when($this->dateTo, fn ($query) => $query->where('occurred_at', '<=', Carbon::parse($this->dateTo, $timezone)->endOfDay()->utc()))
            ->latest('occurred_at')->paginate(15);
    }

    #[Computed] public function categories(): Collection { return ExpenseCategory::query()->orderByDesc('is_active')->orderBy('name')->get(); }
    #[Computed] public function branches(): Collection { return Branch::query()->where('is_active', true)->orderBy('name')->get(); }
    #[Computed] public function paymentMethods(): Collection { return PaymentMethod::query()->where('is_active', true)->orderBy('name')->get(); }
    #[Computed] public function memberships(): Collection { return Membership::query()->with('user:id,name')->orderBy('id')->get(); }

    public function money(string $amount): string
    {
        return Number::format((float) $amount, precision: app(CurrentCompany::class)->company()->baseCurrency->decimal_places, locale: 'es');
    }
}; ?>

@php($company = app(CurrentCompany::class)->company())
<div class="flex w-full flex-col gap-6">
    <div class="flex flex-col justify-between gap-4 md:flex-row md:items-center">
        <div><flux:heading size="xl">Gastos</flux:heading><flux:text>Registra egresos operativos sin mezclarlos con compras de inventario.</flux:text></div>
        <div class="flex gap-2">@can('create', App\Models\ExpenseCategory::class)<flux:button wire:click="$dispatch('modal-show', { name: 'category-form' })">Categorías</flux:button>@endcan @can('create', App\Models\Expense::class)<flux:button variant="primary" icon="plus" wire:click="openCreate">Nuevo gasto</flux:button>@endcan</div>
    </div>
    <flux:callout icon="information-circle" heading="Inventario y gastos son conceptos distintos">Una compra que aumenta existencias se registra como entrada de inventario. Aquí van alquiler, luz, transporte, publicidad y otros gastos operativos.</flux:callout>
    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4"><flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Concepto o referencia" /><flux:input wire:model.live="dateFrom" type="date" label="Desde" /><flux:input wire:model.live="dateTo" type="date" label="Hasta" /><flux:select wire:model.live="categoryId" label="Categoría"><flux:select.option value="">Todas</flux:select.option>@foreach($this->categories as $category)<flux:select.option :value="$category->id">{{ $category->name }}</flux:select.option>@endforeach</flux:select><flux:select wire:model.live="filterPaymentMethodId" label="Método de pago"><flux:select.option value="">Todos</flux:select.option>@foreach($this->paymentMethods as $method)<flux:select.option :value="$method->id">{{ $method->name }}</flux:select.option>@endforeach</flux:select><flux:select wire:model.live="filterStatus" label="Estado"><flux:select.option value="">Todos</flux:select.option><flux:select.option value="confirmed">Confirmado</flux:select.option><flux:select.option value="cancelled">Anulado</flux:select.option></flux:select><flux:select wire:model.live="filterMembershipId" label="Responsable"><flux:select.option value="">Todos</flux:select.option>@foreach($this->memberships as $membership)<flux:select.option :value="$membership->id">{{ $membership->user->name }}</flux:select.option>@endforeach</flux:select></div>
    <flux:card class="overflow-x-auto"><flux:table><flux:table.columns><flux:table.column>Fecha</flux:table.column><flux:table.column>Número</flux:table.column><flux:table.column>Concepto</flux:table.column><flux:table.column>Categoría</flux:table.column><flux:table.column>Forma de pago</flux:table.column><flux:table.column>Responsable</flux:table.column><flux:table.column align="end">Importe</flux:table.column><flux:table.column></flux:table.column></flux:table.columns><flux:table.rows>
        @forelse($this->expenses as $expense)<flux:table.row wire:key="expense-{{ $expense->id }}" class="{{ $expense->status === ExpenseStatus::Cancelled ? 'opacity-60' : '' }}"><flux:table.cell>{{ $expense->occurred_at->timezone($company->timezone)->format('d/m/Y') }}</flux:table.cell><flux:table.cell>{{ $expense->number }} @if($expense->status === ExpenseStatus::Cancelled)<flux:badge color="red">Anulado</flux:badge>@endif</flux:table.cell><flux:table.cell><div class="font-medium">{{ $expense->concept }}</div><flux:text size="sm">{{ $expense->reference }}</flux:text><flux:text size="sm">{{ $expense->receipt_type === ExpenseReceiptType::WithInvoice ? 'Con factura · Nº '.$expense->invoice_number : 'Sin factura' }}</flux:text></flux:table.cell><flux:table.cell>{{ $expense->category_name }}</flux:table.cell><flux:table.cell>{{ $expense->payment_method_name ?? 'No indicada' }}</flux:table.cell><flux:table.cell>{{ $expense->responsible->user->name }}</flux:table.cell><flux:table.cell align="end">{{ $company->baseCurrency->symbol }} {{ $this->money($expense->amount_base) }}</flux:table.cell><flux:table.cell>@if($expense->status === ExpenseStatus::Confirmed && auth()->user()->can('cancel', $expense))<flux:button size="sm" variant="ghost" wire:click="openCancel({{ $expense->id }})">Anular</flux:button>@endif</flux:table.cell></flux:table.row>
        @empty<flux:table.row><flux:table.cell colspan="8" class="py-10 text-center">No hay gastos para este período.</flux:table.cell></flux:table.row>@endforelse
    </flux:table.rows></flux:table></flux:card>{{ $this->expenses->links() }}
    <flux:modal name="expense-form" class="max-w-2xl"><form wire:submit="saveExpense" class="space-y-5"><flux:heading size="lg">Registrar gasto</flux:heading><div class="grid gap-4 md:grid-cols-2"><flux:input wire:model="occurredOn" type="date" label="Fecha" required /><flux:input wire:model="amountBase" type="number" min="0.0001" step="0.0001" label="Importe ({{ $company->baseCurrency->code }})" required /><flux:select wire:model="categoryId" label="Categoría" required><flux:select.option value="">Seleccionar</flux:select.option>@foreach($this->categories->where('is_active', true) as $category)<flux:select.option :value="$category->id">{{ $category->name }}</flux:select.option>@endforeach</flux:select><flux:select wire:model="paymentMethodId" label="Forma de pago"><flux:select.option value="">No indicada</flux:select.option>@foreach($this->paymentMethods as $method)<flux:select.option :value="$method->id">{{ $method->name }}</flux:select.option>@endforeach</flux:select><flux:select wire:model="branchId" label="Sucursal"><flux:select.option value="">General</flux:select.option>@foreach($this->branches as $branch)<flux:select.option :value="$branch->id">{{ $branch->name }}</flux:select.option>@endforeach</flux:select><flux:input wire:model="reference" label="Referencia" /><flux:select wire:model.live="receiptType" label="Tipo de comprobante" required>@foreach(ExpenseReceiptType::cases() as $type)<flux:select.option :value="$type->value">{{ $type->label() }}</flux:select.option>@endforeach</flux:select>@if($receiptType === ExpenseReceiptType::WithInvoice->value)<flux:input wire:model="invoiceNumber" label="Número de factura" required />@endif</div><flux:callout icon="information-circle">Las compras de insumos se registran en Inventario para evitar duplicarlas como gasto.</flux:callout><flux:input wire:model="concept" label="Concepto" required /><flux:textarea wire:model="notes" label="Notas" /><div class="flex justify-end gap-3"><flux:modal.close><flux:button variant="ghost">Cancelar</flux:button></flux:modal.close><flux:button type="submit" variant="primary">Registrar gasto</flux:button></div></form></flux:modal>
    <flux:modal name="category-form" class="max-w-xl"><form wire:submit="saveCategory" class="space-y-5"><flux:heading size="lg">Categorías de gastos</flux:heading><div class="max-h-64 space-y-2 overflow-y-auto">@foreach($this->categories as $category)<div class="flex items-center justify-between rounded-lg border p-3"><span>{{ $category->name }}</span><div class="flex gap-1">@if($category->is_active)<flux:button type="button" size="sm" variant="ghost" wire:click="editCategory({{ $category->id }})">Editar</flux:button><flux:button type="button" size="sm" variant="ghost" wire:click="deactivateCategory({{ $category->id }})" wire:confirm="¿Desactivar esta categoría?">Desactivar</flux:button>@else<flux:badge>Inactiva</flux:badge>@endif</div></div>@endforeach</div><flux:separator /><flux:input wire:model="categoryName" :label="$editingCategoryId ? 'Editar categoría' : 'Nueva categoría'" required /><flux:textarea wire:model="categoryDescription" label="Descripción" /><div class="flex justify-end"><flux:button type="submit" variant="primary">{{ $editingCategoryId ? 'Guardar cambios' : 'Agregar categoría' }}</flux:button></div></form></flux:modal>
    <flux:modal name="cancel-expense" class="max-w-lg"><form wire:submit="cancelExpense" class="space-y-5"><flux:heading size="lg">Anular gasto</flux:heading><flux:text>El registro se conservará para auditoría y dejará de sumar en los resultados.</flux:text><flux:textarea wire:model="cancellationReason" label="Motivo" required /><div class="flex justify-end gap-3"><flux:modal.close><flux:button variant="ghost">Volver</flux:button></flux:modal.close><flux:button type="submit" variant="danger">Confirmar anulación</flux:button></div></form></flux:modal>
</div>
