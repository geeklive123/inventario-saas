<?php

use App\Actions\Companies\CreateCompany;
use App\Models\Currency;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $this->seed(DatabaseSeeder::class);
    $user = User::factory()->create();
    $currency = Currency::query()->where('code', 'BOB')->firstOrFail();
    app(CreateCompany::class)->handle($user, [
        'name' => 'Dashboard Company',
        'base_currency_id' => $currency->getKey(),
        'timezone' => 'America/La_Paz',
        'locale' => 'es',
    ]);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});
