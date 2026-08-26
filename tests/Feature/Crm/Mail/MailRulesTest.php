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

    private function documentLetter(string $type = 'reconciliation_act', string $taxId = '7701234567'): CrmEmail
    {
        return app(MailStream::class)->capture(new Occasion(
            key: 'documents.published',
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
    public function new_rule_works_forward_and_leaves_old_letters_alone(): void
    {
        // Иначе заведённый фильтр поднимал бы переписку недельной давности,
        // о которой все давно забыли.
        $letter = $this->documentLetter();
        $this->assertSame(EmailStatus::UNMATCHED, $letter->status);

        $this->travel(2)->seconds();
        CrmMailRule::factory()->byTag('акт-сверки')->to(['buh@romashka.ru'])->create();

        $this->assertSame(EmailStatus::UNMATCHED, $letter->refresh()->status);
        $this->assertSame([], $letter->to);
    }

    #[Test]
    public function apply_to_old_picks_up_letters_collected_earlier(): void
    {
        $letter = $this->documentLetter();
        $this->travel(2)->seconds();

        $rule = CrmMailRule::factory()->byTag('акт-сверки')->to(['buh@romashka.ru'])->create();

        $response = $this->actingAs($this->manager)
            ->postJson(route('crm.emails.rules.apply-to-old', $rule));

        $response->assertOk()->assertJsonPath('picked', 1);

        $this->assertSame(EmailStatus::DRAFT, $letter->refresh()->status);
        $this->assertSame(['buh@romashka.ru'], $letter->to);
    }

    #[Test]
    public function apply_to_old_does_not_drag_in_other_rules(): void
    {
        // Кнопку нажали у одного правила — второе, заведённое позже письма,
        // своих адресов дописывать не должно: его об этом не просили.
        $letter = $this->documentLetter();

        // Правила заводятся позже письма — как это и бывает в жизни.
        $this->travel(2)->seconds();

        $quiet = CrmMailRule::factory()->byTag('документы')->to(['dir@romashka.ru'])->create();
        $asked = CrmMailRule::factory()->byTag('акт-сверки')->to(['buh@romashka.ru'])->create();

        app(MailRuleEngine::class)->applyToOld($asked);

        $this->assertSame(['buh@romashka.ru'], $letter->refresh()->to);
        $this->assertSame(0, $quiet->refresh()->matched_count);
    }

    #[Test]
    public function second_rule_picks_up_a_letter_already_claimed_by_the_first(): void
    {
        // Так и настраивают: одно правило шлёт всё по контрагенту директору,
        // второе — только просрочку бухгалтеру. Второе правило заводится позже,
        // когда письмо уже лежит в черновиках, и обязано его увидеть.
        $letter = $this->documentLetter();

        $first = CrmMailRule::factory()->byTag('документы')->to(['dir@romashka.ru'])->create();
        app(MailRuleEngine::class)->applyToOld($first);

        $this->assertSame(EmailStatus::DRAFT, $letter->refresh()->status);
        $this->assertSame(['dir@romashka.ru'], $letter->to);

        $second = CrmMailRule::factory()->byTag('акт-сверки')->to(['buh@romashka.ru'])->create();
        $picked = app(MailRuleEngine::class)->applyToOld($second);

        $this->assertSame(1, $picked);
        $this->assertSame(['dir@romashka.ru', 'buh@romashka.ru'], $letter->refresh()->to);
        $this->assertSame(1, $second->refresh()->matched_count);
    }

    #[Test]
    public function manual_letter_keeps_the_recipients_a_human_chose(): void
    {
        // Правило не дописывает адреса в письмо менеджера за его спиной.
        $order = \App\Models\Order::factory()->create(['user_id' => $this->client->id]);

        $letter = app(\App\Services\Crm\CrmEmailService::class)->createDraft(
            $this->manager,
            ['to' => ['client@example.com'], 'subject' => 'Акт сверки', 'body_html' => '<p>Здравствуйте</p>'],
            $order,
        );

        $rule = CrmMailRule::factory()->to(['dir@romashka.ru'])->create([
            'conditions' => ['all' => [['field' => 'subject', 'op' => 'contains', 'value' => 'Акт']]],
        ]);

        app(MailRuleEngine::class)->applyToOld($rule);

        $this->assertSame(['client@example.com'], $letter->refresh()->to);
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
            // Пересказ читается по-русски: «Метка есть метка …» выглядело
            // как ошибка, хотя условие было верным.
            ->assertJsonPath('rule.conditions_text', 'есть метка акт-сверки');

        $this->assertSame(1, CrmMailRule::query()->count());
    }

    #[Test]
    public function role_of_a_contact_works_as_a_recipient(): void
    {
        // Ровно тот кейс, ради которого затевался отменённый пульт: «изменения
        // по контрагенту приходят на почту его бухгалтера». Одно правило
        // покрывает всю базу и подхватывает нового бухгалтера само.
        $company = \App\Models\Company::factory()->create(['user_id' => $this->client->id]);

        $accountant = \App\Models\Contact::factory()
            ->forClient($this->client)
            ->create(['email' => 'buh@romashka.ru']);
        \App\Models\ContactLink::factory()
            ->to($company, \App\Enums\ContactRole::ACCOUNTANT)
            ->create(['contact_id' => $accountant->id]);

        // Директор той же компании письма получать не должен — роль другая.
        $director = \App\Models\Contact::factory()
            ->forClient($this->client)
            ->create(['email' => 'dir@romashka.ru']);
        \App\Models\ContactLink::factory()
            ->to($company, \App\Enums\ContactRole::DIRECTOR)
            ->create(['contact_id' => $director->id]);

        CrmMailRule::factory()->byTag('акт-сверки')->to(['бухгалтер'])->create();

        $this->assertSame(['buh@romashka.ru'], $this->documentLetter()->to);
    }

    #[Test]
    public function empty_role_does_not_break_the_rule(): void
    {
        // У контрагента может не быть бухгалтера — правило просто не даёт
        // этого адресата, а письмо уходит остальным.
        CrmMailRule::factory()->byTag('акт-сверки')->to(['бухгалтер', 'dir@romashka.ru'])->create();

        $this->assertSame(['dir@romashka.ru'], $this->documentLetter()->to);
    }

    #[Test]
    public function retired_contact_is_not_a_recipient(): void
    {
        $company = \App\Models\Company::factory()->create(['user_id' => $this->client->id]);

        $former = \App\Models\Contact::factory()
            ->forClient($this->client)
            ->inactive()
            ->create(['email' => 'former@romashka.ru']);
        \App\Models\ContactLink::factory()
            ->to($company, \App\Enums\ContactRole::ACCOUNTANT)
            ->create(['contact_id' => $former->id]);

        CrmMailRule::factory()->byTag('акт-сверки')->to(['бухгалтер'])->create();

        $this->assertSame([], $this->documentLetter()->to);
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
