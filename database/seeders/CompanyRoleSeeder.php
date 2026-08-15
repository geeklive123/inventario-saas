<?php

namespace Database\Seeders;

use App\Actions\Companies\ProvisionDefaultRoles;
use App\Models\Company;
use Illuminate\Database\Seeder;

class CompanyRoleSeeder extends Seeder
{
    public function run(): void
    {
        Company::query()->eachById(function (Company $company): void {
            app(ProvisionDefaultRoles::class)->handle($company);
        });
    }
}
