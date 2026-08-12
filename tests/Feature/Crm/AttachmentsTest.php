<?php

namespace Tests\Feature\Crm;

use App\Models\CrmComment;
use App\Models\Order;
use App\Models\PersonalManager;
use App\Models\User;
use App\Support\Crm\CrmAttachments;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

class AttachmentsTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $managerA;

    private User $managerB;

    private PersonalManager $profileA;

    private User $clientA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        Storage::fake(config('media-library.disk_name'));

        $this->managerA = User::factory()->create();
        $this->managerA->assignRole('sales-manager');
        $this->profileA = PersonalManager::factory()->create(['user_id' => $this->managerA->id]);

        $this->managerB = User::factory()->create();
        $this->managerB->assignRole('sales-manager');
        PersonalManager::factory()->create(['user_id' => $this->managerB->id]);

        $this->clientA = User::factory()->create(['personal_manager_id' => $this->profileA->id]);
    }

    private function salesHead(): User
    {
        $user = User::factory()->create();
        $user->assignRole('sales-head');

        return $user;
    }

    /**
     * Файл с настоящим содержимым: MediaLibrary определяет mime по содержимому,
     * а не по заявленному типу, и пустышка от `fake()->create()` отлетела бы
     * как `application/x-empty`.
     */
    private function upload(User $actor, string $type, int $id, ?UploadedFile $file = null)
    {
        return $this->actingAs($actor)->postJson(route('crm.attachments.store'), [
            'entity_type' => $type,
            'entity_id' => $id,
            'file' => $file ?? UploadedFile::fake()->image('спецификация.jpg'),
        ]);
    }

    #[Test]
    public function file_is_attached_to_client_order_and_comment(): void
    {
        $order = Order::factory()->create(['user_id' => $this->clientA->id]);
        $comment = CrmComment::factory()->on($this->clientA)->by($this->managerA)->create();

        $this->upload($this->managerA, 'client', $this->clientA->id)->assertCreated();
        $this->upload($this->managerA, 'order', $order->id)->assertCreated();
        $this->upload($this->managerA, 'comment', $comment->id)->assertCreated();

        $this->assertCount(1, $this->clientA->fresh()->getMedia(CrmAttachments::COLLECTION));
        $this->assertCount(1, $order->fresh()->getMedia(CrmAttachments::COLLECTION));
        $this->assertCount(1, $comment->fresh()->getMedia(CrmAttachments::COLLECTION));
    }

    #[Test]
    public function uploader_is_recorded_in_custom_properties(): void
    {
        $response = $this->upload($this->managerA, 'client', $this->clientA->id)->assertCreated();

        $media = \App\Models\Media::findOrFail($response->json('id'));

        $this->assertSame($this->managerA->id, $media->getCustomProperty('uploaded_by'));
        $this->assertSame($this->managerA->name, $media->getCustomProperty('uploaded_by_name'));
    }

    #[Test]
    public function cannot_attach_to_foreign_client(): void
    {
        $foreignProfile = PersonalManager::factory()->create();
        $foreign = User::factory()->create(['personal_manager_id' => $foreignProfile->id]);

        $this->upload($this->managerA, 'client', $foreign->id)->assertNotFound();
    }

    #[Test]
    public function cannot_attach_to_foreign_clients_order(): void
    {
        $foreignProfile = PersonalManager::factory()->create();
        $foreign = User::factory()->create(['personal_manager_id' => $foreignProfile->id]);
        $order = Order::factory()->create(['user_id' => $foreign->id]);

        $this->upload($this->managerA, 'order', $order->id)->assertNotFound();
    }

    #[Test]
    public function oversized_file_is_rejected_with_russian_message(): void
    {
        $tooBig = UploadedFile::fake()->create(
            'огромный.pdf',
            (CrmAttachments::MAX_MB * 1024) + 1,
            'application/pdf'
        );

        $response = $this->upload($this->managerA, 'client', $this->clientA->id, $tooBig)
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertStringContainsString('МБ', $response->json('errors.file.0'));
    }

    #[Test]
    public function forbidden_mime_is_rejected(): void
    {
        $script = UploadedFile::fake()->create('вирус.exe', 10, 'application/x-msdownload');

        $this->upload($this->managerA, 'client', $this->clientA->id, $script)
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    #[Test]
    public function author_deletes_own_file_and_colleague_cannot(): void
    {
        $mediaId = $this->upload($this->managerA, 'client', $this->clientA->id)->json('id');

        // Коллега клиента не видит — 404 раньше, чем правило авторства.
        $this->actingAs($this->managerB)
            ->deleteJson(route('crm.attachments.destroy', $mediaId))
            ->assertNotFound();

        $this->actingAs($this->managerA)
            ->deleteJson(route('crm.attachments.destroy', $mediaId))
            ->assertOk();

        $this->assertDatabaseMissing('media', ['id' => $mediaId]);
    }

    #[Test]
    public function sales_head_deletes_foreign_file(): void
    {
        $mediaId = $this->upload($this->managerA, 'client', $this->clientA->id)->json('id');

        $this->actingAs($this->salesHead())
            ->deleteJson(route('crm.attachments.destroy', $mediaId))
            ->assertOk();
    }

    #[Test]
    public function manager_sees_but_cannot_delete_file_uploaded_by_someone_else(): void
    {
        // Файл на «своём» клиенте загрузил РОП: клиента менеджер видит,
        // а чужое вложение трогать не должен.
        $mediaId = $this->upload($this->salesHead(), 'client', $this->clientA->id)->json('id');

        $this->actingAs($this->managerA)
            ->getJson(route('crm.attachments.index', ['entity_type' => 'client', 'entity_id' => $this->clientA->id]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.can_delete', false);

        $this->actingAs($this->managerA)
            ->deleteJson(route('crm.attachments.destroy', $mediaId))
            ->assertForbidden();

        $this->assertDatabaseHas('media', ['id' => $mediaId]);
    }

    #[Test]
    public function empty_or_mistyped_file_gives_validation_error_not_500(): void
    {
        // Заявленный тип pdf, а содержимого нет: валидация формы это пропустит,
        // а MediaLibrary отвергнет — ответ должен остаться 422.
        $empty = UploadedFile::fake()->create('пустой.pdf', 10, 'application/pdf');

        $this->upload($this->managerA, 'client', $this->clientA->id, $empty)
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    #[Test]
    public function file_is_served_through_the_app_not_by_a_public_disk_url(): void
    {
        $response = $this->upload($this->managerA, 'client', $this->clientA->id)->assertCreated();

        // Ссылка обязана вести в приложение: публичный URL диска раздавал бы
        // документы клиента кому угодно в обход скоупа.
        $this->assertSame(
            route('crm.attachments.download', $response->json('id')),
            $response->json('url'),
        );

        $this->actingAs($this->managerA)
            ->get($response->json('url'))
            ->assertOk();
    }

    #[Test]
    public function foreign_manager_cannot_download_file(): void
    {
        $mediaId = $this->upload($this->managerA, 'client', $this->clientA->id)->json('id');

        $this->actingAs($this->managerB)
            ->get(route('crm.attachments.download', $mediaId))
            ->assertNotFound();
    }

    #[Test]
    public function media_outside_crm_collection_is_not_downloadable(): void
    {
        $media = $this->clientA->addMedia(UploadedFile::fake()->image('avatar.jpg'))
            ->toMediaCollection('avatar');

        $this->actingAs($this->managerA)
            ->get(route('crm.attachments.download', $media->id))
            ->assertNotFound();
    }

    #[Test]
    public function media_outside_crm_collection_is_not_deletable_here(): void
    {
        $media = $this->clientA->addMedia(UploadedFile::fake()->image('avatar.jpg'))
            ->toMediaCollection('avatar');

        $this->actingAs($this->managerA)
            ->deleteJson(route('crm.attachments.destroy', $media->id))
            ->assertNotFound();

        $this->assertDatabaseHas('media', ['id' => $media->id]);
    }
}
