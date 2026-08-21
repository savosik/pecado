<?php

namespace Tests\Feature\Crm\Mail;

use App\Enums\Crm\EmailStatus;
use App\Models\CrmEmail;
use App\Models\CrmMailRule;
use App\Models\PersonalManager;
use App\Models\User;
use App\Notifications\Pulse\Support\PulseSignal;
use App\Services\Crm\Mail\MailRuleEngine;
use App\Services\Crm\Mail\MailStream;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Правила-фильтры: «содержит инн такой-то и акт-сверки → отправить туда-то».
 *
 * Кейс заказчика целиком: правило с именем, условиями по меткам и получателями,
 * плюс исключение через «не содержит».
 */
class MailRulesTest extends TestCase
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

        $this->manager = User::factory()->create(['email' => 'manager@pecado.ru']);
        $this->manager->assignRole('sales-manager');
        $profile = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->client = User::factory()->create([
            'personal_manager_id' => $profile->id,
            'email' => 'client@example.com',
        ]);

        config(['mail_stream.enabled' => true]);
    }

    private function documentLetter(string $type = 'reconciliation_act', string $taxId = '7701234567'): CrmEmail
    {
        return app(MailStream::class)->capture(new PulseSignal(
            eventKey: 'documents.published',
            clientUserId: $this->client->id,
            data: [
                'document_type' => $type,
                'document_number' => (string) fake()->numberBetween(1, 9999),
                'document_title' => 'Акт сверки',
                'company_tax_id' => $taxId,
            ],
            view: ['title' => 'Акт сверки', 'body' => 'Документ выложен в кабинет'],
        ));
    }

    #[Test]
    public function afonina_rule_catches_the_letter_and_sets_recipients(): void
    {
        CrmMailRule::factory()->create([
            'name' => 'Акты Афониной',
            'conditions' => ['all' => [
                ['field' => 'tag', 'op' => 'has_tag', 'value' => 'инн:7701234567'],
                ['field' => 'tag', 'op' => 'has_tag', 'value' => 'акт-сверки'],
            ]],
            'recipients' => ['buh@romashka.ru', 'glavbuh@romashka.ru'],
        ]);

        $letter = $this->documentLetter();

        $this->assertSame(EmailStatus::DRAFT, $letter->status);
        $this->assertSame(['buh@romashka.ru', 'glavbuh@romashka.ru'], $letter->to);
    }

    #[Test]
    public function tag_is_compared_whole_not_as_a_substring(): void
    {
        // ИНН 7701234567 не должен находиться внутри 77012345678 — иначе письмо
        // ушло бы чужому бухгалтеру, и объяснить это было бы нечем.
        CrmMailRule::factory()->byTag('инн:7701234567')->create();

        $letter = $this->documentLetter(taxId: '77012345678');

        $this->assertSame(EmailStatus::UNMATCHED, $letter->status);
    }

    #[Test]
    public function exclusion_is_expressed_by_not_contains(): void
    {
        // Приоритетов и «остановки разбора» здесь нет: «всё, кроме УПД»
        // выражается условием, а не порядком правил.
        CrmMailRule::factory()->create([
            'name' => 'Документы, кроме УПД',
            'conditions' => ['all' => [
                ['field' => 'tag', 'op' => 'has_tag', 'value' => 'документы'],
                ['field' => 'tag', 'op' => 'not_has_tag', 'value' => 'упд'],
            ]],
            'recipients' => ['docs@romashka.ru'],
        ]);

        $this->assertSame(EmailStatus::DRAFT, $this->documentLetter()->status);
        $this->assertSame(EmailStatus::UNMATCHED, $this->documentLetter('upd')->status);
    }

    #[Test]
    public function address_in_two_rules_is_not_duplicated(): void
    {
        CrmMailRule::factory()->byTag('документы')->to(['buh@romashka.ru'])->create();
        CrmMailRule::factory()->byTag('акт-сверки')->to(['buh@romashka.ru', 'dir@romashka.ru'])->create();

        $letter = $this->documentLetter();

        $this->assertSame(['buh@romashka.ru', 'dir@romashka.ru'], $letter->to);
    }

    #[Test]
    public function client_and_manager_are_resolved_by_the_letter(): void
    {
        // Иначе правило «на почту клиента» пришлось бы заводить отдельно
        // на каждого из восьмисот.
        CrmMailRule::factory()->byTag('документы')->to(['клиент', 'менеджер'])->create();

        $letter = $this->documentLetter();

        $this->assertSame(['client@example.com', 'manager@pecado.ru'], $letter->to);
    }

    #[Test]
    public function inactive_rule_catches_nothing(): void
    {
        CrmMailRule::factory()->byTag('документы')->create(['is_active' => false]);

        $this->assertSame(EmailStatus::UNMATCHED, $this->documentLetter()->status);
    }

    #[Test]
    public function rule_created_today_picks_up_yesterdays_letters(): void
    {
        // Иначе менеджер настроит фильтр по сводке непойманного и не увидит
        // ни одного письма, ради которого настраивал.
        $letter = $this->documentLetter();
        $this->assertSame(EmailStatus::UNMATCHED, $letter->status);

        $rule = CrmMailRule::factory()->byTag('акт-сверки')->to(['buh@romashka.ru'])->create();

        $moved = app(MailRuleEngine::class)->reapplyToUnmatched($rule);

        $this->assertSame(1, $moved);
        $this->assertSame(EmailStatus::DRAFT, $letter->refresh()->status);
        $this->assertSame(['buh@romashka.ru'], $letter->to);
    }

    #[Test]
    public function preview_shows_real_letters_from_the_stream(): void
    {
        $this->documentLetter();
        $this->documentLetter('upd');

        $response = $this->actingAs($this->manager)->postJson(route('crm.emails.rules.preview'), [
            'match' => 'all',
            'conditions' => [['field' => 'tag', 'op' => 'has_tag', 'value' => 'акт-сверки']],
        ]);

        $response->assertOk()->assertJsonPath('total', 1);
        $this->assertStringContainsString('Акт сверки', $response->json('letters.0.subject'));
    }

    #[Test]
    public function rule_is_created_through_the_form(): void
    {
        $response = $this->actingAs($this->manager)->postJson(route('crm.emails.rules.store'), [
            'name' => 'Акты Афониной',
            'match' => 'all',
            'conditions' => [['field' => 'tag', 'op' => 'has_tag', 'value' => 'акт-сверки']],
            'recipients' => ['buh@romashka.ru'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('rule.name', 'Акты Афониной')
            ->assertJsonPath('rule.conditions_text', 'Метка есть метка акт-сверки');

        $this->assertSame(1, CrmMailRule::query()->count());
    }

    #[Test]
    public function condition_by_client_profile_field(): void
    {
        // Отдельным условием, а не склейкой профиля в общую строку: иначе
        // фильтр «Ромашка» поймал бы и клиента «Ромашка», и того, у кого
        // в заметке «раньше работал в Ромашке».
        $this->client->forceFill(['city' => 'Тюмень'])->save();

        CrmMailRule::factory()->to(['tmn@romashka.ru'])->create([
            'conditions' => ['all' => [
                ['field' => 'client_city', 'op' => 'contains', 'value' => 'Тюмень'],
            ]],
        ]);

        $this->assertSame(['tmn@romashka.ru'], $this->documentLetter()->to);
    }

    #[Test]
    public function condition_by_expression(): void
    {
        CrmMailRule::factory()->to(['buh@romashka.ru'])->create([
            'conditions' => ['all' => [
                ['field' => 'subject', 'op' => 'regex', 'value' => 'акт\\s+сверки'],
            ]],
        ]);

        $this->assertSame(['buh@romashka.ru'], $this->documentLetter()->to);
    }

    #[Test]
    public function validation_errors_are_in_russian(): void
    {
        $response = $this->actingAs($this->manager)->postJson(route('crm.emails.rules.store'), [
            'name' => '',
            'match' => 'all',
            'conditions' => [],
            'recipients' => [],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('название', $response->json('errors.name.0'));
        $this->assertStringContainsString('получателя', $response->json('errors.recipients.0'));
    }

    #[Test]
    public function broken_expression_is_refused_before_saving(): void
    {
        // Кривое выражение либо молчит, либо ловит всё, и заметить это
        // можно только по последствиям.
        $response = $this->actingAs($this->manager)->postJson(route('crm.emails.rules.store'), [
            'name' => 'Выражение',
            'match' => 'all',
            'conditions' => [['field' => 'subject', 'op' => 'regex', 'value' => '([a-z']],
            'recipients' => ['buh@romashka.ru'],
        ]);

        $response->assertStatus(422);
        $errors = $response->json('errors');
        $this->assertArrayHasKey('conditions.0.value', $errors);
        $this->assertStringContainsString('ошибкой', $errors['conditions.0.value'][0]);
    }
}
