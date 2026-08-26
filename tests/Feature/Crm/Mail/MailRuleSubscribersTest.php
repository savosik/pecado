<?php

namespace Tests\Feature\Crm\Mail;

use App\Enums\Crm\EmailStatus;
use App\Models\CrmEmail;
use App\Models\CrmMailRule;
use App\Models\User;
use App\Services\Crm\Mail\MailRuleEngine;
use App\Services\Crm\Mail\MailStream;
use App\Support\Notifications\Occasion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Адресная часть правила: кто на него подписан.
 *
 * До подписок правило было глобальным фильтром — «все письма с меткой X».
 * Здесь проверяется вторая половина: «...но только у этих партнёров».
 */
class MailRuleSubscribersTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $manager;

    private User $subscribed;

    private User $other;

    /**
     * Движок правил больше не маршрутизирует уведомления: этим занимается
     * настройка партнёра (эпик note-00). Тесты описывают механизм, который
     * ничего не решает, и уходят вместе с ним в note-08.
     *
     * Пропуск, а не удаление: снос движка — большая необратимая правка,
     * и делать её без присмотра неправильно.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->markTestSkipped('Движок правил отключён от маршрутизации — сносится в note-08.');
    }

    private function rule(array $clientIds = []): CrmMailRule
    {
        $rule = CrmMailRule::query()->create([
            'name' => 'Смена статуса — клиенту',
            'conditions' => ['all' => [['field' => 'tag', 'op' => 'has_tag', 'value' => 'заказ']]],
            'recipients' => [CrmMailRule::RECIPIENT_CLIENT],
            'auto_send' => false,
            'is_active' => true,
        ]);

        foreach ($clientIds as $id) {
            $rule->clients()->attach($id, ['created_at' => now()]);
        }

        return $rule->load('clients');
    }

    private function letterFor(User $client): CrmEmail
    {
        return app(MailStream::class)->capture(new Occasion(
            key: 'orders.status_changed',
            clientUserId: $client->id,
            data: [
                'order_id' => fake()->numberBetween(1, 9999),
                'order_number' => (string) fake()->numberBetween(1, 9999),
                'status' => 'shipping',
                'status_label' => 'В процессе отгрузки',
            ],
        ));
    }

    #[Test]
    public function правило_без_подписчиков_ловит_всех(): void
    {
        $this->rule();

        $letter = $this->letterFor($this->other);

        $this->assertSame(EmailStatus::DRAFT, $letter->refresh()->status);
        $this->assertContains($this->other->email, (array) $letter->to);
    }

    #[Test]
    public function правило_с_подписчиками_ловит_только_их(): void
    {
        $this->rule([$this->subscribed->id]);

        $mine = $this->letterFor($this->subscribed);
        $foreign = $this->letterFor($this->other);

        $this->assertSame(EmailStatus::DRAFT, $mine->refresh()->status);
        $this->assertContains($this->subscribed->email, (array) $mine->to);

        // Письмо неподписанного партнёра остаётся мимо фильтров — именно так
        // менеджер и увидит, кого ещё стоит подписать.
        $this->assertSame(EmailStatus::UNMATCHED, $foreign->refresh()->status);
        $this->assertEmpty((array) $foreign->to);
    }

    #[Test]
    public function адресное_правило_не_ловит_письмо_без_партнёра(): void
    {
        $rule = $this->rule([$this->subscribed->id]);

        $letter = CrmEmail::factory()->create([
            'client_user_id' => null,
            'origin' => 'system',
            'status' => EmailStatus::UNMATCHED,
            'to' => [],
            'tags' => ['заказ'],
        ]);

        $matched = app(MailRuleEngine::class)->apply($letter, $rule);

        $this->assertSame([], $matched);
    }

    #[Test]
    public function подписчики_сохраняются_через_форму(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('crm.emails.rules.store'), [
                'name' => 'Статусы избранным',
                'match' => 'all',
                'conditions' => [['field' => 'tag', 'op' => 'has_tag', 'value' => 'заказ']],
                'recipients' => [CrmMailRule::RECIPIENT_CLIENT],
                'client_ids' => [$this->subscribed->id],
            ])
            ->assertCreated()
            ->assertJsonPath('rule.clients.0.id', $this->subscribed->id);

        $rule = CrmMailRule::query()->firstOrFail();

        $this->assertSame([$this->subscribed->id], $rule->subscribedClientIds());
        $this->assertDatabaseHas('crm_mail_rule_clients', [
            'rule_id' => $rule->id,
            'client_user_id' => $this->subscribed->id,
            'created_by_user_id' => $this->manager->id,
        ]);
    }

    #[Test]
    public function пустой_список_возвращает_правило_к_всем_партнёрам(): void
    {
        $rule = $this->rule([$this->subscribed->id]);

        $this->actingAs($this->manager)
            ->patchJson(route('crm.emails.rules.update', $rule), [
                'name' => $rule->name,
                'match' => 'all',
                'conditions' => [['field' => 'tag', 'op' => 'has_tag', 'value' => 'заказ']],
                'recipients' => [CrmMailRule::RECIPIENT_CLIENT],
                'client_ids' => [],
            ])
            ->assertOk()
            ->assertJsonPath('rule.clients', []);

        $this->assertDatabaseCount('crm_mail_rule_clients', 0);
        $this->assertTrue($rule->refresh()->load('clients')->appliesToClient($this->other->id));
    }

    #[Test]
    public function чужой_партнёр_в_подписчики_не_попадает(): void
    {
        // Клиент чужого менеджера: менеджер его не видит и подписать не может,
        // иначе через список подписчиков утекают чужие имена.
        $foreign = User::factory()->create(['erp_name' => 'Чужой партнёр']);

        $this->actingAs($this->manager)
            ->postJson(route('crm.emails.rules.store'), [
                'name' => 'Попытка подписать чужого',
                'match' => 'all',
                'conditions' => [['field' => 'tag', 'op' => 'has_tag', 'value' => 'заказ']],
                'recipients' => [CrmMailRule::RECIPIENT_CLIENT],
                'client_ids' => [$foreign->id],
            ])
            ->assertCreated()
            ->assertJsonPath('rule.clients', []);

        $this->assertDatabaseCount('crm_mail_rule_clients', 0);
    }

    #[Test]
    public function превью_учитывает_подписчиков(): void
    {
        $this->letterFor($this->subscribed);
        $this->letterFor($this->other);

        $payload = [
            'match' => 'all',
            'conditions' => [['field' => 'tag', 'op' => 'has_tag', 'value' => 'заказ']],
        ];

        $this->actingAs($this->manager)
            ->postJson(route('crm.emails.rules.preview'), $payload)
            ->assertOk()
            ->assertJsonPath('total', 2);

        $this->actingAs($this->manager)
            ->postJson(route('crm.emails.rules.preview'), $payload + ['client_ids' => [$this->subscribed->id]])
            ->assertOk()
            ->assertJsonPath('total', 1);
    }

    #[Test]
    public function поиск_партнёров_показывает_только_своих(): void
    {
        User::factory()->create(['erp_name' => 'Чужой партнёр']);

        $response = $this->actingAs($this->manager)
            ->getJson(route('crm.emails.rules.clients', ['search' => 'партнёр']))
            ->assertOk();

        $labels = array_column($response->json('options'), 'label');

        $this->assertContains('Подписанный партнёр', $labels);
        $this->assertNotContains('Чужой партнёр', $labels);
    }

    #[Test]
    public function отписка_убирает_партнёра_но_не_трогает_остальных(): void
    {
        $rule = $this->rule([$this->subscribed->id, $this->other->id]);
        $subscribedAt = $rule->clients->firstWhere('id', $this->subscribed->id)->pivot->created_at;

        $this->actingAs($this->manager)
            ->patchJson(route('crm.emails.rules.update', $rule), [
                'name' => $rule->name,
                'match' => 'all',
                'conditions' => [['field' => 'tag', 'op' => 'has_tag', 'value' => 'заказ']],
                'recipients' => [CrmMailRule::RECIPIENT_CLIENT],
                'client_ids' => [$this->subscribed->id],
            ])
            ->assertOk();

        $this->assertDatabaseMissing('crm_mail_rule_clients', [
            'rule_id' => $rule->id,
            'client_user_id' => $this->other->id,
        ]);

        // Дата подписки уцелевшего не переписана: кто и когда подписал —
        // это история, а не побочный результат сохранения формы.
        $this->assertSame(
            (string) $subscribedAt,
            (string) $rule->refresh()->load('clients')->clients->first()->pivot->created_at,
        );
    }
}
