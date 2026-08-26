<?php

namespace Tests\Feature\Notifications;

use App\Enums\ContactRole;
use App\Models\Company;
use App\Models\Contact;
use App\Models\ContactLink;
use App\Models\NotificationPreference;
use App\Models\NotificationSuppression;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Notifications\NotificationRouter;
use App\Support\Notifications\Occasion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Кому уйдёт уведомление.
 *
 * Главное свойство новой конструкции: ответ читается из настройки партнёра,
 * а не вычисляется прогоном правил.
 */
class NotificationRouterTest extends TestCase
{
    use RefreshDatabase;

    private NotificationRouter $router;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->router = app(NotificationRouter::class);
        $this->client = User::factory()->create(['email' => 'client@example.com']);
    }

    private function occasion(string $key = 'orders.created', array $data = [], ?int $companyId = null): Occasion
    {
        return new Occasion(
            key: $key,
            clientUserId: $this->client->id,
            companyId: $companyId,
            data: $data,
        );
    }

    private function prefer(string $key, array $attributes): void
    {
        NotificationPreference::query()->create(array_merge([
            'user_id' => $this->client->id,
            'occasion_key' => $key,
            'is_enabled' => true,
        ], $attributes));
    }

    #[Test]
    public function без_настройки_действует_умолчание(): void
    {
        $this->assertSame(['client@example.com'], $this->router->addressesFor($this->occasion()));
    }

    #[Test]
    public function выключенное_уведомление_не_уходит_никому(): void
    {
        $this->prefer('orders.created', ['is_enabled' => false]);

        $this->assertSame([], $this->router->addressesFor($this->occasion()));
    }

    #[Test]
    public function произвольный_адрес_заменяет_умолчание(): void
    {
        $this->prefer('orders.created', [
            'destinations' => [['type' => 'email', 'email' => 'buh@romashka.ru']],
        ]);

        $this->assertSame(['buh@romashka.ru'], $this->router->addressesFor($this->occasion()));
    }

    #[Test]
    public function адресаты_складываются_а_не_исключают_друг_друга(): void
    {
        $contact = Contact::factory()->create([
            'client_user_id' => $this->client->id,
            'email' => 'glavbuh@romashka.ru',
        ]);
        ContactLink::query()->create([
            'contact_id' => $contact->id,
            'subject_type' => User::class,
            'subject_id' => $this->client->id,
            'role' => ContactRole::ACCOUNTANT->value,
            'client_user_id' => $this->client->id,
        ]);

        $this->prefer('documents.published', [
            'destinations' => [
                ['type' => 'login'],
                ['type' => 'contact_role', 'role' => 'accountant'],
            ],
        ]);

        $addresses = $this->router->addressesFor($this->occasion('documents.published'));

        $this->assertContains('client@example.com', $addresses);
        $this->assertContains('glavbuh@romashka.ru', $addresses);
    }

    #[Test]
    public function роль_сужается_до_контрагента_письма(): void
    {
        $mine = Company::factory()->create(['user_id' => $this->client->id]);
        $other = Company::factory()->create(['user_id' => $this->client->id]);

        foreach ([[$mine, 'buh-mine@example.com'], [$other, 'buh-other@example.com']] as [$company, $email]) {
            $contact = Contact::factory()->create(['client_user_id' => $this->client->id, 'email' => $email]);
            ContactLink::query()->create([
                'contact_id' => $contact->id,
                'subject_type' => Company::class,
                'subject_id' => $company->id,
                'role' => ContactRole::ACCOUNTANT->value,
                'client_user_id' => $this->client->id,
            ]);
        }

        $this->prefer('documents.published', [
            'destinations' => [['type' => 'contact_role', 'role' => 'accountant']],
        ]);

        $addresses = $this->router->addressesFor(
            $this->occasion('documents.published', companyId: $mine->id),
        );

        // У партнёра с тремя юрлицами письмо про одно не уходит бухгалтерам
        // остальных — этого не умел движок правил.
        $this->assertSame(['buh-mine@example.com'], $addresses);
    }

    #[Test]
    public function конкретный_человек_адресуется_по_идентификатору(): void
    {
        $contact = Contact::factory()->create([
            'client_user_id' => $this->client->id,
            'email' => 'director@romashka.ru',
        ]);

        $this->prefer('documents.published', [
            'destinations' => [['type' => 'contact', 'contact_id' => $contact->id]],
        ]);

        $this->assertSame(
            ['director@romashka.ru'],
            $this->router->addressesFor($this->occasion('documents.published')),
        );
    }

    #[Test]
    public function персональный_менеджер_адресуется(): void
    {
        $manager = PersonalManager::factory()->create(['email' => 'manager@pecado.ru']);
        $this->client->forceFill(['personal_manager_id' => $manager->id])->save();

        $this->prefer('orders.created', ['destinations' => [['type' => 'manager']]]);

        $this->assertSame(['manager@pecado.ru'], $this->router->addressesFor($this->occasion()));
    }

    #[Test]
    public function отписавшийся_контакт_в_выдачу_не_попадает(): void
    {
        $contact = Contact::factory()->create([
            'client_user_id' => $this->client->id,
            'email' => 'left@romashka.ru',
            'unsubscribed_at' => now(),
        ]);

        $this->prefer('documents.published', [
            'destinations' => [['type' => 'contact', 'contact_id' => $contact->id]],
        ]);

        $this->assertSame([], $this->router->addressesFor($this->occasion('documents.published')));
    }

    #[Test]
    public function адрес_из_стоп_листа_отсеивается(): void
    {
        NotificationSuppression::query()->create([
            'email' => 'client@example.com',
            'scope' => NotificationSuppression::SCOPE_ALL,
            'reason' => NotificationSuppression::REASON_UNSUBSCRIBED,
        ]);

        $this->assertSame([], $this->router->addressesFor($this->occasion()));
    }

    #[Test]
    public function статус_вне_набора_письма_не_порождает(): void
    {
        $shipping = $this->occasion('orders.status_changed', ['status' => 'shipping']);
        $closure = $this->occasion('orders.status_changed', ['status' => 'ready_for_closure']);

        // Умолчание — три «физических» статуса; «Готов к закрытию» внутренний.
        $this->assertSame(['client@example.com'], $this->router->addressesFor($shipping));
        $this->assertSame([], $this->router->addressesFor($closure));
    }

    #[Test]
    public function набор_статусов_можно_сузить(): void
    {
        $this->prefer('orders.status_changed', [
            'options' => ['statuses' => ['ready_for_shipment']],
        ]);

        $this->assertSame(
            ['client@example.com'],
            $this->router->addressesFor($this->occasion('orders.status_changed', ['status' => 'ready_for_shipment'])),
        );
        $this->assertSame(
            [],
            $this->router->addressesFor($this->occasion('orders.status_changed', ['status' => 'shipping'])),
        );
    }

    #[Test]
    public function неизвестный_тип_уведомления_ничего_не_даёт(): void
    {
        $this->assertSame([], $this->router->addressesFor($this->occasion('orders.nonexistent')));
    }
}
