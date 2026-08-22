<?php

namespace Tests\Feature\Crm;

use App\Enums\ContactRole;
use App\Enums\ContactSource;
use App\Models\Company;
use App\Models\Contact;
use App\Models\ContactLink;
use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Фундамент справочника: карточка человека и его роли при сущностях.
 *
 * Проверяется то, на чём держится вся модель: человек один, ролей много,
 * телефон ищется по цифрам, а чужого человека сотрудник не видит.
 */
class ContactModelTest extends TestCase
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

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $profile = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->client = User::factory()->create(['personal_manager_id' => $profile->id]);
    }

    #[Test]
    public function one_person_holds_many_roles_at_once(): void
    {
        // Ради этого и заведены две таблицы: бухгалтер двух юрлиц одного партнёра —
        // одна карточка, а не два дубля с расходящимися телефонами.
        $contact = Contact::factory()->forClient($this->client)->create(['full_name' => 'Афонина Мария']);

        $first = Company::factory()->create(['user_id' => $this->client->id]);
        $second = Company::factory()->create(['user_id' => $this->client->id]);

        ContactLink::factory()->to($first, ContactRole::ACCOUNTANT)->create(['contact_id' => $contact->id]);
        ContactLink::factory()->to($second, ContactRole::ACCOUNTANT)->create(['contact_id' => $contact->id]);

        $this->assertSame(1, Contact::query()->count());
        $this->assertSame(2, $contact->links()->count());
        $this->assertSame($this->client->id, $contact->links()->first()->client_user_id);
    }

    #[Test]
    public function same_role_at_same_entity_is_not_duplicated(): void
    {
        $contact = Contact::factory()->forClient($this->client)->create();
        $company = Company::factory()->create(['user_id' => $this->client->id]);

        ContactLink::factory()->to($company, ContactRole::ACCOUNTANT)->create(['contact_id' => $contact->id]);

        $this->expectException(QueryException::class);

        ContactLink::factory()->to($company, ContactRole::ACCOUNTANT)->create(['contact_id' => $contact->id]);
    }

    #[Test]
    public function another_role_at_the_same_entity_is_allowed(): void
    {
        // Бывает: тот же человек и закупщик, и логист.
        $contact = Contact::factory()->forClient($this->client)->create();
        $company = Company::factory()->create(['user_id' => $this->client->id]);

        ContactLink::factory()->to($company, ContactRole::BUYER)->create(['contact_id' => $contact->id]);
        ContactLink::factory()->to($company, ContactRole::LOGIST)->create(['contact_id' => $contact->id]);

        $this->assertSame(2, $contact->links()->count());
    }

    #[Test]
    public function phone_is_stored_as_typed_and_searched_by_digits(): void
    {
        // Поиск по отформатированной строке не берёт индекс, поэтому цифры
        // лежат отдельной колонкой.
        $contact = Contact::factory()->forClient($this->client)->create([
            'phone' => '+7 (912) 345-67-89',
        ]);

        $this->assertSame('+7 (912) 345-67-89', $contact->phone);
        $this->assertSame('79123456789', $contact->phone_digits);

        $found = Contact::query()->where('phone_digits', 'like', '%3456789')->first();
        $this->assertTrue($found?->is($contact));
    }

    #[Test]
    public function email_is_lowercased_so_one_address_is_one_person(): void
    {
        $contact = Contact::factory()->forClient($this->client)->create(['email' => 'Buh@Romashka.RU']);

        $this->assertSame('buh@romashka.ru', $contact->email);
    }

    #[Test]
    public function unsubscribe_token_appears_by_itself(): void
    {
        $contact = Contact::factory()->forClient($this->client)->create();

        $this->assertSame(64, mb_strlen((string) $contact->unsubscribe_token));
    }

    #[Test]
    public function foreign_contact_is_invisible_to_a_manager(): void
    {
        $stranger = User::factory()->create();
        $stranger->assignRole('sales-manager');
        $foreignManager = PersonalManager::factory()->create(['user_id' => $stranger->id]);
        $foreignClient = User::factory()->create(['personal_manager_id' => $foreignManager->id]);

        $mine = Contact::factory()->forClient($this->client)->create();
        $foreign = Contact::factory()->forClient($foreignClient)->create();

        $visible = Contact::query()->visibleInCrm($this->manager)->pluck('id');

        $this->assertTrue($visible->contains($mine->id));
        $this->assertFalse($visible->contains($foreign->id));
    }

    #[Test]
    public function person_without_a_partner_is_seen_only_by_those_who_see_everything(): void
    {
        // Водитель перевозчика не принадлежит ни одному партнёру. Показывать его
        // каждому менеджеру значило бы засорить всем справочник.
        $orphan = Contact::factory()->create(['client_user_id' => null]);

        $this->assertFalse(
            Contact::query()->visibleInCrm($this->manager)->pluck('id')->contains($orphan->id),
        );

        $head = User::factory()->create();
        $head->assignRole('sales-head');

        $this->assertTrue(
            Contact::query()->visibleInCrm($head)->pluck('id')->contains($orphan->id),
        );
    }

    #[Test]
    public function deliverable_means_more_than_active(): void
    {
        $noEmail = Contact::factory()->forClient($this->client)->create(['email' => null]);
        $inactive = Contact::factory()->forClient($this->client)->inactive()->create();
        $unsubscribed = Contact::factory()->forClient($this->client)->unsubscribed()->create();
        $good = Contact::factory()->forClient($this->client)->create();

        $this->assertFalse($noEmail->isDeliverable());
        $this->assertFalse($inactive->isDeliverable());
        $this->assertFalse($unsubscribed->isDeliverable());
        $this->assertTrue($good->isDeliverable());

        $this->assertSame([$good->id], Contact::query()->deliverable()->pluck('id')->all());
    }

    #[Test]
    public function source_tells_whose_data_it_is(): void
    {
        $ours = Contact::factory()->forClient($this->client)->create();
        $theirs = Contact::factory()->forClient($this->client)->bySelf()->create();

        $this->assertFalse($ours->source->belongsToPartner());
        $this->assertTrue($theirs->source->belongsToPartner());
        $this->assertSame(ContactSource::SELF, $theirs->source);
        $this->assertNotNull($theirs->partner_touched_at);
    }

    #[Test]
    public function birthday_without_a_year_is_marked_as_such(): void
    {
        $contact = Contact::factory()->forClient($this->client)->birthdayWithoutYear()->create();

        $this->assertFalse($contact->birthday_has_year);
        $this->assertSame('05-17', $contact->birthday->format('m-d'));
    }
}
