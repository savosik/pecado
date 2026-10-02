<?php

namespace Tests\Feature\Erp;

use App\Enums\Crm\EmailStatus;
use App\Enums\PrintedDocumentType;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\CrmEmail;
use App\Models\NotificationPreference;
use App\Models\PersonalManager;
use App\Models\PrintedDocument;
use App\Models\User;
use App\Queue\Jobs\ErpIncomingJob;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Отзыв печатной формы из 1С → письмо «Документ отозван» персональному менеджеру.
 *
 * Решение заказчика 02.10.2026: об отзыве узнаёт только менеджер партнёра,
 * клиенту письмо не уходит ни при каких настройках.
 *
 * Сообщения идут через ErpIncomingJob, а не прямым вызовом обработчика: так
 * проверяется весь путь шины — валидация схемы, дедупликация по `message_id`,
 * отсечение ревизий — и то, что письмо не способно уронить сам отзыв.
 *
 * Все даты — от today(): календарная константа превратила бы тест в бомбу.
 */
class PrintedDocumentRevokedMailTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private const MAX_AGE_DAYS = 31;

    private const MANAGER_EMAIL = 'manager@pecado.ru';

    private const CLIENT_EMAIL = 'client@example.com';

    private User $manager;

    private User $client;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        config([
            'documents.enabled' => true,
            'documents.notify_max_age_days' => self::MAX_AGE_DAYS,
            'mail_stream.enabled' => true,
            'mail_stream.autosend' => true,
            'mail_stream.notifications_live' => true,
            'notifications.mail.features.crm_outbound' => true,
        ]);

        $this->manager = User::factory()->create(['email' => self::MANAGER_EMAIL]);
        $this->manager->assignRole('sales-manager');
        $profile = PersonalManager::factory()->create([
            'user_id' => $this->manager->id,
            'email' => self::MANAGER_EMAIL,
        ]);

        $this->client = User::factory()->create([
            'status' => UserStatus::ACTIVE,
            'must_change_password' => false,
            'personal_manager_id' => $profile->id,
            'email' => self::CLIENT_EMAIL,
        ]);
        $this->company = Company::factory()->create(['user_id' => $this->client->id]);

        Mail::fake();
    }

    private function makeJob(array $payload): ErpIncomingJob
    {
        $rabbitmqQueue = $this->createMock(\VladimirYuldashev\LaravelQueueRabbitMQ\Queue\RabbitMQQueue::class);

        $amqpMessage = $this->createMock(\PhpAmqpLib\Message\AMQPMessage::class);
        $amqpMessage->method('getBody')->willReturn(json_encode($payload));
        $amqpMessage->delivery_info = [
            'channel' => $this->createMock(\PhpAmqpLib\Channel\AMQPChannel::class),
            'delivery_tag' => 'test-tag',
        ];

        return new ErpIncomingJob(
            app(),
            $rabbitmqQueue,
            $amqpMessage,
            'rabbitmq-erp-incoming',
            'erp_in.printed_documents',
        );
    }

    /**
     * Сообщение 1С об отзыве формы.
     */
    private function revoke(PrintedDocument $document, array $overrides = []): void
    {
        $this->makeJob(array_merge([
            'event' => 'printed_document.deleted',
            'message_id' => 'msg-'.uniqid(),
            'timestamp' => now()->toIso8601String(),
            'revision' => 2,
            'uuid' => $document->uuid,
            'reason' => 'Документ помечен на удаление в 1С',
        ], $overrides))->fire();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function document(array $attributes = []): PrintedDocument
    {
        return PrintedDocument::factory()->ofType(PrintedDocumentType::UPD)->create(array_merge([
            'user_id' => $this->client->id,
            'company_id' => $this->company->id,
            'date' => today()->subDays(2),
        ], $attributes));
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, CrmEmail>
     */
    private function letters(): \Illuminate\Database\Eloquent\Collection
    {
        return CrmEmail::query()->where('origin_event', 'documents.deleted')->get();
    }

    #[Test]
    public function отзыв_свежего_документа_даёт_одно_письмо_менеджеру_и_ни_одного_клиенту(): void
    {
        $document = $this->document(['number' => '29УТ-008415']);

        $this->revoke($document);

        $this->assertSoftDeleted('printed_documents', ['id' => $document->id]);

        $letters = $this->letters();
        $this->assertCount(1, $letters);

        $letter = $letters->first();
        $this->assertSame([self::MANAGER_EMAIL], (array) $letter->to);
        $this->assertNotContains(self::CLIENT_EMAIL, (array) $letter->to);
        $this->assertSame(EmailStatus::SENT, $letter->status);
        $this->assertSame($this->client->id, $letter->client_user_id, 'Письмо лежит в карточке партнёра, хотя адресовано менеджеру');
        $this->assertSame($document->id, (int) $letter->related_id);
        $this->assertStringContainsString('Документ отозван', (string) $letter->subject);
        $this->assertStringContainsString('29УТ-008415', (string) $letter->subject);
    }

    #[Test]
    public function письмо_уходит_без_ручной_подписки_партнёра(): void
    {
        // Тип внутренний и включён умолчанием: строк в настройках партнёра нет.
        $this->assertSame(0, NotificationPreference::query()->count());

        $this->revoke($this->document());

        $this->assertSame([self::MANAGER_EMAIL], (array) $this->letters()->sole()->to);
    }

    #[Test]
    public function старая_настройка_клиента_с_его_адресом_письмо_клиенту_не_возвращает(): void
    {
        // До 02.10.2026 тип был виден в кабинете, и клиент мог включить его себе.
        // Строка сильнее умолчания — именно так тишину уже пробивали однажды.
        NotificationPreference::query()->create([
            'user_id' => $this->client->id,
            'occasion_key' => 'documents.deleted',
            'is_enabled' => true,
            'destinations' => [['type' => 'login'], ['type' => 'email', 'email' => 'buh@example.com']],
            'changed_by_client' => true,
        ]);

        $this->revoke($this->document());

        $letter = $this->letters()->sole();
        $this->assertSame([self::MANAGER_EMAIL], (array) $letter->to);
    }

    #[Test]
    public function отзыв_документа_старше_порога_письма_не_даёт(): void
    {
        $document = $this->document(['date' => today()->subDays(self::MAX_AGE_DAYS + 1)]);

        $this->revoke($document);

        // Срез касается только письма: сам документ отозван как обычно.
        $this->assertSoftDeleted('printed_documents', ['id' => $document->id]);
        $this->assertCount(0, $this->letters());
        Mail::assertNothingSent();
    }

    #[Test]
    public function документ_возрастом_ровно_в_порог_ещё_новость(): void
    {
        $this->revoke($this->document(['date' => today()->subDays(self::MAX_AGE_DAYS)]));

        $this->assertCount(1, $this->letters());
    }

    #[Test]
    public function повторная_доставка_того_же_сообщения_второго_письма_не_даёт(): void
    {
        $document = $this->document();

        $this->revoke($document, ['message_id' => 'msg-revoke-1']);
        $this->revoke($document, ['message_id' => 'msg-revoke-1']);

        $this->assertCount(1, $this->letters());
    }

    #[Test]
    public function повторный_отзыв_новым_сообщением_второго_письма_не_даёт(): void
    {
        $document = $this->document();

        // 1С перепубликовала отзыв: другой message_id, ревизия выше. Первое письмо
        // уже ушло, склейка по окну его не поймает — защищает сам обработчик.
        $this->revoke($document, ['message_id' => 'msg-revoke-1', 'revision' => 2]);
        $this->revoke($document, ['message_id' => 'msg-revoke-2', 'revision' => 3]);

        $letters = $this->letters();
        $this->assertCount(1, $letters);
        $this->assertSame(EmailStatus::SENT, $letters->first()->status);
    }

    #[Test]
    public function документ_без_партнёра_отзывается_без_исключения_и_без_письма(): void
    {
        // Контрагент на сайт ещё не выгружен: ни партнёра, ни менеджера.
        $document = PrintedDocument::factory()->unmatched()->create(['date' => today()->subDay()]);

        $this->revoke($document);

        $this->assertSoftDeleted('printed_documents', ['id' => $document->id]);
        $this->assertCount(0, $this->letters());
        Mail::assertNothingSent();
    }

    #[Test]
    public function партнёр_без_менеджера_отзывается_без_исключения_и_клиенту_ничего_не_уходит(): void
    {
        $this->client->forceFill(['personal_manager_id' => null])->save();
        $document = $this->document();

        $this->revoke($document);

        $this->assertSoftDeleted('printed_documents', ['id' => $document->id]);
        Mail::assertNothingSent();

        foreach ($this->letters() as $letter) {
            $this->assertSame([], (array) $letter->to);
            $this->assertNotSame(EmailStatus::SENT, $letter->status);
        }
    }

    #[Test]
    public function форма_которую_клиент_не_видел_письма_не_даёт(): void
    {
        // Файл так и не перенесён: в кабинете формы не было, отзывать нечего.
        $document = PrintedDocument::factory()->pending()->create([
            'user_id' => $this->client->id,
            'company_id' => $this->company->id,
            'date' => today()->subDay(),
        ]);

        $this->revoke($document);

        $this->assertSoftDeleted('printed_documents', ['id' => $document->id]);
        $this->assertCount(0, $this->letters());
    }

    /**
     * PDF и XLSX одного УПД: разные `uuid`, один конверт — значит, общий `variant_key`.
     *
     * @return array{0: PrintedDocument, 1: PrintedDocument}
     */
    private function pdfAndXlsx(): array
    {
        $envelope = [
            'number' => '29УТ-008007',
            'shipment_uuid' => 'bd5699dd-b98e-11f1-8948-b00eaec447ca',
            'base_document_kind' => 'shipment',
            'contractor_uuid' => '0b0e7a52-1c1e-11ee-8d74-ac62e19dfb78',
            'organization_uuid' => 'e043aa01-2777-11ed-8d74-ac62e19dfb78',
        ];

        $pdf = $this->document($envelope);
        $xlsx = $this->document($envelope + [
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);

        $this->assertNotNull($pdf->variant_key);
        $this->assertSame($pdf->variant_key, $xlsx->variant_key);

        return [$pdf, $xlsx];
    }

    #[Test]
    public function отзыв_одного_формата_при_живом_втором_письма_не_даёт(): void
    {
        [$pdf, $xlsx] = $this->pdfAndXlsx();

        $this->revoke($xlsx);

        // Excel ушёл, PDF остался: документ в кабинете жив, писать менеджеру не о чем.
        $this->assertSoftDeleted('printed_documents', ['id' => $xlsx->id]);
        $this->assertNotSoftDeleted('printed_documents', ['id' => $pdf->id]);
        $this->assertCount(0, $this->letters());
        Mail::assertNothingSent();
    }

    #[Test]
    public function отзыв_последней_живой_формы_пары_даёт_одно_письмо(): void
    {
        [$pdf, $xlsx] = $this->pdfAndXlsx();

        $this->revoke($xlsx);
        $this->assertCount(0, $this->letters());

        $this->revoke($pdf);

        $letter = $this->letters()->sole();
        $this->assertSame([self::MANAGER_EMAIL], (array) $letter->to);
        $this->assertSame($pdf->id, (int) $letter->related_id);
    }

    #[Test]
    public function выключенный_в_карточке_партнёра_тип_молчит(): void
    {
        $this->actingAs($this->manager)
            ->patchJson(route('crm.clients.notifications.update', $this->client), [
                'occasion_key' => 'documents.deleted',
                'is_enabled' => false,
                'destinations' => [],
            ])
            ->assertOk();

        $this->revoke($this->document());

        $this->assertCount(0, $this->letters());
    }

    #[Test]
    public function в_карточке_партнёра_адресат_закреплён_и_клиенту_не_переадресуется(): void
    {
        $rows = collect($this->actingAs($this->manager)
            ->patchJson(route('crm.clients.notifications.update', $this->client), [
                'occasion_key' => 'documents.deleted',
                'is_enabled' => true,
                'destinations' => [['type' => 'manager'], ['type' => 'login']],
            ])
            ->assertOk()
            ->json('rows'))->keyBy('key');

        $row = $rows['documents.deleted'];
        $this->assertTrue($row['destinations_locked']);
        $this->assertFalse($row['client_visible']);
        $this->assertSame(['manager'], array_column($row['destinations'], 'type'));
        // Совпало с умолчанием — строки-отклонения нет.
        $this->assertSame(0, NotificationPreference::query()->where('occasion_key', 'documents.deleted')->count());

        $this->revoke($this->document());

        $this->assertSame([self::MANAGER_EMAIL], (array) $this->letters()->sole()->to);
    }

    #[Test]
    public function в_кабинете_клиента_типа_нет_и_включить_его_нельзя(): void
    {
        $rows = collect($this->actingAs($this->client)
            ->getJson('/cabinet/notifications/data')
            ->assertOk()
            ->json('rows'));

        $this->assertNull($rows->firstWhere('key', 'documents.deleted'));

        $this->actingAs($this->client)
            ->patchJson('/cabinet/notifications', [
                'occasion_key' => 'documents.deleted',
                'is_enabled' => true,
                'destinations' => [['type' => 'login']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['occasion_key']);

        $this->assertSame(0, NotificationPreference::query()->count());
    }
}
