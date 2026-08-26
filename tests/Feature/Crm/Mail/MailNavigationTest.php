<?php

namespace Tests\Feature\Crm\Mail;

use App\Models\Company;
use App\Models\CrmEmail;
use App\Models\Order;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Crm\CrmEmailService;
use App\Services\Crm\Mail\MailStream;
use App\Services\Crm\Mail\PartnerAddressBook;
use App\Support\Notifications\Occasion;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Ориентирование, когда писем и правил становится много.
 *
 * Топики отвечают на «что я сейчас разбираю», метки — на «за что зацепить
 * фильтр», отбор правил — на «где то правило, которое я завёл в марте».
 * И отдельно: письмо бухгалтеру партнёра должно оказаться в карточке партнёра.
 */
class MailNavigationTest extends TestCase
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
        Mail::fake();

        $this->manager = User::factory()->create(['email' => 'manager@pecado.ru']);
        $this->manager->assignRole('sales-manager');
        $profile = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->client = User::factory()->create([
            'personal_manager_id' => $profile->id,
            'email' => 'client@example.com',
        ]);

        config(['mail_stream.enabled' => true]);
    }

    private function documentLetter(): CrmEmail
    {
        return app(MailStream::class)->capture(new Occasion(
            key: 'documents.published',
            clientUserId: $this->client->id,
            data: ['document_type' => 'reconciliation_act', 'document_number' => '1023', 'document_title' => 'Акт сверки'],
            view: ['title' => 'Акт сверки', 'body' => 'Документ выложен'],
        ));
    }

    private function financeLetter(): CrmEmail
    {
        // По умолчанию о просрочке узнаёт менеджер; здесь проверяются топики,
        // поэтому адресуем письмо партнёру, чтобы оба легли в одну папку.
        \App\Models\NotificationPreference::query()->updateOrCreate(
            ['user_id' => $this->client->id, 'occasion_key' => 'finance.overdue_started'],
            ['is_enabled' => true, 'destinations' => [['type' => 'login']]],
        );

        return app(MailStream::class)->capture(new Occasion(
            key: 'finance.overdue_started',
            clientUserId: $this->client->id,
            data: ['days_overdue' => 40, 'overdue_amount' => 1000, 'positions_count' => 1],
            view: ['title' => 'Просрочка', 'body' => 'Есть долг'],
        ));
    }

    private function props(array $query = []): array
    {
        return $this->actingAs($this->manager)
            // Уведомления теперь адресованы по настройке партнёра и ждут
            // отправки в черновиках, а не лежат «мимо фильтров».
            ->get(route('crm.emails.index', $query + ['folder' => 'drafts']))
            ->viewData('page')['props'];
    }

    #[Test]
    public function topic_narrows_the_stream_to_one_kind_of_business(): void
    {
        $this->documentLetter();
        $this->financeLetter();

        $this->assertCount(2, $this->props()['emails']['data']);

        $finance = $this->props(['topic' => 'finance'])['emails']['data'];

        $this->assertCount(1, $finance);
        $this->assertSame('finance.overdue_started', $finance[0]['origin_event']);
    }

    #[Test]
    public function tag_filter_matches_the_whole_tag(): void
    {
        // Кириллица в json-колонке лежит экранированной, и поиск сырой строкой
        // не нашёл бы ничего — молча и навсегда.
        $this->documentLetter();
        $this->financeLetter();

        $found = $this->props(['tag' => 'акт-сверки'])['emails']['data'];

        $this->assertCount(1, $found);
        $this->assertContains('акт-сверки', $found[0]['tags']);

        $this->assertCount(0, $this->props(['tag' => 'акт'])['emails']['data']);
    }

    #[Test]
    public function folder_counters_respect_the_topic(): void
    {
        $this->documentLetter();
        $this->financeLetter();

        $counts = collect($this->props(['topic' => 'documents'])['folders'])->pluck('count', 'value')->all();

        $this->assertSame(1, $counts['drafts']);
    }

    #[Test]
    public function letter_to_a_partner_contact_lands_in_the_partner_card(): void
    {
        // Письмо бухгалтеру уходит на его личный ящик, пользователя сайта
        // по такому адресу не найти — но человек есть в справочнике, и переписка
        // должна оказаться там, где её будут искать.
        \App\Models\Contact::factory()->forClient($this->client)->create([
            'full_name' => 'Афонина Мария',
            'email' => 'buh@romashka.ru',
        ]);

        $letter = app(CrmEmailService::class)->createDraft(
            $this->manager,
            ['to' => ['buh@romashka.ru'], 'subject' => 'Акт сверки', 'body_html' => '<p>Здравствуйте</p>'],
            null,
        );

        $this->assertSame($this->client->id, $letter->client_user_id);
    }

    #[Test]
    public function letter_lands_in_the_contact_card_too(): void
    {
        // Ради этого справочник и связан с почтой: раньше письмо бухгалтеру
        // подшивалось к партнёру, и открыть карточку человека было нельзя.
        $contact = \App\Models\Contact::factory()
            ->forClient($this->client)
            ->create(['email' => 'buh@romashka.ru']);

        $letter = app(CrmEmailService::class)->createDraft(
            $this->manager,
            ['to' => ['buh@romashka.ru'], 'subject' => 'Акт сверки', 'body_html' => '<p>Здравствуйте</p>'],
            null,
        );

        $this->assertSame($this->client->id, $letter->client_user_id);
        $this->assertSame($contact->id, $letter->contact_id);
    }

    #[Test]
    public function directory_wins_over_the_contractor_email(): void
    {
        // Справочник — первый источник: там адрес заведён осознанно и привязан
        // к человеку, а почта юрлица выведена из реквизитов.
        $other = User::factory()->create();
        \App\Models\Company::factory()->create(['user_id' => $other->id, 'email' => 'shared@romashka.ru']);

        \App\Models\Contact::factory()->forClient($this->client)->create(['email' => 'shared@romashka.ru']);

        $this->assertSame(
            $this->client->id,
            app(PartnerAddressBook::class)->resolve('shared@romashka.ru'),
        );
    }

    #[Test]
    public function retired_contact_stops_resolving(): void
    {
        // Уволившийся не должен получать письма и подтягивать к себе переписку.
        \App\Models\Contact::factory()
            ->forClient($this->client)
            ->inactive()
            ->create(['email' => 'former@romashka.ru']);

        $this->assertNull(app(PartnerAddressBook::class)->resolveContact('former@romashka.ru'));
    }

    #[Test]
    public function similar_address_does_not_bind_a_foreign_letter(): void
    {
        // Догадки здесь означали бы чужую переписку в чужой карточке.
        \App\Models\Contact::factory()->forClient($this->client)->create(['email' => 'buh@romashka.ru']);

        $this->assertNull(app(PartnerAddressBook::class)->resolve('sbuh@romashka.ru'));
        $this->assertSame($this->client->id, app(PartnerAddressBook::class)->resolve('BUH@Romashka.RU'));
    }

    #[Test]
    public function company_address_binds_to_its_partner(): void
    {
        Company::factory()->create([
            'user_id' => $this->client->id,
            'email' => 'office@romashka.ru',
        ]);

        $this->assertSame($this->client->id, app(PartnerAddressBook::class)->resolve('office@romashka.ru'));
    }

    #[Test]
    public function letter_bound_to_an_entity_keeps_its_partner(): void
    {
        $order = Order::factory()->create(['user_id' => $this->client->id]);

        $letter = app(CrmEmailService::class)->createDraft(
            $this->manager,
            ['to' => ['stranger@example.com'], 'subject' => 'Заказ', 'body_html' => '<p>Здравствуйте</p>'],
            $order,
        );

        $this->assertSame($this->client->id, $letter->client_user_id);
    }
}
