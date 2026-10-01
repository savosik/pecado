<?php

namespace Tests\Feature\Notifications;

use App\Enums\PrintedDocumentType;
use App\Enums\UserStatus;
use App\Jobs\StorePrintedDocumentFile;
use App\Models\Company;
use App\Models\CrmEmail;
use App\Models\PersonalManager;
use App\Models\PrintedDocument;
use App\Models\User;
use App\Services\Crm\Mail\Sources\DocumentOccasions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\EnablesClientNotifications;
use Tests\TestCase;

/**
 * О печатных формах старше месяца клиенту не пишем.
 *
 * 30.09.2026 1С догрузила 421 УПД за январь–сентябрь, и подписанные на
 * «опубликован документ» клиенты получили письма о документах девятимесячной
 * давности. Решение заказчика 01.10.2026: «старше 1 месяца не досылаем».
 *
 * Срез считается от даты самого документа, а не от момента загрузки, и касается
 * только письма: в кабинете форма появляется как обычно.
 *
 * Все даты — от today(): календарная константа превратила бы тест в бомбу.
 */
class DocumentPublishedAgeCutoffTest extends TestCase
{
    use EnablesClientNotifications;
    use RefreshDatabase;

    private const MAX_AGE_DAYS = 31;

    private User $client;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('documents-exchange');
        Storage::fake('printed-documents');

        config([
            'documents.enabled' => true,
            'documents.exchange_disk' => 'documents-exchange',
            'documents.disk' => 'printed-documents',
            'documents.notify_max_age_days' => self::MAX_AGE_DAYS,
            'mail_stream.enabled' => true,
            'mail_stream.autosend' => false,
        ]);

        $manager = User::factory()->create();
        $profile = PersonalManager::factory()->create(['user_id' => $manager->id]);

        $this->client = User::factory()->create([
            'status' => UserStatus::ACTIVE,
            'must_change_password' => false,
            'personal_manager_id' => $profile->id,
            'email' => 'client@example.com',
        ]);
        $this->company = Company::factory()->create(['user_id' => $this->client->id]);

        $this->enableNotificationsFor($this->client, ['documents.published']);
    }

    /**
     * @param  \Illuminate\Support\Carbon|string|null  $date
     */
    private function document($date, array $attributes = []): PrintedDocument
    {
        return PrintedDocument::factory()->ofType(PrintedDocumentType::UPD)->create(array_merge([
            'user_id' => $this->client->id,
            'company_id' => $this->company->id,
            'date' => $date,
        ], $attributes));
    }

    private function lettersAbout(PrintedDocument $document): int
    {
        return CrmEmail::query()
            ->where('origin_event', 'documents.published')
            ->where('related_type', $document->getMorphClass())
            ->where('related_id', $document->getKey())
            ->count();
    }

    #[Test]
    public function fresh_document_produces_a_letter(): void
    {
        $document = $this->document(today()->subDays(2));

        app(DocumentOccasions::class)->published($document);

        $this->assertSame(1, $this->lettersAbout($document));
    }

    #[Test]
    public function document_older_than_the_threshold_is_silent(): void
    {
        $document = $this->document(today()->subDays(self::MAX_AGE_DAYS + 1));

        app(DocumentOccasions::class)->published($document);

        $this->assertSame(0, $this->lettersAbout($document));
        $this->assertDatabaseMissing('crm_emails', ['origin_event' => 'documents.published']);
    }

    #[Test]
    public function document_exactly_at_the_threshold_still_notifies(): void
    {
        // «Старше месяца» — строго старше: ровно порог ещё новость.
        $document = $this->document(today()->subDays(self::MAX_AGE_DAYS));

        app(DocumentOccasions::class)->published($document);

        $this->assertSame(1, $this->lettersAbout($document));
    }

    #[Test]
    public function document_without_a_date_notifies_as_before(): void
    {
        // Молчать о документе из-за пустого поля хуже, чем написать о старом.
        $document = $this->document(null);

        app(DocumentOccasions::class)->published($document);

        $this->assertSame(1, $this->lettersAbout($document));
    }

    #[Test]
    public function age_is_measured_by_document_date_not_by_arrival(): void
    {
        // Ровно случай 30.09.2026: документ январский, а на сайт приехал сегодня.
        $loadedToday = $this->document(today()->subMonths(8));
        // И обратный: строка лежит давно, а сам документ свежий (доклейка контрагента).
        $linkedLate = $this->document(today()->subDays(5));
        $linkedLate->forceFill(['created_at' => now()->subMinutes(30)])->save();

        $occasions = app(DocumentOccasions::class);
        $occasions->published($loadedToday);
        $occasions->published($linkedLate);

        $this->assertSame(0, $this->lettersAbout($loadedToday));
        $this->assertSame(1, $this->lettersAbout($linkedLate));
    }

    #[Test]
    public function threshold_is_taken_from_config_and_zero_disables_the_cutoff(): void
    {
        $document = $this->document(today()->subDays(10));

        config(['documents.notify_max_age_days' => 7]);
        app(DocumentOccasions::class)->published($document);
        $this->assertSame(0, $this->lettersAbout($document));

        $ancient = $this->document(today()->subYear());

        config(['documents.notify_max_age_days' => 0]);
        app(DocumentOccasions::class)->published($ancient);
        $this->assertSame(1, $this->lettersAbout($ancient));
    }

    #[Test]
    public function old_document_is_still_published_in_the_cabinet(): void
    {
        // Сквозной путь исторической догрузки: файл из обменного бакета переносится,
        // форма видна клиенту, письма нет.
        $sourcePath = '2026/history/old-upd.pdf';
        Storage::disk('documents-exchange')->put($sourcePath, '%PDF-1.7 историческая догрузка');

        $document = PrintedDocument::factory()->ofType(PrintedDocumentType::UPD)->pending()->create([
            'user_id' => $this->client->id,
            'company_id' => $this->company->id,
            'date' => today()->subMonths(6),
            'source_url' => 's3://documents-exchange/'.$sourcePath,
        ]);

        (new StorePrintedDocumentFile($document->id, $document->source_url))->handle();

        $document->refresh();
        $this->assertSame(PrintedDocument::FILE_STORED, $document->file_status);
        Storage::disk('printed-documents')->assertExists($document->path);

        $this->actingAs($this->client)
            ->get('/cabinet/documents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('documents.data', 1)
                ->where('documents.data.0.id', $document->id));

        $this->assertSame(0, $this->lettersAbout($document));
    }

    #[Test]
    public function fresh_document_notifies_through_the_storage_job(): void
    {
        $sourcePath = '2026/fresh/new-upd.pdf';
        Storage::disk('documents-exchange')->put($sourcePath, '%PDF-1.7 свежая реализация');

        $document = PrintedDocument::factory()->ofType(PrintedDocumentType::UPD)->pending()->create([
            'user_id' => $this->client->id,
            'company_id' => $this->company->id,
            'date' => today()->subDay(),
            'source_url' => 's3://documents-exchange/'.$sourcePath,
        ]);

        (new StorePrintedDocumentFile($document->id, $document->source_url))->handle();

        $this->assertSame(PrintedDocument::FILE_STORED, $document->fresh()->file_status);
        $this->assertSame(1, $this->lettersAbout($document));
    }
}
