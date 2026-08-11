<?php

namespace App\Actions\Fortify;

use App\Actions\Companies\CreateCompany;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(private CreateCompany $createCompany) {}

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'company_name' => ['sometimes', 'string', 'max:255'],
            'password' => $this->passwordRules(),
        ])->validate();

        return DB::transaction(function () use ($input): User {
            $user = User::query()->create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $currency = Currency::query()
                ->where('code', config('tenancy.registration.currency'))
                ->where('is_active', true)
                ->firstOrFail();

            $this->createCompany->handle($user, [
                'name' => $input['company_name'] ?? $input['name'],
                'base_currency_id' => $currency->getKey(),
                'timezone' => config('tenancy.registration.timezone'),
                'locale' => config('tenancy.registration.locale'),
            ]);

            return $user;
        }, attempts: 3);
    }
}
