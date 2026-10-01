<?php

namespace Tests\Feature\Notifications;

use App\Enums\PrintedDocumentType;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\CrmEmail;
use App\Models\PersonalManager;
use App\Models\PrintedDocument;
use App\Models\User;
use App\Services\Crm\Mail\Sources\DocumentOccasions;
use App\Services\Erp\Handlers\HandlePrintedDocumentDeleted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\EnablesClientNotifications;
use Tests\TestCase;

/**
 * Об отзыве печатных форм старше месяца клиенту не пишем.
 *
 * Продолжение среза для публикации (`DocumentPublishedAgeCutoffTest`). Решение
 * заказчика 02.10.2026: правило «о документах старше 31 дня не уведомляем»
 * действует и для письма «Документ отозван».
 *
 * Срез касается только письма: сам документ отзывается как обычно.
 *
 * Все даты — от today(): календарная константа превратила бы тест в бомбу.
 */
class DocumentDeletedAgeCutoffTest extends TestCase
{
    use EnablesClientNotifications;
    use RefreshDatabase;

    private const MAX_AGE_DAYS = 31;

    private User $client;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'documents.enabled' => true,
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

        $this->enableNotificationsFor($this->client, ['documents.deleted']);
    }

    /**
     * @param  \Illuminate\Support\Carbon|string|null  $date
     */
    private function document($date): PrintedDocument
    {
        return PrintedDocument::factory()->ofType(PrintedDocumentType::UPD)->create([
            'user_id' => $this->client->id,
            'company_id' => $this->company->id,
            'date' => $date,
        ]);
    }

    private function lettersAbout(PrintedDocument $document): int
    {
        return CrmEmail::query()
            ->where('origin_event', 'documents.deleted')
            ->where('related_type', $document->getMorphClass())
            ->where('related_id', $document->getKey())
            ->count();
    }

    #[Test]
    public function revoking_a_fresh_document_produces_a_letter(): void
    {
        $document = $this->document(today()->subDays(2));

        app(DocumentOccasions::class)->deleted($document);

        $this->assertSame(1, $this->lettersAbout($document));
    }

    #[Test]
    public function revoking_a_document_older_than_the_threshold_is_silent(): void
    {
        $document = $this->document(today()->subDays(self::MAX_AGE_DAYS + 1));

        // Отзыв приходит из 1С: документ отозван как обычно, срез касается только письма.
        app(HandlePrintedDocumentDeleted::class)->handle(['uuid' => $document->uuid]);
        $this->assertSoftDeleted('printed_documents', ['id' => $document->id]);

        app(DocumentOccasions::class)->deleted($document->fresh());

        $this->assertSame(0, $this->lettersAbout($document));
        $this->assertDatabaseMissing('crm_emails', ['origin_event' => 'documents.deleted']);
    }

    #[Test]
    public function revoking_a_document_exactly_at_the_threshold_still_notifies(): void
    {
        // «Старше месяца» — строго старше: ровно порог ещё новость.
        $document = $this->document(today()->subDays(self::MAX_AGE_DAYS));

        app(DocumentOccasions::class)->deleted($document);

        $this->assertSame(1, $this->lettersAbout($document));
    }

    #[Test]
    public function revoking_a_document_without_a_date_notifies_as_before(): void
    {
        // Молчать об отзыве из-за пустого поля хуже, чем написать о старом документе.
        $document = $this->document(null);

        app(DocumentOccasions::class)->deleted($document);

        $this->assertSame(1, $this->lettersAbout($document));
    }

    #[Test]
    public function threshold_is_shared_with_publication_and_zero_disables_the_cutoff(): void
    {
        $document = $this->document(today()->subDays(10));

        config(['documents.notify_max_age_days' => 7]);
        app(DocumentOccasions::class)->deleted($document);
        $this->assertSame(0, $this->lettersAbout($document));

        $ancient = $this->document(today()->subYear());

        config(['documents.notify_max_age_days' => 0]);
        app(DocumentOccasions::class)->deleted($ancient);
        $this->assertSame(1, $this->lettersAbout($ancient));
    }
}
