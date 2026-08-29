<?php

namespace Tests\Feature\Contacts;

use App\Enums\ContactRole;
use App\Enums\ContactSource;
use App\Models\Company;
use App\Models\Contact;
use App\Models\ContactLink;
use App\Models\User;
use App\Services\Contacts\ContractorSheetImporter;
use App\Support\Contacts\ContractorSheetReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Перенос таблицы контрагентов партнёра: владельцы ИП в справочник,
 * общие ящики сети — в заметку.
 */
class ContractorSheetImportTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private User $client;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->author = User::factory()->create(['email' => 'opt@pecado.ru']);
        $this->client = User::factory()->create(['name' => 'ООО Гевея', 'email' => 'invoice@geveya.test']);

        $this->company = Company::factory()->create([
            'user_id' => $this->client->getKey(),
            'name' => 'Кошкин Павел Александрович ИП, г.Чебоксары (закрыт)',
            'legal_name' => 'Индивидуальный предприниматель Кошкин Павел Александрович',
            'phone' => '+79161112233',
        ]);
    }

    /**
     * @param  array<string, mixed>  $rowOverrides
     * @return array<string, mixed>
     */
    private function document(array $rowOverrides = [], array $documentOverrides = []): array
    {
        return array_merge([
            'client_id' => $this->client->getKey(),
            'exported_at' => '2026-08-29',
            'source_title' => 'таблица контрагентов «Гевеи»',
            'rows' => [array_merge([
                'line' => 4,
                'contractor' => 'Кошкин Павел Александрович ИП, г.Чебоксары',
                'work_type' => 'директор по франшизам',
                'personal_emails' => ['koshkinpa@gmail.com'],
                'invoice_emails' => ['invoice@geveya.test'],
                'responsible_emails' => ['open@tochka-lubvi.ru'],
            ], $rowOverrides)],
        ], $documentOverrides);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function import(array $document, bool $dryRun = false, bool $overwrite = false): ContractorSheetReport
    {
        return app(ContractorSheetImporter::class)->import($document, $this->author, $dryRun, $overwrite);
    }

    #[Test]
    public function owner_of_contractor_lands_in_directory_with_both_links(): void
    {
        $report = $this->import($this->document());

        $this->assertSame(1, $report->rowsMatched);
        $this->assertSame(1, $report->contactsCreated);
        $this->assertSame(2, $report->linksCreated);

        $contact = Contact::query()->firstOrFail();

        // ФИО выведено из названия ИП — города и пометки в имени человека нет.
        $this->assertSame('Кошкин Павел Александрович', $contact->full_name);
        $this->assertSame('koshkinpa@gmail.com', $contact->email);
        $this->assertSame('+7 916 111-22-33', $contact->phone);
        $this->assertSame(ContactSource::MANAGER_SHEET, $contact->source);
        $this->assertSame($this->client->getKey(), $contact->client_user_id);

        $this->assertSame(ContactRole::OWNER, ContactLink::query()
            ->where('subject_type', Company::class)
            ->where('subject_id', $this->company->getKey())
            ->firstOrFail()->role);

        // У партнёра он контактное лицо: собственником всей сети владелец ИП не является.
        $this->assertSame(ContactRole::MANAGER, ContactLink::query()
            ->where('subject_type', User::class)
            ->where('subject_id', $this->client->getKey())
            ->firstOrFail()->role);
    }

    #[Test]
    public function closed_contractor_leaves_a_dormant_card(): void
    {
        $this->import($this->document(['work_type' => 'закрытое']));

        $this->assertFalse(Contact::query()->firstOrFail()->is_active);
    }

    #[Test]
    public function shared_mailbox_never_becomes_a_person(): void
    {
        $second = Company::factory()->create([
            'user_id' => $this->client->getKey(),
            'name' => 'Гоков Александр Владимирович ИП, г.Краснодар',
            'phone' => '+79094551447',
        ]);

        // Один и тот же адрес записан личной почтой у двух контрагентов — значит,
        // это ящик сети, и человека за ним нет.
        $document = $this->document();
        $document['rows'][0]['personal_emails'] = ['open@tochka-lubvi.ru'];
        $document['rows'][] = [
            'line' => 6,
            'contractor' => 'Гоков Александр Владимирович ИП, г.Краснодар',
            'personal_emails' => ['open@tochka-lubvi.ru'],
        ];

        $report = $this->import($document);

        $this->assertContains('open@tochka-lubvi.ru', $report->sharedEmails);
        $this->assertSame(0, Contact::query()->whereNotNull('email')->count());
        $this->assertSame(2, Contact::query()->count());
        $this->assertNotNull($second->fresh());
    }

    #[Test]
    public function partner_own_mailbox_is_shared_too(): void
    {
        $this->import($this->document(['personal_emails' => ['invoice@geveya.test']]));

        $this->assertNull(Contact::query()->firstOrFail()->email);
    }

    #[Test]
    public function phone_of_several_contractors_is_not_given_to_a_person(): void
    {
        Company::factory()->create([
            'user_id' => $this->client->getKey(),
            'name' => 'Гайдай Оксана Эдуардовна ИП, г.Москва',
            'phone' => '+79161112233',
        ]);

        $this->import($this->document());

        $this->assertNull(Contact::query()->firstOrFail()->phone);
    }

    #[Test]
    public function contractor_data_lands_in_notes(): void
    {
        $this->import($this->document(['note' => 'ТГ: @Vasiley_Zmey']));

        $notes = (string) Contact::query()->firstOrFail()->notes;

        $this->assertStringContainsString(ContractorSheetImporter::NOTES_MARKER, $notes);
        $this->assertStringContainsString('**Тип работы:** директор по франшизам', $notes);
        $this->assertStringContainsString('**Почта для счетов:** invoice@geveya.test', $notes);
        $this->assertStringContainsString('**Почта ответственного за ИП:** open@tochka-lubvi.ru', $notes);
        $this->assertStringContainsString('ТГ: @Vasiley_Zmey', $notes);
    }

    #[Test]
    public function second_run_changes_nothing(): void
    {
        $this->import($this->document());
        $report = $this->import($this->document());

        $this->assertSame(0, $report->contactsCreated);
        $this->assertSame(1, $report->contactsUpdated);
        $this->assertSame(0, $report->linksCreated);
        $this->assertSame(1, Contact::query()->count());
        $this->assertSame(2, ContactLink::query()->count());
        $this->assertSame(1, mb_substr_count((string) Contact::query()->firstOrFail()->notes, ContractorSheetImporter::NOTES_MARKER));
    }

    #[Test]
    public function company_without_a_readable_person_is_reported_not_invented(): void
    {
        Company::factory()->create(['user_id' => $this->client->getKey(), 'name' => 'Никифоров ИО ООО, г.Москва']);

        $report = $this->import($this->document([
            'line' => 52,
            'contractor' => 'Никифоров ИО ООО, г.Москва',
            'personal_emails' => ['doc@dfinance.ru'],
        ]));

        $this->assertSame(0, $report->rowsMatched);
        $this->assertSame([['line' => 52, 'contractor' => 'Никифоров ИО ООО, г.Москва']], $report->withoutPerson);
        $this->assertSame(0, Contact::query()->count());
    }

    #[Test]
    public function closure_marks_do_not_break_matching(): void
    {
        Company::factory()->create([
            'user_id' => $this->client->getKey(),
            'name' => 'ИП Галецкая Елена Олеговна  (закрыт)',
        ]);

        $report = $this->import($this->document([
            'line' => 23,
            'contractor' => 'Галецкая Елена Олеговна ИП, д. Сапроново',
        ]));

        $this->assertSame([], $report->unmatched);
        $this->assertSame('Галецкая Елена Олеговна', Contact::query()->firstOrFail()->full_name);
    }

    #[Test]
    public function unknown_contractor_is_reported(): void
    {
        $report = $this->import($this->document(['contractor' => 'Неизвестный Кто-то ИП, г.Тула']));

        $this->assertSame([['line' => 4, 'contractor' => 'Неизвестный Кто-то ИП, г.Тула']], $report->unmatched);
        $this->assertSame(0, Contact::query()->count());
    }

    #[Test]
    public function dry_run_writes_nothing_but_counts_the_same(): void
    {
        $report = $this->import($this->document(), dryRun: true);

        $this->assertSame(1, $report->contactsCreated);
        $this->assertSame(2, $report->linksCreated);
        $this->assertSame(0, Contact::query()->count());
    }
}
