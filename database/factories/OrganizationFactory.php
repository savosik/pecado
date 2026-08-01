<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'external_id' => (string) Str::uuid(),
            'name' => 'ООО «'.fake()->company().'»',
            'legal_name' => 'Общество с ограниченной ответственностью «'.fake()->company().'»',
            'tax_id' => (string) fake()->numerify('##########'),
            'tax_code' => (string) fake()->numerify('#########'),
            'is_active' => true,
            'is_stub' => false,
            'sort_order' => 0,
        ];
    }

    /**
     * Заглушка: UUID приехал из 1С, в админке организация ещё не заведена.
     */
    public function stub(): static
    {
        return $this->state(fn () => [
            'legal_name' => null,
            'tax_id' => null,
            'tax_code' => null,
            'is_stub' => true,
        ])->afterMaking(function (Organization $organization) {
            // Названия у заглушки нет — в заполнителе лежит сам UUID. Ставим его
            // после слияния атрибутов, иначе переданный в create() external_id
            // не попал бы в name.
            $organization->name = $organization->external_id;
        });
    }
}
