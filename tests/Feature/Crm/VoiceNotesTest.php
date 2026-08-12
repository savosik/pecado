<?php

namespace Tests\Feature\Crm;

use App\Models\PersonalManager;
use App\Models\User;
use App\Support\Crm\CrmAttachments;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Голосовые записи в CRM (crm-25).
 *
 * Главное, что проверяется: голос живёт в своей коллекции и не смешивается
 * с документами. Смешавшись со спецификациями и счетами, надиктованная
 * заметка потерялась бы там, где её как раз и ищут.
 */
class VoiceNotesTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        Storage::fake(config('media-library.disk_name'));

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $card = PersonalManager::factory()->create(['user_id' => $this->manager->id]);

        $this->client = User::factory()->create(['personal_manager_id' => $card->id]);
    }

    /**
     * Настоящий WAV, а не заглушка.
     *
     * MediaLibrary проверяет фактическое содержимое, а не заявленный тип:
     * файл из UploadedFile::fake()->create() определяется как текст и коллекция
     * его отклоняет. Поэтому собираем минимальный валидный контейнер.
     */
    private function voice(string $name = 'note.wav'): UploadedFile
    {
        $samples = str_repeat("\x00\x00", 512);
        $dataSize = strlen($samples);

        $wav = 'RIFF'.pack('V', 36 + $dataSize).'WAVE'
            .'fmt '.pack('V', 16).pack('v', 1).pack('v', 1)
            .pack('V', 8000).pack('V', 16000).pack('v', 2).pack('v', 16)
            .'data'.pack('V', $dataSize).$samples;

        return UploadedFile::fake()->createWithContent($name, $wav);
    }

    #[Test]
    public function голосовая_заметка_ложится_в_свою_коллекцию(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('crm.attachments.store'), [
                'entity_type' => 'client',
                'entity_id' => $this->client->id,
                'kind' => 'voice',
                'duration_seconds' => 42,
                'file' => $this->voice(),
            ])
            ->assertCreated()
            ->assertJsonPath('duration_label', '0:42');

        $this->assertDatabaseHas('media', [
            'model_id' => $this->client->id,
            'collection_name' => CrmAttachments::VOICE_COLLECTION,
        ]);
    }

    #[Test]
    public function голос_не_попадает_в_список_документов(): void
    {
        $this->actingAs($this->manager)->postJson(route('crm.attachments.store'), [
            'entity_type' => 'client',
            'entity_id' => $this->client->id,
            'kind' => 'voice',
            'file' => $this->voice(),
        ])->assertCreated();

        $this->actingAs($this->manager)
            ->getJson(route('crm.attachments.index', [
                'entity_type' => 'client',
                'entity_id' => $this->client->id,
            ]))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->manager)
            ->getJson(route('crm.attachments.index', [
                'entity_type' => 'client',
                'entity_id' => $this->client->id,
                'kind' => 'voice',
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    #[Test]
    public function документ_нельзя_загрузить_как_голос(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('crm.attachments.store'), [
                'entity_type' => 'client',
                'entity_id' => $this->client->id,
                'kind' => 'voice',
                'file' => UploadedFile::fake()->create('spec.pdf', 100, 'application/pdf'),
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.file.0',
                'Такой формат записи не поддерживается. Разрешены webm, ogg, mp3, m4a, mp4 и wav.',
            );
    }

    /**
     * Минимальный EBML/Matroska с DocType «webm» — ровно тот контейнер,
     * который отдаёт MediaRecorder в Chromium.
     */
    private function chromiumWebm(): UploadedFile
    {
        $ebml = "\x1A\x45\xDF\xA3"."\x01\x00\x00\x00\x00\x00\x00\x1F"
            ."\x42\x86\x81\x01"."\x42\xF7\x81\x01"."\x42\xF2\x81\x04"
            ."\x42\xF3\x81\x08"."\x42\x82\x84".'webm'
            ."\x42\x87\x81\x02"."\x42\x85\x81\x02";

        return UploadedFile::fake()->createWithContent('voice.webm', $ebml.str_repeat("\x00", 2048));
    }

    /** Заголовок ftyp mp42 — то, что пишет MediaRecorder в Safari. */
    private function safariMp4(): UploadedFile
    {
        $ftyp = "\x00\x00\x00\x1cftypmp42\x00\x00\x00\x00mp42isom";

        return UploadedFile::fake()->createWithContent('voice.mp4', $ftyp.str_repeat("\x00", 2048));
    }

    /**
     * Регрессия: запись из Chromium отлетала с «формат не поддерживается».
     *
     * Браузер отдаёт blob с типом `audio/webm`, но валидация смотрит
     * на фактический тип через finfo, а WebM — контейнер: аудио-дорожку
     * от видео по магическим байтам не отличить, и finfo отвечает
     * `video/webm`. Первая версия списка форматов этого не знала, а тест
     * собирал WAV и настоящий путь не проверял.
     */
    #[Test]
    public function запись_из_chromium_принимается(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('crm.attachments.store'), [
                'entity_type' => 'client',
                'entity_id' => $this->client->id,
                'kind' => 'voice',
                'duration_seconds' => 12,
                'file' => $this->chromiumWebm(),
            ])
            ->assertCreated();

        $this->assertDatabaseHas('media', [
            'model_id' => $this->client->id,
            'collection_name' => CrmAttachments::VOICE_COLLECTION,
        ]);
    }

    #[Test]
    public function запись_из_safari_принимается(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('crm.attachments.store'), [
                'entity_type' => 'client',
                'entity_id' => $this->client->id,
                'kind' => 'voice',
                'file' => $this->safariMp4(),
            ])
            ->assertCreated();
    }

    /**
     * Список форматов задаётся контейнерами: `audio/*` недостаточно, потому что
     * finfo для webm и mp4 отвечает `video/*` даже на чистом аудио.
     */
    #[Test]
    public function список_форматов_покрывает_контейнеры_а_не_только_аудио_типы(): void
    {
        foreach (['audio/webm', 'video/webm', 'audio/mp4', 'video/mp4'] as $mime) {
            $this->assertContains($mime, CrmAttachments::VOICE_MIMES, "Не хватает {$mime}");
        }
    }

    #[Test]
    public function валидный_контейнер_принимается(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('crm.attachments.store'), [
                'entity_type' => 'client',
                'entity_id' => $this->client->id,
                'kind' => 'voice',
                'file' => $this->voice('note.wav'),
            ])
            ->assertCreated();
    }

    #[Test]
    public function чужую_запись_без_доступа_к_клиенту_не_скачать(): void
    {
        $this->actingAs($this->manager)->postJson(route('crm.attachments.store'), [
            'entity_type' => 'client',
            'entity_id' => $this->client->id,
            'kind' => 'voice',
            'file' => $this->voice(),
        ])->assertCreated();

        $mediaId = \App\Models\Media::query()->value('id');

        // Менеджер без права на отдел и без этого клиента.
        \Spatie\Permission\Models\Role::findByName('sales-manager')
            ->revokePermissionTo('crm-department.view');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $stranger = User::factory()->create();
        $stranger->assignRole('sales-manager');
        PersonalManager::factory()->create(['user_id' => $stranger->id]);

        $this->actingAs($stranger)
            ->get(route('crm.attachments.download', $mediaId))
            ->assertNotFound();
    }
}
