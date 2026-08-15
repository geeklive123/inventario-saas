<?php

namespace Database\Seeders;

use App\Actions\Companies\ProvisionDefaultPaymentMethods;
use App\Models\Company;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Company::query()->eachById(function (Company $company): void {
            app(ProvisionDefaultPaymentMethods::class)->handle($company);
        });
    }
}
