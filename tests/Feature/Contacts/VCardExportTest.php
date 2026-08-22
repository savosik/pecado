<?php

namespace Tests\Feature\Contacts;

use App\Enums\ContactRole;
use App\Models\Company;
use App\Models\Contact;
use App\Models\ContactLink;
use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Выгрузка контактов в телефон.
 *
 * Проверяется не «файл отдался», а то, из-за чего импорт молча ломается:
 * кодировка, переводы строк, фолдинг, экранирование. Ошибку здесь не видно
 * глазами — телефон просто отказывается открывать файл.
 */
class VCardExportTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-head');
        $profile = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->client = User::factory()->create(['personal_manager_id' => $profile->id]);
    }

    private function download(Contact $contact): string
    {
        $response = $this->actingAs($this->manager)->get(route('crm.contacts.vcard', $contact));
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/vcard; charset=utf-8');

        return $response->streamedContent();
    }

    #[Test]
    public function card_carries_the_fields_a_phone_shows(): void
    {
        $company = Company::factory()->create(['user_id' => $this->client->id, 'name' => 'Ромашка ООО']);

        $contact = Contact::factory()->forClient($this->client)->create([
            'full_name' => 'Афонина Мария Петровна',
            'greeting_name' => 'Мария Петровна',
            'position' => 'Главный бухгалтер',
            'email' => 'buh@romashka.ru',
            'phone' => '+79123456789',
            'telegram' => '@afonina',
            'birthday' => '1980-05-17',
        ]);

        ContactLink::factory()->to($company, ContactRole::ACCOUNTANT)->create(['contact_id' => $contact->id]);

        $vcf = $this->download($contact->fresh(['client', 'links.subject']));

        $this->assertStringContainsString('BEGIN:VCARD', $vcf);
        $this->assertStringContainsString('VERSION:3.0', $vcf);
        $this->assertStringContainsString('FN:Афонина Мария Петровна', $vcf);
        $this->assertStringContainsString('N:Афонина;Мария;Петровна;;', $vcf);
        $this->assertStringContainsString('NICKNAME:Мария Петровна', $vcf);
        $this->assertStringContainsString('ORG:Ромашка ООО', $vcf);
        $this->assertStringContainsString('TITLE:Главный бухгалтер', $vcf);
        $this->assertStringContainsString('TEL;TYPE=CELL,VOICE:+79123456789', $vcf);
        $this->assertStringContainsString('EMAIL;TYPE=INTERNET,WORK:buh@romashka.ru', $vcf);
        $this->assertStringContainsString('BDAY:1980-05-17', $vcf);
        $this->assertStringContainsString('https://t.me/afonina', $vcf);
        $this->assertStringContainsString('UID:pecado-contact-'.$contact->id, $vcf);
        $this->assertStringContainsString('END:VCARD', $vcf);
    }

    #[Test]
    public function file_has_no_bom_and_uses_crlf(): void
    {
        // В CSV для Excel BOM обязателен, в vCard запрещён — перепутать легко,
        // а телефон на такой файл ругается непонятно.
        $contact = Contact::factory()->forClient($this->client)->create();

        $vcf = $this->download($contact->fresh(['client', 'links.subject']));

        $this->assertStringStartsWith('BEGIN:VCARD', $vcf);
        $this->assertStringNotContainsString("\xEF\xBB\xBF", $vcf);
        $this->assertStringContainsString("\r\n", $vcf);
        $this->assertSame(0, preg_match('/(?<!\r)\n/', $vcf), 'В файле есть перевод строки без CR');
    }

    #[Test]
    public function long_line_is_folded_without_breaking_a_letter(): void
    {
        // Кириллица в UTF-8 занимает два байта: разрез посреди символа даст
        // на телефоне кашу вместо фамилии.
        $contact = Contact::factory()->forClient($this->client)->create([
            'full_name' => str_repeat('Длиннофамильная ', 8).'Мария',
        ]);

        $vcf = $this->download($contact->fresh(['client', 'links.subject']));

        foreach (explode("\r\n", $vcf) as $line) {
            $this->assertLessThanOrEqual(76, strlen($line), 'Строка длиннее допустимого: '.$line);
        }

        // Развернём фолдинг обратно — имя должно собраться целиком.
        $unfolded = str_replace("\r\n ", '', $vcf);
        $this->assertStringContainsString('FN:'.str_repeat('Длиннофамильная ', 8).'Мария', $unfolded);
        $this->assertStringNotContainsString('?', $unfolded);
    }

    #[Test]
    public function birthday_without_a_year_keeps_the_day(): void
    {
        $contact = Contact::factory()->forClient($this->client)->birthdayWithoutYear()->create();

        $vcf = $this->download($contact->fresh(['client', 'links.subject']));

        $this->assertStringContainsString('BDAY:--0517', $vcf);
        $this->assertStringNotContainsString('BDAY:1900', $vcf);
    }

    #[Test]
    public function separators_inside_values_are_escaped(): void
    {
        $contact = Contact::factory()->forClient($this->client)->create([
            'full_name' => 'Иванов Пётр',
            'position' => 'Директор, он же собственник; лично',
        ]);

        $vcf = $this->download($contact->fresh(['client', 'links.subject']));

        $this->assertStringContainsString('TITLE:Директор\\, он же собственник\; лично', $vcf);
    }

    #[Test]
    public function batch_download_has_no_photos_by_default(): void
    {
        Contact::factory()->count(3)->forClient($this->client)->create();

        $response = $this->actingAs($this->manager)->get(route('crm.contacts.vcf'));
        $response->assertOk();

        $vcf = $response->streamedContent();

        $this->assertSame(3, substr_count($vcf, 'BEGIN:VCARD'));
        $this->assertStringNotContainsString('PHOTO;', $vcf);
    }

    #[Test]
    public function batch_download_respects_the_list_filters(): void
    {
        Contact::factory()->forClient($this->client)->create(['full_name' => 'Работает']);
        Contact::factory()->forClient($this->client)->inactive()->create(['full_name' => 'Уволился']);

        $vcf = $this->actingAs($this->manager)
            ->get(route('crm.contacts.vcf'))
            ->streamedContent();

        $this->assertStringContainsString('Работает', $vcf);
        $this->assertStringNotContainsString('Уволился', $vcf);
    }

    #[Test]
    public function foreign_contact_is_not_downloadable(): void
    {
        $stranger = User::factory()->create();
        $foreign = Contact::factory()->create(['client_user_id' => $stranger->id]);

        $manager = User::factory()->create();
        $manager->assignRole('sales-manager');
        PersonalManager::factory()->create(['user_id' => $manager->id]);

        $this->actingAs($manager)
            ->get(route('crm.contacts.vcard', $foreign))
            ->assertNotFound();
    }
}
