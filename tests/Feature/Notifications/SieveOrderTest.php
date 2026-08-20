<?php

namespace Tests\Feature\Notifications;

use App\Enums\ClientContactRole;
use App\Models\ClientContact;
use App\Models\Company;
use App\Models\NotificationDelivery;
use App\Models\NotificationRule;
use App\Models\NotificationRuleRecipient;
use App\Models\NotificationSignal;
use App\Models\PersonalManager;
use App\Models\User;
use App\Notifications\Pulse\PulseNotification;
use App\Notifications\Pulse\Support\PulseSignal;
use App\Services\Notifications\Pulse\NotificationPulse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Разбор правил в порядке приоритета — кейс заказчика целиком.
 *
 * «Изменения заказов по контрагенту Пупкину приходят на емейлы Жопкина
 * и Петрова, если там недосталось позиции; а если просто статус поменялся —
 * тогда на емейл пользователя; а если заказ закрыт — то на емейл директора
 * Залупкина».
 */
class SieveOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $partner;

    private Company $pupkin;

    private ClientContact $director;

    private ClientContact $buyerOne;

    private ClientContact $buyerTwo;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'notification_pulse.enabled' => true,
            'notification_pulse.mode' => 'live',
            'notification_pulse.live_events' => [],
            'notification_pulse.domains.orders.enabled' => true,
        ]);

        Notification::fake();

        $manager = PersonalManager::factory()->create(['email' => 'manager@pecado.ru']);
        $this->partner = User::factory()->create([
            'email' => 'client@pupkin.ru',
            'personal_manager_id' => $manager->id,
        ]);

        $this->pupkin = Company::factory()->create([
            'user_id' => $this->partner->id,
            'name' => 'ООО Пупкин',
            'tax_id' => '7701234567',
        ]);

        $this->director = $this->contact('Залупкин Виктор', ClientContactRole::DIRECTOR, 'dir@pupkin.ru');
        $this->buyerOne = $this->contact('Жопкин Анатолий', ClientContactRole::BUYER, 'zhopkin@pupkin.ru');
        $this->buyerTwo = $this->contact('Петров Иван', ClientContactRole::BUYER, 'petrov@pupkin.ru');

        $this->buildCustomerRules();
    }

    private function contact(string $name, ClientContactRole $role, string $email): ClientContact
    {
        return ClientContact::factory()->create([
            'user_id' => $this->partner->id,
            'company_id' => $this->pupkin->id,
            'full_name' => $name,
            'role' => $role,
            'email' => $email,
        ]);
    }

    /**
     * Три правила из задачи заказчика.
     */
    private function buildCustomerRules(): void
    {
        // 50: заказ закрыт → директору, дальше не обрабатывать
        $closed = NotificationRule::factory()->forCompany($this->pupkin->id)->stopping()->priority(50)->create([
            'name' => 'Закрытие заказа — директору',
            'event_key' => 'orders.status_changed',
            'conditions' => ['field' => 'status', 'op' => 'in', 'value' => ['closed']],
        ]);
        $closed->recipients()->create([
            'kind' => NotificationRuleRecipient::KIND_CONTACT,
            'contact_id' => $this->director->id,
        ]);

        // 100: любая смена статуса → клиенту
        $status = NotificationRule::factory()->forCompany($this->pupkin->id)->priority(100)->create([
            'name' => 'Смена статуса — клиенту',
            'event_key' => 'orders.status_changed',
        ]);
        $status->recipients()->create(['kind' => NotificationRuleRecipient::KIND_CLIENT_USER]);

        // 100: недобор → всем закупщикам контрагента
        $shortfall = NotificationRule::factory()->forCompany($this->pupkin->id)->priority(100)->create([
            'name' => 'Недобор — закупщикам',
            'event_key' => 'orders.shortfall',
        ]);
        $shortfall->recipients()->create([
            'kind' => NotificationRuleRecipient::KIND_CONTACT_ROLE,
            'value' => ClientContactRole::BUYER->value,
        ]);
    }

    private function fire(string $eventKey, array $data): array
    {
        return app(NotificationPulse::class)->signal(new PulseSignal(
            eventKey: $eventKey,
            clientUserId: $this->partner->id,
            companyId: $this->pupkin->id,
            data: $data,
            view: ['title' => 'Тест', 'body' => 'Тело'],
        ));
    }

    /**
     * Адреса, на которые ушли письма.
     *
     * Собираем из журнала фейка, а не через assertSentOnDemand: последний
     * падает, когда писем нет вовсе, а «не ушло никому» — законный ожидаемый
     * исход в половине проверок этого класса.
     *
     * @return array<int, string>
     */
    private function sentTo(): array
    {
        $addresses = [];

        // Структура журнала фейка: [класс notifiable][ключ][класс уведомления][]
        foreach (Notification::sentNotifications() as $byKey) {
            foreach ($byKey as $byType) {
                foreach ($byType[PulseNotification::class] ?? [] as $item) {
                    $target = $item['notifiable'] ?? null;

                    if ($target instanceof AnonymousNotifiable) {
                        $addresses[] = $target->routes['mail'];
                    }
                }
            }
        }

        $addresses = array_values(array_unique($addresses));
        sort($addresses);

        return $addresses;
    }

    #[Test]
    #[TestDox('Закрытие заказа уходит директору и НЕ уходит клиенту')]
    public function closed_order_goes_to_director_only(): void
    {
        $result = $this->fire('orders.status_changed', ['status' => 'closed']);

        $this->assertSame(['dir@pupkin.ru'], $this->sentTo());
        $this->assertSame(1, $result['queued']);

        // Правило с приоритетом 50 остановило разбор — правило 100 не сработало
        $this->assertSame(1, $result['matched']);
    }

    #[Test]
    #[TestDox('Обычная смена статуса уходит клиенту')]
    public function other_status_goes_to_client(): void
    {
        $this->fire('orders.status_changed', ['status' => 'shipping']);

        $this->assertSame(['client@pupkin.ru'], $this->sentTo());
    }

    #[Test]
    #[TestDox('Недобор уходит обоим закупщикам, по письму на адрес')]
    public function shortfall_goes_to_both_buyers(): void
    {
        $result = $this->fire('orders.shortfall', ['shortfall_items_count' => 2]);

        $this->assertSame(['petrov@pupkin.ru', 'zhopkin@pupkin.ru'], $this->sentTo());
        $this->assertSame(2, $result['queued']);
    }

    #[Test]
    #[TestDox('Адрес в двух сработавших правилах получает письмо один раз')]
    public function duplicate_recipient_gets_single_letter(): void
    {
        // Второе правило на тот же адрес: директор указан и напрямую
        $extra = NotificationRule::factory()->forCompany($this->pupkin->id)->priority(60)->create([
            'name' => 'Дубль директору',
            'event_key' => 'orders.shortfall',
        ]);
        $extra->recipients()->create([
            'kind' => NotificationRuleRecipient::KIND_EMAIL,
            'value' => 'zhopkin@pupkin.ru',
        ]);

        $result = $this->fire('orders.shortfall', ['shortfall_items_count' => 1]);

        $this->assertSame(['petrov@pupkin.ru', 'zhopkin@pupkin.ru'], $this->sentTo());
        $this->assertSame(2, $result['queued'], 'дубль не должен породить третье письмо');
    }

    #[Test]
    #[TestDox('Правило другого контрагента не срабатывает')]
    public function other_contractor_rules_do_not_fire(): void
    {
        $other = Company::factory()->create(['user_id' => $this->partner->id, 'name' => 'ООО Одуванчик']);

        $rule = NotificationRule::factory()->forCompany($other->id)->priority(10)->create([
            'name' => 'Чужое правило',
            'event_key' => 'orders.status_changed',
        ]);
        $rule->recipients()->create(['kind' => NotificationRuleRecipient::KIND_EMAIL, 'value' => 'other@x.ru']);

        $this->fire('orders.status_changed', ['status' => 'shipping']);

        $this->assertNotContains('other@x.ru', $this->sentTo());
    }

    #[Test]
    #[TestDox('Контакт чужого контрагента в получатели не попадает')]
    public function contact_of_another_contractor_is_refused(): void
    {
        $oduvanchik = Company::factory()->create(['user_id' => $this->partner->id]);
        $strangerContact = ClientContact::factory()->create([
            'user_id' => $this->partner->id,
            'company_id' => $oduvanchik->id,
            'email' => 'stranger@oduvanchik.ru',
        ]);

        // Правило Пупкина, но получатель — контакт «Одуванчика»: так выглядит
        // неаккуратно собранное правило, и резолвер обязан его отсечь.
        $rule = NotificationRule::factory()->forCompany($this->pupkin->id)->priority(10)->create([
            'name' => 'Ошибочный адресат',
            'event_key' => 'orders.shipped',
        ]);
        $rule->recipients()->create([
            'kind' => NotificationRuleRecipient::KIND_CONTACT,
            'contact_id' => $strangerContact->id,
        ]);

        $this->fire('orders.shipped', ['amount' => 100]);

        $this->assertNotContains('stranger@oduvanchik.ru', $this->sentTo());
    }

    #[Test]
    #[TestDox('Правило-исключение вычёркивает адресата, добавленного ниже')]
    public function suppress_rule_removes_recipient(): void
    {
        $suppress = NotificationRule::factory()->forCompany($this->pupkin->id)->priority(10)->create([
            'name' => 'Не писать Петрову',
            'event_key' => 'orders.shortfall',
        ]);
        $suppress->recipients()->create([
            'kind' => NotificationRuleRecipient::KIND_SUPPRESS,
            'value' => 'petrov@pupkin.ru',
        ]);

        $this->fire('orders.shortfall', ['shortfall_items_count' => 1]);

        $this->assertSame(['zhopkin@pupkin.ru'], $this->sentTo());
    }

    #[Test]
    #[TestDox('Резервный адресат вступает, только когда основных нет')]
    public function fallback_recipient_is_last_resort(): void
    {
        $orphan = User::factory()->create(['email' => 'orphan@x.ru', 'personal_manager_id' => null]);

        $rule = NotificationRule::factory()->create([
            'name' => 'Новый заказ менеджеру',
            'event_key' => 'orders.created',
            'scope_type' => NotificationRule::SCOPE_GLOBAL,
        ]);
        $rule->recipients()->create(['kind' => NotificationRuleRecipient::KIND_PERSONAL_MANAGER]);
        $rule->recipients()->create([
            'kind' => NotificationRuleRecipient::KIND_EMAIL,
            'value' => 'sales@pecado.ru',
            'is_fallback' => true,
        ]);

        // У клиента менеджера нет — письмо уходит на резервный адрес
        app(NotificationPulse::class)->signal(new PulseSignal(
            eventKey: 'orders.created',
            clientUserId: $orphan->id,
            data: ['total' => 100],
        ));

        $this->assertSame(['sales@pecado.ru'], $this->sentTo());
    }

    #[Test]
    #[TestDox('Каждое решение движка попадает в журнал доставок')]
    public function every_decision_is_journaled(): void
    {
        $result = $this->fire('orders.shortfall', ['shortfall_items_count' => 1]);

        $deliveries = NotificationDelivery::where('signal_uuid', $result['signal_uuid'])->get();

        $this->assertCount(2, $deliveries);
        $this->assertEqualsCanonicalizing(
            ['petrov@pupkin.ru', 'zhopkin@pupkin.ru'],
            $deliveries->pluck('recipient')->all(),
        );

        foreach ($deliveries as $delivery) {
            $this->assertSame(NotificationDelivery::STATUS_QUEUED, $delivery->status);
            $this->assertSame('Недобор — закупщикам', $delivery->rule_name);
            $this->assertNotNull($delivery->subject);
        }
    }

    #[Test]
    #[TestDox('Сигнал без единого совпавшего правила тоже записывается')]
    public function signal_without_rules_is_recorded(): void
    {
        NotificationRule::query()->delete();

        $result = $this->fire('orders.status_changed', ['status' => 'closed']);

        Notification::assertNothingSent();

        $signal = NotificationSignal::where('uuid', $result['signal_uuid'])->sole();
        $this->assertSame(0, $signal->matched_rules_count);
        $this->assertSame(0, $signal->deliveries_count);
        // Именно эта запись отвечает на «почему клиенту ничего не пришло»
        $this->assertSame('orders.status_changed', $signal->event_key);
    }

    #[Test]
    #[TestDox('Выключенное правило не участвует в разборе')]
    public function inactive_rule_is_ignored(): void
    {
        NotificationRule::where('name', 'Закрытие заказа — директору')->update(['is_active' => false]);

        $this->fire('orders.status_changed', ['status' => 'closed']);

        // Правило-стоп выключено, поэтому сработало правило ниже
        $this->assertSame(['client@pupkin.ru'], $this->sentTo());
    }

    #[Test]
    #[TestDox('Метка ИНН ловит любое событие контрагента, включая будущие')]
    public function tag_rule_catches_any_event(): void
    {
        NotificationRule::query()->delete();

        $rule = NotificationRule::factory()->create([
            'name' => 'Всё по Пупкину — бухгалтеру',
            'event_key' => 'orders.*',
            'scope_type' => NotificationRule::SCOPE_GLOBAL,
            'conditions' => ['op' => 'has_tag', 'value' => 'инн:7701234567'],
        ]);
        $rule->recipients()->create([
            'kind' => NotificationRuleRecipient::KIND_EMAIL,
            'value' => 'buh@pupkin.ru',
        ]);

        $this->fire('orders.shipped', ['amount' => 500]);

        $this->assertSame(['buh@pupkin.ru'], $this->sentTo());
    }
}
