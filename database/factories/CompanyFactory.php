<?php

namespace Database\Factories;

use App\Enums\Country;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $country = fake()->randomElement(Country::cases());

        return [
            'user_id' => User::factory(),
            'country' => $country,
            'name' => fake()->company(),
            'legal_name' => 'ООО "' . fake()->company() . '"',
            'tax_id' => $this->generateTaxId($country),
            'registration_number' => $country === Country::RU ? fake()->numerify('1##############') : null,
            'tax_code' => $country === Country::RU ? fake()->numerify('#########') : null,
            'okpo_code' => in_array($country, [Country::RU, Country::BY]) ? fake()->numerify('########') : null,
            'legal_address' => fake()->address(),
            'actual_address' => fake()->optional()->address(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->companyEmail(),
        ];
    }

    /**
     * Generate country-specific tax ID.
     */
    private function generateTaxId(Country $country): string
    {
        return match ($country) {
            Country::RU => fake()->numerify('##########'), // ИНН 10 digits for legal entity
            Country::BY => fake()->numerify('#########'), // УНП 9 digits
            Country::KZ => fake()->numerify('############'), // БИН 12 digits
        };
    }

    /**
     * Set company country to Russia.
     */
    public function russia(): static
    {
        return $this->state(fn (array $attributes) => [
            'country' => Country::RU,
            'tax_id' => fake()->numerify('##########'),
            'registration_number' => fake()->numerify('1##############'),
            'tax_code' => fake()->numerify('#########'),
            'okpo_code' => fake()->numerify('########'),
        ]);
    }

    /**
     * Set company country to Belarus.
     */
    public function belarus(): static
    {
        return $this->state(fn (array $attributes) => [
            'country' => Country::BY,
            'tax_id' => fake()->numerify('#########'),
            'registration_number' => null,
            'tax_code' => null,
            'okpo_code' => fake()->numerify('########'),
        ]);
    }

    /**
     * Set company country to Kazakhstan.
     */
    public function kazakhstan(): static
    {
        return $this->state(fn (array $attributes) => [
            'country' => Country::KZ,
            'tax_id' => fake()->numerify('############'),
            'registration_number' => null,
            'tax_code' => null,
            'okpo_code' => null,
        ]);
    }
}
