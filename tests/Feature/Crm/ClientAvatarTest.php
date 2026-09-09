<?php

namespace Tests\Feature\Crm;

use App\Jobs\GenerateClientAvatar;
use App\Models\CrmClientAvatar;
use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Аватарки партнёров: загрузка, отдача и — главное — то, что картинка
 * не выходит за пределы CRM.
 */
class ClientAvatarTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $manager;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        Storage::fake('crm-avatars');

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $profile = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->client = User::factory()->create(['personal_manager_id' => $profile->id]);
    }

    /** Настоящий PNG: GD в процессоре разбирает файл, заглушкой не обойтись. */
    private function pngFile(int $side = 400): UploadedFile
    {
        return UploadedFile::fake()->image('avatar.png', $side, $side);
    }

    #[Test]
    #[TestDox('Менеджер загружает аватарку, файл ложится на приватный диск в WebP')]
    public function manager_uploads_an_avatar(): void
    {
        $this->actingAs($this->manager)
            ->post(route('crm.clients.avatar.store', $this->client), ['avatar' => $this->pngFile()])
            ->assertOk()
            ->assertJson(['has_avatar' => true, 'source' => 'manual']);

        $avatar = CrmClientAvatar::query()->where('user_id', $this->client->id)->firstOrFail();

        $this->assertSame('image/webp', $avatar->mime);
        $this->assertSame($this->manager->id, $avatar->uploaded_by);
        Storage::disk('crm-avatars')->assertExists((string) $avatar->path);
    }

    #[Test]
    #[TestDox('Аватарка отдаётся маршрутом CRM и кешируется по ETag')]
    public function avatar_is_served_with_an_etag(): void
    {
        $this->actingAs($this->manager)
            ->post(route('crm.clients.avatar.store', $this->client), ['avatar' => $this->pngFile()])
            ->assertOk();

        $response = $this->actingAs($this->manager)
            ->get(route('crm.clients.avatar', $this->client))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp');

        // Приватный кеш: аватарка не должна осесть в общем кеше прокси.
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));

        $etag = (string) $response->headers->get('ETag');
        $this->assertNotSame('', $etag);

        $this->actingAs($this->manager)
            ->withHeaders(['If-None-Match' => $etag])
            ->get(route('crm.clients.avatar', $this->client))
            ->assertStatus(304);
    }

    #[Test]
    #[TestDox('Партнёр свою аватарку не получает — она видна только CRM')]
    public function the_partner_cannot_see_their_own_avatar(): void
    {
        $this->actingAs($this->manager)
            ->post(route('crm.clients.avatar.store', $this->client), ['avatar' => $this->pngFile()])
            ->assertOk();

        // У партнёра нет прав CRM: middleware 'crm' разворачивает его на пороге
        // раздела — редиректом, как и на любом другом адресе CRM.
        $response = $this->actingAs($this->client)
            ->get(route('crm.clients.avatar', $this->client))
            ->assertRedirect();

        $this->assertNotSame('image/webp', $response->headers->get('Content-Type'));

        // И гостю тоже: маршрут за auth.
        $this->get(route('crm.clients.avatar', $this->client))->assertRedirect();
    }

    #[Test]
    #[TestDox('Файл не лежит на публичном диске')]
    public function the_file_never_reaches_public_storage(): void
    {
        Storage::fake('public');

        $this->actingAs($this->manager)
            ->post(route('crm.clients.avatar.store', $this->client), ['avatar' => $this->pngFile()])
            ->assertOk();

        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame('crm-avatars', config('crm_avatars.disk'));
        // Диск обязан быть приватным: иначе появился бы публичный адрес.
        $this->assertSame('private', config('filesystems.disks.crm-avatars.visibility'));
        $this->assertFalse((bool) config('filesystems.disks.crm-avatars.serve'));
    }

    #[Test]
    #[TestDox('Чужой партнёр — 404, а не 403: ответ не подтверждает, что он есть')]
    public function a_foreign_partner_is_hidden_behind_404(): void
    {
        $foreign = User::factory()->create([
            'personal_manager_id' => PersonalManager::factory()->create()->id,
        ]);

        $this->actingAs($this->manager)
            ->get(route('crm.clients.avatar', $foreign))
            ->assertNotFound();

        $this->actingAs($this->manager)
            ->post(route('crm.clients.avatar.store', $foreign), ['avatar' => $this->pngFile()])
            ->assertNotFound();
    }

    #[Test]
    #[TestDox('Партнёру без аватарки маршрут отвечает 404 — фронт покажет инициалы')]
    public function a_partner_without_an_avatar_returns_404(): void
    {
        $this->actingAs($this->manager)
            ->get(route('crm.clients.avatar', $this->client))
            ->assertNotFound();
    }

    #[Test]
    #[TestDox('Снятие удаляет файл и обнуляет счётчик попыток')]
    public function removing_an_avatar_deletes_the_file(): void
    {
        $this->actingAs($this->manager)
            ->post(route('crm.clients.avatar.store', $this->client), ['avatar' => $this->pngFile()])
            ->assertOk();

        $path = (string) CrmClientAvatar::query()->where('user_id', $this->client->id)->value('path');

        $this->actingAs($this->manager)
            ->delete(route('crm.clients.avatar.destroy', $this->client))
            ->assertOk();

        Storage::disk('crm-avatars')->assertMissing($path);
        $avatar = CrmClientAvatar::query()->where('user_id', $this->client->id)->firstOrFail();
        $this->assertNull($avatar->path);
        $this->assertSame(0, $avatar->attempts);
    }

    #[Test]
    #[TestDox('Перерисовка ставит задание в очередь, а не держит запрос')]
    public function regeneration_is_queued(): void
    {
        Queue::fake();

        $this->actingAs($this->manager)
            ->post(route('crm.clients.avatar.regenerate', $this->client))
            ->assertOk();

        Queue::assertPushed(
            GenerateClientAvatar::class,
            fn (GenerateClientAvatar $job): bool => $job->clientId === $this->client->id && $job->force,
        );
    }

    #[Test]
    #[TestDox('Аватарка приезжает в список партнёров и в карточку')]
    public function the_avatar_reaches_the_list_and_the_card(): void
    {
        $this->actingAs($this->manager)
            ->post(route('crm.clients.avatar.store', $this->client), ['avatar' => $this->pngFile()])
            ->assertOk();

        $this->actingAs($this->manager)
            ->get(route('crm.clients.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('clients.data.0.avatar.source', 'manual')
                ->has('clients.data.0.avatar.url')
            );

        $this->actingAs($this->manager)
            ->get(route('crm.clients.show', $this->client))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('client.avatar.source', 'manual'));
    }

    #[Test]
    #[TestDox('Не картинка отбивается русской ошибкой')]
    public function a_non_image_is_rejected_in_russian(): void
    {
        $response = $this->actingAs($this->manager)
            ->postJson(route('crm.clients.avatar.store', $this->client), [
                'avatar' => UploadedFile::fake()->create('прайс.pdf', 20, 'application/pdf'),
            ]);

        $response->assertStatus(422);
        $this->assertSame('Файл должен быть картинкой.', $response->json('errors.avatar.0'));
    }
}
