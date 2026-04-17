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
        $currencies = [
            [
                'code' => 'RUB',
                'name' => 'Российский рубль',
                'symbol' => '₽',
                'is_base' => true,
                'exchange_rate' => 1.0000000000,
                'rate_coefficient' => 1.0000,
            ],
            [
                'code' => 'BYN',
                'name' => 'Белорусский рубль',
                'symbol' => 'Br',
                'is_base' => false,
                'exchange_rate' => 1.0000000000,
                'rate_coefficient' => 1.0000,
            ],
            [
                'code' => 'KZT',
                'name' => 'Тенге',
                'symbol' => '₸',
                'is_base' => false,
                'exchange_rate' => 1.0000000000,
                'rate_coefficient' => 1.0000,
            ],
        ];

        foreach ($currencies as $currency) {
            Currency::updateOrCreate(
                ['code' => $currency['code']],
                $currency
            );
        }
    }
}
