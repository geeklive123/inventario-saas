<?php

use App\Actions\Companies\CreateCompany;
use App\Actions\Modules\SetModuleStatus;
use App\Enums\CompanyModuleStatus;
use App\Enums\ModuleCode;
use App\Models\Company;
use App\Models\Currency;
use App\Models\Membership;
use App\Models\Module;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/** @return array{owner: User, company: Company, membership: Membership, finance: Module} */
function expenseContext(string $name = 'Florería Gastos'): array
{
    $owner = User::factory()->create();
    $company = app(CreateCompany::class)->handle($owner, [
        'name' => $name,
        'base_currency_id' => Currency::query()->where('code', 'BOB')->value('id'),
        'timezone' => 'America/La_Paz',
        'locale' => 'es',
    ]);
    $membership = Membership::query()->withoutGlobalScope('company')
        ->where('company_id', $company->getKey())->where('user_id', $owner->getKey())->firstOrFail();
    $finance = Module::query()->where('code', ModuleCode::Finance)->firstOrFail();
    app(SetModuleStatus::class)->handle(
        $membership, $finance, CompanyModuleStatus::Enabled,
    );

    return compact('owner', 'company', 'membership', 'finance');
}
