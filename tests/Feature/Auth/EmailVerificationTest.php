<?php

use App\Actions\Companies\CreateCompany;
use App\Models\Currency;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

test('an unverified user can access the application normally', function () {
    $this->seed(DatabaseSeeder::class);
    $user = User::factory()->unverified()->create();
    $currency = Currency::query()->where('code', 'BOB')->firstOrFail();
    app(CreateCompany::class)->handle($user, [
        'name' => 'Florería',
        'base_currency_id' => $currency->getKey(),
        'timezone' => 'America/La_Paz',
        'locale' => 'es',
    ]);

    $this->actingAs($user)->get(route('dashboard'))->assertSuccessful();

    expect($user->hasVerifiedEmail())->toBeFalse();
});

test('email verification endpoints are not exposed in this version', function () {
    $this->get('/verify-email')->assertNotFound();
});
