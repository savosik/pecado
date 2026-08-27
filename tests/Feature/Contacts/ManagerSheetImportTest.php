<?php

namespace Tests\Feature\Contacts;

use App\Enums\ContactRole;
use App\Enums\ContactSource;
use App\Enums\Crm\PaymentType;
use App\Models\Company;
use App\Models\Contact;
use App\Models\ContactLink;
use App\Models\CrmComment;
use App\Models\User;
use App\Services\Contacts\ManagerSheetImporter;
use App\Support\PhoneFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Перенос таблицы менеджеров: люди по местам, условия в паспорт, прогноз в ленту.
 */
class ManagerSheetImportTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->author = User::factory()->create(['email' => 'b2b@pecado.ru']);
        $this->client = User::factory()->create([
            'name' => 'ИП Зольников',
            'erp_name' => 'Зольников Владимир Алексеевич ИП, г.Липецк',
            'erp_id' => 'erp-zolnikov',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function document(array $rowOverrides = []): array
    {
        return [
            'exported_at' => '2026-08-27',
            'rows' => [array_merge([
                'line' => 35,
                'manager' => 'kurochkina',
                'client' => 'Зольников Владимир Алексеевич ИП, г.Липецк',
                'status' => 'SILVER 30% и 13%',
                'new_status' => 'GOLD 35% и 15%',
                'payment' => 'отсрочка 30 к. дней',
                'docs' => '1 УПД',
                'comment' => 'Отгрузки на ИП Зольникова Валери.',
                'forecast' => 'по потребности',
                'doc_emails' => ['buh@example.com'],
                'contacts' => [[
                    'full_name' => 'Зольникова Валери',
                    'greeting_name' => 'Валери',
                    'role' => 'owner',
                    'phone' => '8 (909) 951-66-68',
                    'skype' => 'live:valeri',
                    'primary' => true,
                ]],
            ], $rowOverrides)],
        ];
    }

    private function import(array $document, bool $dryRun = false, bool $overwrite = false): \App\Support\Contacts\ManagerSheetReport
    {
        return app(ManagerSheetImporter::class)->import($document, ['kurochkina' => $this->author], $dryRun, $overwrite);
    }

    #[Test]
    public function person_lands_in_directory_with_normalized_phone_and_partner_link(): void
    {
        $report = $this->import($this->document());

        $this->assertSame(1, $report->rowsMatched);
        $this->assertSame(1, $report->contactsCreated);

        $contact = Contact::query()->firstOrFail();

        $this->assertSame('+7 909 951-66-68', $contact->phone);
        $this->assertSame('79099516668', $contact->phone_digits);
        $this->assertSame('Skype: live:valeri', $contact->notes);
        $this->assertSame(ContactSource::MANAGER_SHEET, $contact->source);
        $this->assertSame($this->client->id, $contact->client_user_id);
        $this->assertSame($this->author->id, $contact->created_by_user_id);

        $link = ContactLink::query()->where('subject_type', User::class)->firstOrFail();
        $this->assertSame(ContactRole::OWNER, $link->role);
        $this->assertTrue($link->is_primary);
    }

    #[Test]
    public function owner_is_linked_to_the_matching_contractor(): void
    {
        $company = Company::factory()->create([
            'user_id' => $this->client->id,
            'name' => 'Зольникова Валери ИП',
            'legal_name' => 'ИП Зольникова Валери',
        ]);
        Company::factory()->create(['user_id' => $this->client->id, 'name' => 'Ромашка ООО']);

        $this->import($this->document());

        $this->assertDatabaseHas('contact_links', [
            'subject_type' => Company::class,
            'subject_id' => $company->id,
            'role' => ContactRole::OWNER->value,
            'client_user_id' => $this->client->id,
        ]);

        // Подсказка ведёт к чужому по имени юрлицу — роль остаётся ролью человека, не «собственник».
        $this->import($this->document(['contacts' => [[
            'full_name' => 'Юлия Петрова', 'role' => 'buyer', 'phone' => '+7 900 000-00-01', 'company_hint' => 'Зольникова',
        ]]]));

        $this->assertDatabaseHas('contact_links', [
            'subject_type' => Company::class,
            'subject_id' => $company->id,
            'role' => ContactRole::BUYER->value,
        ]);
    }

    #[Test]
    public function terms_go_to_passport_and_forecast_to_timeline(): void
    {
        $this->import($this->document());

        $profile = $this->client->crmProfile()->firstOrFail();

        $this->assertSame('отсрочка 30 к. дней', $profile->payment_terms);
        $this->assertSame(PaymentType::DEFERRED, $profile->payment_type);
        $this->assertSame(30, $profile->deferral_days);
        $this->assertStringContainsString(ManagerSheetImporter::NOTES_MARKER.' (27.08.2026)', (string) $profile->notes_md);
        $this->assertStringContainsString('SILVER 30% и 13% → GOLD 35% и 15%', (string) $profile->notes_md);
        $this->assertStringContainsString('buh@example.com', (string) $profile->notes_md);
        $this->assertStringContainsString('Отгрузки на ИП Зольникова Валери.', (string) $profile->notes_md);

        $comment = CrmComment::query()->firstOrFail();
        $this->assertSame($this->client->id, $comment->commentable_id);
        $this->assertSame($this->author->id, $comment->user_id);
        $this->assertStringContainsString('по потребности', $comment->body);
    }

    #[Test]
    public function second_run_changes_nothing(): void
    {
        $this->import($this->document());
        $report = $this->import($this->document());

        $this->assertSame(0, $report->contactsCreated);
        $this->assertSame(1, $report->contactsUpdated);
        $this->assertSame(0, $report->linksCreated);
        $this->assertSame(0, $report->commentsCreated);
        $this->assertSame(0, $report->profilesUpdated);
        $this->assertSame(1, Contact::query()->count());
        $this->assertSame(1, CrmComment::query()->count());
        $this->assertSame(1, substr_count((string) $this->client->crmProfile()->value('notes_md'), ManagerSheetImporter::NOTES_MARKER));
    }

    #[Test]
    public function hand_written_fields_are_kept_unless_overwrite(): void
    {
        Contact::factory()->forClient($this->client)->create([
            'full_name' => 'Зольникова Валери',
            'phone' => '+7 909 951-66-68',
            'position' => 'Директор по всему',
            'email' => 'valeri@example.com',
        ]);
        $this->client->crmProfile()->create(['payment_terms' => 'по договору', 'notes_md' => 'Ручная заметка.']);

        $this->import($this->document(['contacts' => [[
            'full_name' => 'Зольникова Валери', 'role' => 'owner', 'phone' => '+7 909 951-66-68', 'position' => 'Владелец',
        ]]]));

        $contact = Contact::query()->firstOrFail();
        $this->assertSame('Директор по всему', $contact->position);
        $this->assertSame('valeri@example.com', $contact->email);

        $profile = $this->client->crmProfile()->firstOrFail();
        $this->assertSame('по договору', $profile->payment_terms);
        $this->assertStringStartsWith('Ручная заметка.', (string) $profile->notes_md);
        $this->assertStringContainsString(ManagerSheetImporter::NOTES_MARKER, (string) $profile->notes_md);

        $this->import($this->document(['contacts' => [[
            'full_name' => 'Зольникова Валери', 'role' => 'owner', 'phone' => '+7 909 951-66-68', 'position' => 'Владелец',
        ]]]), overwrite: true);

        $this->assertSame('Владелец', Contact::query()->firstOrFail()->position);
        $this->assertSame('отсрочка 30 к. дней', $this->client->crmProfile()->value('payment_terms'));
    }

    #[Test]
    public function unknown_partner_is_reported_not_guessed(): void
    {
        User::factory()->create(['erp_name' => 'Зольников Владимир Алексеевич ИП', 'erp_id' => 'erp-twin']);

        $report = $this->import($this->document());

        $this->assertSame(0, $report->rowsMatched);
        $this->assertCount(1, $report->ambiguous);
        $this->assertSame(0, Contact::query()->count());

        $report = $this->import($this->document(['client' => 'Неизвестный ИП, г. Нигде']));

        $this->assertCount(1, $report->unmatched);

        // Явный client_id в строке решает неоднозначность и незнакомое имя.
        $report = $this->import($this->document(['client' => 'Неизвестный ИП, г. Нигде', 'client_id' => $this->client->id]));

        $this->assertSame(1, $report->rowsMatched);
        $this->assertSame($this->client->id, Contact::query()->firstOrFail()->client_user_id);
    }

    #[Test]
    public function unmatched_row_people_become_orphans_only_when_asked(): void
    {
        $row = ['client' => 'Славный Сергей Сергеевич, г.Бишкек', 'payment' => 'предоплата', 'forecast' => 'сентябрь', 'contacts' => [[
            'full_name' => 'Славный Сергей Сергеевич', 'role' => 'owner', 'phone' => '+996 555 153 014',
        ]]];

        $this->import($this->document($row));
        $this->assertSame(0, Contact::query()->count());

        $report = app(ManagerSheetImporter::class)->import($this->document($row), ['kurochkina' => $this->author], orphans: true);
        $this->assertSame(1, $report->orphansCreated);

        $contact = Contact::query()->firstOrFail();
        $this->assertNull($contact->client_user_id);
        $this->assertSame('+996 555 153-014', $contact->phone);
        $this->assertSame($this->author->id, $contact->created_by_user_id);
        $this->assertStringContainsString('Славный Сергей Сергеевич, г.Бишкек — в базе сайта не найден', (string) $contact->notes);
        $this->assertStringContainsString('Условия оплаты: предоплата', (string) $contact->notes);
        $this->assertStringContainsString('Прогноз заказа: сентябрь', (string) $contact->notes);
        $this->assertSame(0, ContactLink::query()->count());

        $report = app(ManagerSheetImporter::class)->import($this->document($row), ['kurochkina' => $this->author], orphans: true);
        $this->assertSame(0, $report->orphansCreated);
        $this->assertSame(1, $report->orphansUpdated);
        $this->assertSame(1, Contact::query()->count());
        $this->assertSame(1, substr_count((string) Contact::query()->firstOrFail()->notes, 'в базе сайта не найден'));
    }

    #[Test]
    public function short_names_are_completed_from_the_partner_card(): void
    {
        $this->import($this->document(['contacts' => [
            ['full_name' => 'Владимир Зольников', 'role' => 'manager', 'phone' => '+7 900 000-00-01'],
            ['full_name' => 'Владимир', 'role' => 'owner', 'phone' => '+7 900 000-00-02'],
            ['full_name' => 'Владимир', 'role' => 'manager', 'phone' => '+7 900 000-00-03'],
            ['full_name' => 'Ольга', 'role' => 'owner', 'phone' => '+7 900 000-00-04'],
        ]]));

        // «Владимир Зольников» и собственник «Владимир» — один человек: после
        // достройки имени второй нашёл первого и дополнил его, а не задвоил.
        $names = Contact::query()->orderBy('phone')->pluck('full_name')->all();

        $this->assertSame(['Зольников Владимир Алексеевич', 'Владимир', 'Ольга'], $names);
    }

    #[Test]
    public function dry_run_counts_but_writes_nothing(): void
    {
        $report = $this->import($this->document(), dryRun: true);

        $this->assertSame(1, $report->contactsCreated);
        $this->assertSame(1, $report->commentsCreated);
        $this->assertSame(0, Contact::query()->count());
        $this->assertSame(0, CrmComment::query()->count());
    }

    #[Test]
    public function phones_are_formatted_per_country_and_incomplete_ones_kept_as_is(): void
    {
        $this->assertSame('+7 926 353-80-95', PhoneFormatter::format('8 (926) 353-80-95'));
        $this->assertSame('+7 930 330-32-55', PhoneFormatter::format('7 930 330-32-55'));
        $this->assertSame('+375 33 651-45-35', PhoneFormatter::format('WA+375 33 651-45-35'));
        $this->assertSame('+998 88 333-57-75', PhoneFormatter::format('+998 883335775'));
        $this->assertSame('+992 918 43-16-55', PhoneFormatter::format('+992 918 43 1655'));
        $this->assertSame('+996 555 153-014', PhoneFormatter::format('+996 555 153 014'));
        $this->assertSame('+993 61 16-44-39', PhoneFormatter::format('+993 61 164439'));
        $this->assertSame('+420 723 653-081', PhoneFormatter::format('+420 723 653 081'));
        $this->assertSame('+7 927 298-70-5', PhoneFormatter::format('+7 927 298-70-5'));
        $this->assertNull(PhoneFormatter::format('  '));
    }
}
