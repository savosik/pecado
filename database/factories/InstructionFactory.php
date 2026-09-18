<?php

namespace Database\Factories;

use App\Enums\InstructionType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Instruction>
 */
class InstructionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(4),
            'short_description' => $this->faker->sentence(12),
            'type' => InstructionType::TEXT,
            'content' => json_encode([
                'time' => 1_700_000_000_000,
                'blocks' => [
                    ['type' => 'paragraph', 'data' => ['text' => $this->faker->paragraph()]],
                ],
                'version' => '2.29.0',
            ], JSON_UNESCAPED_UNICODE),
            'video_url' => null,
            'for_clients' => false,
            'for_crm' => false,
            'for_wms' => false,
            'is_published' => true,
        ];
    }

    public function forClients(): self
    {
        return $this->state(fn () => ['for_clients' => true]);
    }

    public function forCrm(): self
    {
        return $this->state(fn () => ['for_crm' => true]);
    }

    public function forWms(): self
    {
        return $this->state(fn () => ['for_wms' => true]);
    }

    public function unpublished(): self
    {
        return $this->state(fn () => ['is_published' => false]);
    }

    public function pdf(): self
    {
        return $this->state(fn () => ['type' => InstructionType::PDF, 'content' => null]);
    }

    public function video(string $url = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'): self
    {
        return $this->state(fn () => ['type' => InstructionType::VIDEO, 'content' => null, 'video_url' => $url]);
    }
}
