<?php

namespace Tests\Feature\Crm;

use App\Jobs\GenerateClientAvatar;
use App\Models\CrmClientAvatar;
use App\Models\PersonalManager;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Рисование аватарки: два хода к OpenRouter и то, что происходит, когда
 * второй ход не удался.
 */
class ClientAvatarGenerationTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('crm-avatars');
        config(['normalizer.api_key' => 'test-key']);

        $this->client = User::factory()->create([
            'erp_name' => 'Гончарова Кристина Александровна ИП, г.Москва',
            'personal_manager_id' => PersonalManager::factory()->create()->id,
        ]);
    }

    /** Однопиксельный PNG в base64 — ровно то, что вернула бы модель. */
    private function pngDataUrl(): string
    {
        $png = base64_encode((string) file_get_contents($this->makePng()));

        return 'data:image/png;base64,'.$png;
    }

    private function makePng(): string
    {
        $image = imagecreatetruecolor(64, 64);
        $path = tempnam(sys_get_temp_dir(), 'avatar').'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    private function fakeOpenRouter(?string $imageUrl = null): void
    {
        $imageUrl = $imageUrl ?? $this->pngDataUrl();
        $calls = 0;

        Http::fake([
            'openrouter.ai/*' => function () use (&$calls, $imageUrl) {
                $calls++;

                // Первый вызов — текстовая модель с промтом, второй — художник.
                return $calls === 1
                    ? Http::response(['choices' => [['message' => ['content' => 'friendly flat vector otter merchant mascot']]]])
                    : Http::response(['choices' => [['message' => ['images' => [['image_url' => ['url' => $imageUrl]]]]]]]);
            },
        ]);
    }

    #[Test]
    #[TestDox('Два хода: текстовая модель придумывает промт, художник рисует')]
    public function it_generates_in_two_steps(): void
    {
        $this->fakeOpenRouter();

        Shipment::factory()->create([
            'user_id' => $this->client->id,
            'total_amount' => 1_500_000,
            'erp_created_at' => now()->subMonth(),
        ]);

        (new GenerateClientAvatar($this->client->id))->handle(
            app(\App\Services\Crm\Avatars\ClientAvatarService::class),
            app(\App\Services\Crm\Avatars\ClientAvatarGenerator::class),
        );

        $avatar = CrmClientAvatar::query()->where('user_id', $this->client->id)->firstOrFail();

        $this->assertSame(CrmClientAvatar::SOURCE_AI, $avatar->source);
        $this->assertSame('image/webp', $avatar->mime);
        $this->assertStringContainsString('otter', (string) $avatar->prompt);
        Storage::disk('crm-avatars')->assertExists((string) $avatar->path);

        // Факты о партнёре обязаны доехать до текстовой модели — иначе промт
        // будет одинаковым для всей базы.
        Http::assertSent(function (Request $request): bool {
            $body = json_encode($request->data(), JSON_UNESCAPED_UNICODE);

            return str_contains((string) $body, 'Гончарова')
                && str_contains((string) $body, 'женщина')
                && str_contains((string) $body, 'средний');
        });
    }

    #[Test]
    #[TestDox('Осечка модели не роняет очередь, а считается попыткой')]
    public function a_failure_is_counted_not_thrown(): void
    {
        Http::fake(['openrouter.ai/*' => Http::response(['error' => ['message' => 'rate limited']], 429)]);

        (new GenerateClientAvatar($this->client->id))->handle(
            app(\App\Services\Crm\Avatars\ClientAvatarService::class),
            app(\App\Services\Crm\Avatars\ClientAvatarGenerator::class),
        );

        $avatar = CrmClientAvatar::query()->where('user_id', $this->client->id)->firstOrFail();

        $this->assertNull($avatar->path);
        $this->assertSame(1, $avatar->attempts);
        $this->assertNotNull($avatar->failed_at);
        $this->assertStringContainsString('429', (string) $avatar->failure_reason);
    }

    #[Test]
    #[TestDox('Ручную аватарку ИИ не перерисовывает даже принудительно')]
    public function a_manual_avatar_is_never_overwritten(): void
    {
        $this->fakeOpenRouter();

        CrmClientAvatar::factory()
            ->withFile(CrmClientAvatar::SOURCE_MANUAL)
            ->create(['user_id' => $this->client->id]);

        $before = CrmClientAvatar::query()->where('user_id', $this->client->id)->firstOrFail()->path;

        (new GenerateClientAvatar($this->client->id, force: true))->handle(
            app(\App\Services\Crm\Avatars\ClientAvatarService::class),
            app(\App\Services\Crm\Avatars\ClientAvatarGenerator::class),
        );

        $this->assertSame(
            $before,
            CrmClientAvatar::query()->where('user_id', $this->client->id)->firstOrFail()->path,
        );
        Http::assertNothingSent();
    }

    #[Test]
    #[TestDox('Исчерпанные попытки останавливают повторы до кнопки менеджера')]
    public function exhausted_attempts_stop_the_retries(): void
    {
        $this->fakeOpenRouter();

        CrmClientAvatar::factory()->create([
            'user_id' => $this->client->id,
            'attempts' => (int) config('crm_avatars.generation.max_attempts'),
            'failed_at' => now()->subYear(),
        ]);

        (new GenerateClientAvatar($this->client->id))->handle(
            app(\App\Services\Crm\Avatars\ClientAvatarService::class),
            app(\App\Services\Crm\Avatars\ClientAvatarGenerator::class),
        );

        Http::assertNothingSent();

        // Кнопка в карточке (force) сильнее счётчика: менеджер решил, что надо.
        (new GenerateClientAvatar($this->client->id, force: true))->handle(
            app(\App\Services\Crm\Avatars\ClientAvatarService::class),
            app(\App\Services\Crm\Avatars\ClientAvatarGenerator::class),
        );

        $this->assertNotNull(
            CrmClientAvatar::query()->where('user_id', $this->client->id)->firstOrFail()->path,
        );
    }

    #[Test]
    #[TestDox('Картинка читается и из data-URL в тексте ответа')]
    public function an_image_inside_plain_content_is_understood(): void
    {
        // Разные модели отдают картинку по-разному: смена модели в конфиге
        // не должна ломать генерацию.
        $dataUrl = $this->pngDataUrl();
        $calls = 0;

        Http::fake([
            'openrouter.ai/*' => function () use (&$calls, $dataUrl) {
                $calls++;

                return $calls === 1
                    ? Http::response(['choices' => [['message' => ['content' => 'a cheerful fox']]]])
                    : Http::response(['choices' => [['message' => ['content' => "Готово: {$dataUrl}"]]]]);
            },
        ]);

        (new GenerateClientAvatar($this->client->id))->handle(
            app(\App\Services\Crm\Avatars\ClientAvatarService::class),
            app(\App\Services\Crm\Avatars\ClientAvatarGenerator::class),
        );

        $this->assertNotNull(
            CrmClientAvatar::query()->where('user_id', $this->client->id)->firstOrFail()->path,
        );
    }

    #[Test]
    #[TestDox('Команда ставит задания только тем, у кого аватарки нет')]
    public function the_command_queues_only_partners_without_avatars(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $withAvatar = User::factory()->create([
            'personal_manager_id' => PersonalManager::factory()->create()->id,
        ]);
        CrmClientAvatar::factory()->withFile()->create(['user_id' => $withAvatar->id]);

        $this->artisan('crm:avatars-generate')->assertSuccessful();

        \Illuminate\Support\Facades\Queue::assertPushed(
            GenerateClientAvatar::class,
            fn (GenerateClientAvatar $job): bool => $job->clientId === $this->client->id,
        );
        \Illuminate\Support\Facades\Queue::assertNotPushed(
            GenerateClientAvatar::class,
            fn (GenerateClientAvatar $job): bool => $job->clientId === $withAvatar->id,
        );
    }
}
