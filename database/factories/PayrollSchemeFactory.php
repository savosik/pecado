<?php

namespace Database\Factories;

use App\Models\PayrollScheme;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollScheme>
 */
class PayrollSchemeFactory extends Factory
{
    protected $model = PayrollScheme::class;

    public function definition(): array
    {
        $default = (array) config('payroll.default_scheme', []);

        return [
            'code' => PayrollScheme::CODE_SALES,
            'version' => 1,
            'title' => 'Схема для тестов',
            'effective_from' => '2026-01-01',
            'components' => $default['components'] ?? [],
            'author_id' => null,
            'comment' => null,
        ];
    }
}
