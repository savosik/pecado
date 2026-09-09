<?php

namespace Database\Factories;

use App\Models\CrmClientAvatar;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrmClientAvatar>
 */
class CrmClientAvatarFactory extends Factory
{
    protected $model = CrmClientAvatar::class;

    /**
     * По умолчанию — запись без файла: так выглядит партнёр, которому ещё
     * не рисовали.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'attempts' => 0,
        ];
    }

    /**
     * С файлом на диске — состояние «аватарка есть».
     */
    public function withFile(string $source = CrmClientAvatar::SOURCE_AI): static
    {
        return $this->state(fn (array $attributes): array => [
            // user_id в состоянии может быть ещё фабрикой (модель не создана),
            // поэтому путь не привязываем к нему: в тестах важен сам факт файла.
            'path' => 'seeded/'.fake()->lexify('????????????').'.webp',
            'disk' => (string) config('crm_avatars.disk', 'crm-avatars'),
            'source' => $source,
            'mime' => 'image/webp',
            'size' => 12345,
            'checksum' => hash('sha256', fake()->uuid()),
            'generated_at' => now(),
        ]);
    }
}
