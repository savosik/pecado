<?php

namespace Tests\Unit\Contacts;

use App\Enums\ContactRole;
use App\Models\Company;
use App\Models\Contact;
use App\Models\ContactLink;
use App\Models\CrmEmail;
use App\Models\User;
use App\Services\Contacts\ContactDeduplicator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Дубли людей и слияние.
 *
 * Главное, что здесь проверяется: при слиянии ни одна ссылка не осиротела.
 * Письмо, привязанное к проигравшей карточке, обязано оказаться у победителя,
 * иначе переписка исчезнет из карточки человека молча.
 */
class ContactDeduplicatorTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = User::factory()->create();
    }

    private function dedupe(): ContactDeduplicator
    {
        return app(ContactDeduplicator::class);
    }

    #[Test]
    public function same_phone_makes_a_pair(): void
    {
        $first = Contact::factory()->forClient($this->client)->create(['phone' => '+7 912 345-67-89']);
        Contact::factory()->forClient($this->client)->create(['phone' => '89123456789', 'email' => null]);

        $this->assertCount(1, $this->dedupe()->similar($first));
    }

    #[Test]
    public function same_email_makes_a_pair(): void
    {
        $first = Contact::factory()->forClient($this->client)->create(['email' => 'buh@romashka.ru']);
        Contact::factory()->forClient($this->client)->create(['email' => 'BUH@romashka.ru', 'phone' => null]);

        $this->assertCount(1, $this->dedupe()->similar($first));
    }

    #[Test]
    public function namesakes_at_different_partners_are_not_duplicates(): void
    {
        // «Иванов Иван» у разных клиентов — разные люди.
        $other = User::factory()->create();

        $first = Contact::factory()->forClient($this->client)->create([
            'full_name' => 'Иванов Иван',
            'email' => 'one@example.com',
            'phone' => '+79110000001',
        ]);
        Contact::factory()->forClient($other)->create([
            'full_name' => 'Иванов Иван',
            'email' => 'two@example.com',
            'phone' => '+79110000002',
        ]);

        $this->assertCount(0, $this->dedupe()->similar($first));
    }

    #[Test]
    public function merge_moves_every_reference(): void
    {
        $winner = Contact::factory()->forClient($this->client)->create([
            'phone' => null,
            'position' => null,
            'email' => 'win@x.ru',
        ]);
        $duplicate = Contact::factory()->forClient($this->client)->create([
            'phone' => '+79123456789',
            'email' => 'dup@x.ru',
            'position' => 'Главный бухгалтер',
        ]);

        $company = Company::factory()->create(['user_id' => $this->client->id]);
        ContactLink::factory()->to($company, ContactRole::ACCOUNTANT)->create(['contact_id' => $duplicate->id]);

        $letter = CrmEmail::factory()->create(['contact_id' => $duplicate->id]);

        $this->dedupe()->merge($winner, $duplicate);

        $winner->refresh();

        $this->assertSame(1, $winner->links()->count());
        $this->assertSame($winner->id, $letter->fresh()->contact_id);
        // Пустые поля победителя добираются из дубля — иначе слияние потеряло бы
        // телефон, который был только у проигравшего.
        $this->assertSame('+79123456789', $winner->phone);
        $this->assertSame('Главный бухгалтер', $winner->position);
    }

    #[Test]
    public function loser_is_not_erased_but_points_at_the_winner(): void
    {
        // Ссылка из старого отчёта не должна упереться в пустоту.
        $winner = Contact::factory()->forClient($this->client)->create();
        $duplicate = Contact::factory()->forClient($this->client)->create();

        $this->dedupe()->merge($winner, $duplicate);

        $this->assertSoftDeleted($duplicate);
        $this->assertSame($winner->id, Contact::withTrashed()->find($duplicate->id)->merged_into_id);
    }

    #[Test]
    public function duplicate_link_does_not_break_the_unique_index(): void
    {
        // Оба были бухгалтерами одного юрлица — после слияния роль остаётся одна.
        $company = Company::factory()->create(['user_id' => $this->client->id]);

        $winner = Contact::factory()->forClient($this->client)->create();
        $duplicate = Contact::factory()->forClient($this->client)->create();

        ContactLink::factory()->to($company, ContactRole::ACCOUNTANT)->create(['contact_id' => $winner->id]);
        ContactLink::factory()->to($company, ContactRole::ACCOUNTANT)->create(['contact_id' => $duplicate->id]);

        $this->dedupe()->merge($winner, $duplicate);

        $this->assertSame(1, $winner->links()->count());
    }

    #[Test]
    public function merged_card_stops_showing_up_as_a_duplicate(): void
    {
        $winner = Contact::factory()->forClient($this->client)->create(['phone' => '+79123456789']);
        $duplicate = Contact::factory()->forClient($this->client)->create(['phone' => '+79123456789', 'email' => null]);

        $this->dedupe()->merge($winner, $duplicate);

        $this->assertCount(0, $this->dedupe()->similar($winner->fresh()));
    }

    #[Test]
    public function merging_a_card_into_itself_does_nothing(): void
    {
        $contact = Contact::factory()->forClient($this->client)->create();

        $this->dedupe()->merge($contact, $contact);

        $this->assertNull($contact->fresh()->deleted_at);
    }
}
