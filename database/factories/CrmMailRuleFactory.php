<?php

namespace Database\Factories;

use App\Models\CrmMailRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrmMailRule>
 */
class CrmMailRuleFactory extends Factory
{
    protected $model = CrmMailRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Правило '.fake()->word(),
            'conditions' => null,
            'recipients' => [fake()->safeEmail()],
            'cc' => null,
            'auto_send' => false,
            'is_active' => true,
        ];
    }

    /**
     * Правило, ловящее письма по метке целиком.
     */
    public function byTag(string $tag): static
    {
        return $this->state(fn () => [
            'conditions' => ['all' => [['field' => 'tag', 'op' => 'has_tag', 'value' => $tag]]],
        ]);
    }

    /**
     * @param  array<int, string>  $addresses
     */
    public function to(array $addresses): static
    {
        return $this->state(fn () => ['recipients' => $addresses]);
    }

    public function auto(): static
    {
        return $this->state(fn () => ['auto_send' => true]);
    }
}
