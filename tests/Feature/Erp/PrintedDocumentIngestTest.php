<?php

namespace Tests\Feature\Erp;

use App\Enums\PrintedDocumentType;
use App\Jobs\StorePrintedDocumentFile;
use App\Models\Company;
use App\Models\Organization;
use App\Models\PrintedDocument;
use App\Models\Shipment;
use App\Models\User;
use App\Queue\Jobs\ErpIncomingJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Приём печатных форм из 1С (v16.1.0).
 *
 * Сообщения прогоняются через ErpIncomingJob, а не прямым вызовом обработчика:
 * только так проверяется runtime-валидация JSON Schema, дедупликация по
 * message_id и отсечение обогнавших ревизий — то есть весь путь, по которому
 * документ реально попадает в базу.
 */
class PrintedDocumentIngestTest extends TestCase
{
    use RefreshDatabase;

    private const UUID = '3f2504e0-4f89-11d3-9a0c-0305e82c3301';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents-exchange');
        Storage::fake('printed-documents');

        config([
            'documents.exchange_disk' => 'documents-exchange',
            'documents.disk' => 'printed-documents',
        ]);
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
     * Сообщение с файлом в обменном бакете: кладём PDF и прогоняем приём вместе
     * с переносом файла (очередь синхронная в тестах).
     */
    private function publish(array $overrides = [], ?string $content = "%PDF-1.7\nтест"): array
    {
        $payload = array_merge([
            'event' => 'printed_document.published',
            'message_id' => 'msg-'.uniqid(),
            'timestamp' => now()->toIso8601String(),
            'revision' => 1,
            'uuid' => self::UUID,
            'type_code' => 'tax_invoice',
            'type_name' => 'Счёт-фактура',
            'number' => '29УТ-002488',
            'date' => '2026-08-12',
            'file_url' => 's3://documents-exchange/2026/08/'.self::UUID.'.pdf',
            'file_name' => 'Счет-фактура_29УТ-002488.pdf',
            'mime_type' => 'application/pdf',
        ], $overrides);

        if ($content !== null) {
            $path = $this->pathFromUrl($payload['file_url']);
            Storage::disk('documents-exchange')->put($path, $content);
        }

        $this->makeJob($payload)->fire();

        return $payload;
    }

    private function pathFromUrl(string $url): string
    {
        $withoutScheme = substr($url, strlen('s3://'));

        return substr($withoutScheme, strpos($withoutScheme, '/') + 1);
    }

    #[Test]
    public function published_creates_document_and_moves_file_to_private_disk(): void
    {
        $user = User::factory()->create(['erp_id' => 'partner-uuid-1']);
        $company = Company::factory()->create(['user_id' => $user->id, 'erp_id' => 'contractor-uuid-1']);
        $organization = Organization::factory()->create(['external_id' => 'org-uuid-1']);
        $shipment = Shipment::factory()->create(['uuid' => 'shipment-uuid-1', 'user_id' => $user->id]);

        $this->publish([
            'partner_uuid' => 'partner-uuid-1',
            'contractor_uuid' => 'contractor-uuid-1',
            'organization_uuid' => 'org-uuid-1',
            'shipment_uuid' => 'shipment-uuid-1',
            'base_document_kind' => 'shipment',
        ]);

        $document = PrintedDocument::where('uuid', self::UUID)->firstOrFail();

        $this->assertSame(PrintedDocumentType::TAX_INVOICE, $document->type);
        $this->assertSame('29УТ-002488', $document->number);
        $this->assertSame($company->id, $document->company_id);
        $this->assertSame($user->id, $document->user_id);
        $this->assertSame($organization->id, $document->organization_id);
        $this->assertSame($shipment->id, $document->shipment_id);

        $this->assertSame(PrintedDocument::FILE_STORED, $document->file_status);
        $this->assertSame('2026/08/'.self::UUID.'.pdf', $document->path);
        Storage::disk('printed-documents')->assertExists($document->path);

        // Обменный бакет — транспорт, а не хранилище.
        Storage::disk('documents-exchange')->assertMissing('2026/08/'.self::UUID.'.pdf');
    }

    #[Test]
    public function repeated_message_id_is_ignored(): void
    {
        $payload = $this->publish();

        // Тот же message_id со сдвинутой ревизией: если дедупликация не сработает,
        // документ обновится и это будет видно по номеру.
        Storage::disk('documents-exchange')->put('2026/08/'.self::UUID.'.pdf', '%PDF-1.7 второй');
        $this->makeJob(array_merge($payload, ['number' => 'ДРУГОЙ-НОМЕР', 'revision' => 2]))->fire();

        $this->assertSame(1, PrintedDocument::count());
        $this->assertSame('29УТ-002488', PrintedDocument::first()->number);
    }

    #[Test]
    public function reissue_updates_same_document_and_overwrites_file(): void
    {
        $this->publish();

        $this->publish([
            'message_id' => 'msg-reissue',
            'revision' => 2,
            'number' => '29УТ-002488',
            'title' => 'Счёт-фактура № 29УТ-002488 от 12.08.2026 (исправлен)',
        ], "%PDF-1.7\nисправленная версия");

        $this->assertSame(1, PrintedDocument::count());

        $document = PrintedDocument::firstOrFail();
        $this->assertSame('Счёт-фактура № 29УТ-002488 от 12.08.2026 (исправлен)', $document->title);
        $this->assertSame(2, $document->version, 'Перезапись файла должна поднимать счётчик версий');
        $this->assertStringContainsString(
            'исправленная версия',
            Storage::disk('printed-documents')->get($document->path),
        );
    }

    #[Test]
    public function identical_file_is_not_copied_again(): void
    {
        $this->publish();
        $versionAfterFirst = PrintedDocument::firstOrFail()->version;

        // Тот же байт-в-байт файл: реквизиты поправили, содержимое нет.
        $this->publish(['message_id' => 'msg-same-file', 'revision' => 2, 'number' => '29УТ-002489']);

        $document = PrintedDocument::firstOrFail();
        $this->assertSame('29УТ-002489', $document->number);
        $this->assertSame($versionAfterFirst, $document->version, 'Копирование должно быть пропущено по совпавшему хешу');
    }

    #[Test]
    public function stale_revision_does_not_replace_fresh_document(): void
    {
        $this->publish(['revision' => 5, 'number' => 'СВЕЖИЙ']);

        $this->publish([
            'message_id' => 'msg-stale',
            'revision' => 3,
            'number' => 'УСТАРЕВШИЙ',
        ], "%PDF-1.7\nстарая версия");

        $document = PrintedDocument::firstOrFail();
        $this->assertSame('СВЕЖИЙ', $document->number);
        $this->assertStringNotContainsString(
            'старая версия',
            Storage::disk('printed-documents')->get($document->path),
        );
    }

    #[Test]
    public function unknown_type_falls_back_to_other_and_keeps_source_code(): void
    {
        $this->publish([
            'type_code' => 'warehouse_receipt',
            'type_name' => 'Складская расписка',
        ]);

        $document = PrintedDocument::firstOrFail();

        $this->assertSame(PrintedDocumentType::OTHER, $document->type);
        $this->assertSame('warehouse_receipt', $document->erp_type_code);
        // Клиенту показывается название из 1С, а не безликое «Прочее».
        $this->assertSame('Складская расписка', $document->type_label);
        $this->assertSame(PrintedDocument::FILE_STORED, $document->file_status);
    }

    #[Test]
    public function russian_type_name_is_recognised(): void
    {
        $this->publish(['type_code' => 'АктСверкиВзаиморасчетов', 'type_name' => 'Акт сверки']);

        $this->assertSame(PrintedDocumentType::RECONCILIATION_ACT, PrintedDocument::firstOrFail()->type);
    }

    #[Test]
    public function missing_file_is_marked_without_throwing(): void
    {
        // Файл в бакет не кладём вовсе.
        $this->publish(content: null);

        $document = PrintedDocument::firstOrFail();

        $this->assertSame(PrintedDocument::FILE_MISSING, $document->file_status);
        $this->assertNull($document->path);
    }

    #[Test]
    public function unknown_format_is_rejected_and_source_removed(): void
    {
        // RTF: офисный формат, который сайт не принимает. С v16.6.0 сигнатура ZIP
        // означает XLSX, поэтому «не тот файл» приходится изображать иначе.
        $this->publish(content: '{\rtf1\ansi обычный текст');

        $document = PrintedDocument::firstOrFail();

        $this->assertSame(PrintedDocument::FILE_REJECTED, $document->file_status);
        Storage::disk('printed-documents')->assertMissing('2026/08/'.self::UUID.'.pdf');
        Storage::disk('documents-exchange')->assertMissing('2026/08/'.self::UUID.'.pdf');
    }

    /**
     * Акт сверки за период приезжает в XLSX (v16.6.0): и формат, и период
     * должны дойти до клиента — по ним он отличает суточные ревизии друг от друга.
     */
    #[Test]
    public function xlsx_reconciliation_act_is_stored_with_period(): void
    {
        $this->publish([
            'type_code' => 'reconciliation_act',
            'type_name' => 'Акт сверки взаимных расчетов',
            'number' => '29УТ-000242',
            'date' => '2026-08-19',
            'period_from' => '2026-01-01',
            'period_to' => '2026-08-19',
            'base_document_kind' => null,
            'file_url' => 's3://documents-exchange/2026/08/'.self::UUID.'.xlsx',
            'file_name' => 'Акт сверки_29УТ-000242.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ], content: "PK\x03\x04".str_repeat('x', 64));

        $document = PrintedDocument::where('uuid', self::UUID)->firstOrFail();

        $this->assertSame(PrintedDocumentType::RECONCILIATION_ACT, $document->type);
        $this->assertSame(PrintedDocument::FILE_STORED, $document->file_status);
        $this->assertSame('2026/08/'.self::UUID.'.xlsx', $document->path);
        Storage::disk('printed-documents')->assertExists($document->path);

        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $document->mime_type,
        );
        $this->assertSame('2026-01-01', $document->period_from->toDateString());
        $this->assertSame('2026-08-19', $document->period_to->toDateString());
        $this->assertSame('01.01.2026 — 19.08.2026', $document->period_label);
        $this->assertStringEndsWith('.xlsx', $document->download_name);
        $this->assertStringContainsString('01.01.2026-19.08.2026', $document->download_name);
    }

    #[Test]
    public function legacy_xls_is_accepted(): void
    {
        $this->publish([
            'type_code' => 'reconciliation_act',
            'mime_type' => 'application/vnd.ms-excel',
        ], content: "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat('x', 64));

        $document = PrintedDocument::firstOrFail();

        $this->assertSame(PrintedDocument::FILE_STORED, $document->file_status);
        $this->assertSame('2026/08/'.self::UUID.'.xls', $document->path);
        $this->assertSame('application/vnd.ms-excel', $document->mime_type);
    }

    /**
     * mime из 1С — заявление, сигнатура — факт. Забытая строка в коде 1С не должна
     * лишать клиента акта, поэтому документ принимается по содержимому.
     */
    #[Test]
    public function mime_mismatch_does_not_lose_document(): void
    {
        $this->publish([
            'type_code' => 'reconciliation_act',
            'mime_type' => 'application/pdf',
        ], content: "PK\x03\x04".str_repeat('x', 64));

        $document = PrintedDocument::firstOrFail();

        $this->assertSame(PrintedDocument::FILE_STORED, $document->file_status);
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $document->mime_type,
        );
        $this->assertSame('2026/08/'.self::UUID.'.xlsx', $document->path);
    }

    /**
     * Переход акта сверки с PDF на XLSX идёт по тому же `uuid`: расширение меняется,
     * а значит меняется и ключ хранения. Старый объект после этого не адресуется
     * ничем и остался бы в приватном бакете навсегда.
     */
    #[Test]
    public function format_change_removes_orphaned_file(): void
    {
        $this->publish([
            'type_code' => 'reconciliation_act',
            'revision' => 1,
        ]);

        $this->assertSame('2026/08/'.self::UUID.'.pdf', PrintedDocument::firstOrFail()->path);
        Storage::disk('printed-documents')->assertExists('2026/08/'.self::UUID.'.pdf');

        $this->publish([
            'type_code' => 'reconciliation_act',
            'revision' => 2,
            'file_url' => 's3://documents-exchange/2026/08/'.self::UUID.'.xlsx',
            'mime_type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ], content: "PK\x03\x04".str_repeat('x', 64));

        $document = PrintedDocument::firstOrFail();

        $this->assertSame('2026/08/'.self::UUID.'.xlsx', $document->path);
        Storage::disk('printed-documents')->assertExists('2026/08/'.self::UUID.'.xlsx');
        Storage::disk('printed-documents')->assertMissing('2026/08/'.self::UUID.'.pdf');
        $this->assertSame(1, PrintedDocument::count());
    }

    #[Test]
    public function oversized_file_is_rejected(): void
    {
        config(['documents.max_file_size' => 10]);

        $this->publish(content: '%PDF-1.7 этот файл длиннее десяти байт');

        $this->assertSame(PrintedDocument::FILE_REJECTED, PrintedDocument::firstOrFail()->file_status);
    }

    #[Test]
    public function bucket_in_file_url_is_ignored(): void
    {
        // 1С указала чужой бакет, путь внутри верный: читаем свой обменный диск.
        Storage::disk('documents-exchange')->put('2026/08/'.self::UUID.'.pdf', '%PDF-1.7 ok');

        $this->makeJob([
            'event' => 'printed_document.published',
            'message_id' => 'msg-wrong-bucket',
            'uuid' => self::UUID,
            'type_code' => 'invoice',
            'date' => '2026-08-12',
            'file_url' => 's3://someone-elses-bucket/2026/08/'.self::UUID.'.pdf',
        ])->fire();

        $this->assertSame(PrintedDocument::FILE_STORED, PrintedDocument::firstOrFail()->file_status);
    }

    #[Test]
    public function document_without_known_contractor_is_kept_with_raw_uuid(): void
    {
        $this->publish(['contractor_uuid' => 'contractor-not-on-site-yet']);

        $document = PrintedDocument::firstOrFail();

        $this->assertNull($document->company_id);
        $this->assertSame('contractor-not-on-site-yet', $document->contractor_uuid);
        $this->assertSame(PrintedDocument::FILE_STORED, $document->file_status);
    }

    #[Test]
    public function unknown_organization_creates_stub(): void
    {
        $this->publish(['organization_uuid' => 'org-uuid-unknown']);

        $document = PrintedDocument::firstOrFail();

        $this->assertNotNull($document->organization_id);
        $this->assertTrue($document->organization->is_stub);
    }

    #[Test]
    public function deleted_soft_deletes_document_but_keeps_file(): void
    {
        $this->publish();
        $path = PrintedDocument::firstOrFail()->path;

        $this->makeJob([
            'event' => 'printed_document.deleted',
            'message_id' => 'msg-deleted',
            'uuid' => self::UUID,
            'revision' => 2,
            'reason' => 'Документ помечен на удаление в 1С',
        ])->fire();

        $this->assertSame(0, PrintedDocument::count());
        $this->assertSame(1, PrintedDocument::withTrashed()->count());
        // Перезалить PDF заново неоткуда — файл переживает отзыв формы.
        Storage::disk('printed-documents')->assertExists($path);
    }

    #[Test]
    public function republish_restores_deleted_document(): void
    {
        $this->publish();

        $this->makeJob([
            'event' => 'printed_document.deleted',
            'message_id' => 'msg-deleted',
            'uuid' => self::UUID,
            'revision' => 2,
        ])->fire();

        $this->publish(['message_id' => 'msg-restore', 'revision' => 3]);

        $this->assertSame(1, PrintedDocument::count(), 'Снятие пометки удаления в 1С — обычная операция');
    }

    #[Test]
    public function message_without_required_fields_is_rejected_by_schema(): void
    {
        $this->makeJob([
            'event' => 'printed_document.published',
            'message_id' => 'msg-broken',
            // нет uuid, type_code, file_url и date
        ])->fire();

        $this->assertSame(0, PrintedDocument::withTrashed()->count());
    }

    #[Test]
    public function store_job_is_dispatched_with_source_url(): void
    {
        \Illuminate\Support\Facades\Bus::fake();

        $this->publish(content: null);

        \Illuminate\Support\Facades\Bus::assertDispatched(
            StorePrintedDocumentFile::class,
            fn (StorePrintedDocumentFile $job) => str_contains($job->fileUrl, self::UUID),
        );
    }
}
