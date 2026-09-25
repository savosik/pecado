<?php

namespace Database\Factories;

use App\Models\AgentHubLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentHubLink>
 */
class AgentHubLinkFactory extends Factory
{
    protected $model = AgentHubLink::class;

    public function definition(): array
    {
        return [
            'label' => 'Админ 1С',
            'note' => null,
        ];
    }

    /** Отозванная ссылка — пульт и API по ней отвечают 404. */
    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }
}
