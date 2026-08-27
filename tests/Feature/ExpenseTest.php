<?php

use App\Actions\Expenses\CancelExpense;
use App\Actions\Expenses\CreateExpense;
use App\Actions\Modules\SetModuleStatus;
use App\Enums\CompanyModuleStatus;
use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Support\Tenancy\CurrentCompany;
use Database\Seeders\DatabaseSeeder;
use Livewire\Livewire;

beforeEach(fn () => $this->seed(DatabaseSeeder::class));

test('an owner registers and cancels an immutable audited expense', function () {
    $context = expenseContext();
    $category = ExpenseCategory::query()->withoutGlobalScope('company')->where('company_id', $context['company']->getKey())->firstOrFail();
    $method = PaymentMethod::query()->withoutGlobalScope('company')->where('company_id', $context['company']->getKey())->firstOrFail();

    $expense = app(CreateExpense::class)->handle($context['membership'], $category, '125.50', 'Publicidad de agosto', paymentMethod: $method);

    expect($expense->number)->toBe('G-000001')
        ->and($expense->amount_base)->toBe('125.5000')
        ->and($expense->category_name)->toBe($category->name)
        ->and($expense->status)->toBe(ExpenseStatus::Confirmed);

    expect(fn () => $expense->update(['concept' => 'Alterado']))->toThrow(DomainException::class, 'immutable');

    app(CancelExpense::class)->handle($context['membership'], $expense, 'Registro duplicado');
    expect($expense->refresh()->status)->toBe(ExpenseStatus::Cancelled)
        ->and($expense->cancelled_by_membership_id)->toBe($context['membership']->getKey())
        ->and($expense->cancellation_reason)->toBe('Registro duplicado');
});

test('a disabled finance module blocks new expenses but preserves historical reads', function () {
    $context = expenseContext();
    $category = ExpenseCategory::query()->withoutGlobalScope('company')->where('company_id', $context['company']->getKey())->firstOrFail();
    $expense = app(CreateExpense::class)->handle($context['membership'], $category, 10, 'Transporte');
    app(SetModuleStatus::class)->handle($context['membership'], $context['finance'], CompanyModuleStatus::Disabled);

    expect(fn () => app(CreateExpense::class)->handle($context['membership'], $category, 20, 'Otro'))
        ->toThrow(DomainException::class, 'no puede registrar gastos');

    $this->actingAs($context['owner'])->withSession(['current_membership_id' => $context['membership']->getKey()])
        ->get(route('finance.expenses'))->assertOk()->assertSee($expense->number);
});

test('expense references cannot cross company boundaries', function () {
    $first = expenseContext('Primera');
    $second = expenseContext('Segunda');
    $foreignCategory = ExpenseCategory::query()->withoutGlobalScope('company')->where('company_id', $second['company']->getKey())->firstOrFail();

    expect(fn () => app(CreateExpense::class)->handle($first['membership'], $foreignCategory, 50, 'Intrusión'))
        ->toThrow(DomainException::class, 'pertenecer a la empresa');

    expect(Expense::query()->withoutGlobalScope('company')->count())->toBe(0);
});

test('expense form does not expose the legacy direct or indirect classification', function () {
    $context = expenseContext('Gastos sencillos');
    app(CurrentCompany::class)->set($context['membership']);

    Livewire::actingAs($context['owner'])->test('pages::finance.expenses')
        ->assertSee('Categoría')
        ->assertSee('Método de pago')
        ->assertSee('Tipo de comprobante')
        ->assertDontSee('Tipo de gasto')
        ->assertDontSee('Directo')
        ->assertDontSee('Indirecto')
        ->assertDontSee('Venta relacionada');
});
