<?php

namespace Database\Seeders;

use App\Actions\Companies\ProvisionDefaultExpenseCategories;
use App\Models\Company;
use Illuminate\Database\Seeder;

class ExpenseCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Company::query()->eachById(function (Company $company): void {
            app(ProvisionDefaultExpenseCategories::class)->handle($company);
        });
    }
}
