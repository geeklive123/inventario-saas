<?php

namespace Database\Seeders;

use App\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Currency::query()->upsert([
            [
                'code' => 'BOB',
                'name' => 'Boliviano',
                'symbol' => 'Bs',
                'decimal_places' => 2,
                'is_active' => true,
            ],
            [
                'code' => 'USD',
                'name' => 'US Dollar',
                'symbol' => '$',
                'decimal_places' => 2,
                'is_active' => true,
            ],
        ], ['code'], ['name', 'symbol', 'decimal_places', 'is_active']);
    }
}
