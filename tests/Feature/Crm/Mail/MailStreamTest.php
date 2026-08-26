<?php

namespace Tests\Feature\Crm\Mail;

use App\Enums\Crm\EmailStatus;
use App\Models\CrmEmail;
use App\Models\Order;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Crm\Mail\MailStream;
use App\Support\Notifications\Occasion;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Поток писем: повод превращается в обычное письмо-черновик.
 *
 * Проверяется главное обещание модели: система не заводит новых сущностей,
 * а кладёт письмо туда же, где лежат письма менеджера.
 */
class MailStreamTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->manager = User::factory()->create(['email' => 'manager@pecado.ru']);
        $this->manager->assignRole('sales-manager');
        $profile = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->client = User::factory()->create([
            'personal_manager_id' => $profile->id,
            'email' => 'client@example.com',
        ]);

        config(['mail_stream.enabled' => true]);
    }

    private function capture(array $overrides = []): ?CrmEmail
    {
        $signal = new Occasion(
            key: $overrides['eventKey'] ?? 'orders.items_updated',
            clientUserId: $this->client->id,
            data: $overrides['data'] ?? [
                'order_number' => 'П-100',
                'removed_count' => 1,
                'has_removed' => true,
                'total' => 120000,
            ],
            view: $overrides['view'] ?? [
                'title' => 'Изменение по заказу П-100',
                'body' => 'Из заказа выбыла позиция.',
                'rows' => [['type' => 'note', 'text' => 'Позиция снята складом']],
            ],
            occurredAt: $overrides['occurredAt'] ?? null,
        );

        return app(MailStream::class)->capture($signal);
    }

    #[Test]
    public function occasion_becomes_a_letter_in_the_same_list(): void
    {
        $letter = $this->capture();

        $this->assertNotNull($letter);
        $this->assertSame(CrmEmail::ORIGIN_SYSTEM, $letter->origin);
        $this->assertSame($this->client->id, $letter->client_user_id);
        // Автор — персональный менеджер: письмо про его клиента должно попасть
        // в его папку, а ответ клиента — прийти живому человеку.
        $this->assertSame($this->manager->id, $letter->user_id);
        $this->assertSame('manager@pecado.ru', $letter->reply_to);
        $this->assertStringContainsString('выбыла позиция', $letter->body_html);
    }

    #[Test]
    public function letter_carries_tags_to_be_caught_by(): void
    {
        $tags = $this->capture()->tagList();

        $this->assertContains('заказ', $tags);
        $this->assertContains('состав-изменён', $tags);
        $this->assertContains('недобор', $tags);
        $this->assertContains('партнёр:'.$this->client->display_name, $tags);
    }

    #[Test]
    public function уведомление_адресуется_по_умолчанию_типа(): void
    {
        // Раньше письмо ждало правила и лежало «мимо фильтров». Теперь адресата
        // даёт настройка партнёра, а при её отсутствии — умолчание типа.
        $letter = $this->capture();

        $this->assertContains($this->client->email, (array) $letter->to);
    }

    #[Test]
    public function old_occasion_produces_nothing(): void
    {
        // Первичная выгрузка истории из 1С не должна залить поток тысячами писем.
        $letter = $this->capture(['occurredAt' => now()->subDays(3)]);

        $this->assertNull($letter);
        $this->assertSame(0, CrmEmail::query()->count());
    }

    #[Test]
    public function series_of_edits_of_one_order_makes_one_letter(): void
    {
        // 1С правит заказ построчно: без склейки менеджер получил бы
        // десять писем об одном изменении.
        $this->capture();
        $this->capture();
        $this->capture();

        $this->assertSame(1, CrmEmail::query()->count());
    }

    #[Test]
    public function партия_заказов_склеивается_в_одно_письмо(): void
    {
        // Поведение изменено осознанно (note-05): 1С правит заказы построчно,
        // и восемь смен статуса за две минуты — одно событие глазами клиента.
        // Раньше каждый заказ давал своё письмо, и активный клиент получал
        // по два десятка писем в день.
        $this->capture();
        $this->capture(['data' => ['order_number' => 'П-200']]);

        $this->assertSame(1, CrmEmail::query()->count());
    }

    #[Test]
    public function stream_switched_off_collects_nothing(): void
    {
        config(['mail_stream.enabled' => false]);

        $this->assertNull($this->capture());
        $this->assertSame(0, CrmEmail::query()->count());
    }

    #[Test]
    public function disabled_domain_collects_nothing(): void
    {
        config(['mail_stream.domains.orders' => false]);

        $this->assertNull($this->capture());
    }

    #[Test]
    public function real_order_change_lands_in_the_stream(): void
    {
        // Сквозная проверка проводки: письмо появляется из настоящего события
        // домена, а не только из вызова сборщика в тесте.
        $order = Order::factory()->create(['user_id' => $this->client->id]);

        \App\Models\OrderChangeLog::create([
            'order_id' => $order->id,
            'user_id' => $this->client->id,
            'type' => 'items_updated',
            'source' => 'erp',
            'summary' => 'Состав заказа изменён',
            'changes' => ['removed' => [['product_name' => 'Товар', 'quantity' => 1]]],
            'old_total' => 1000,
            'new_total' => 500,
        ]);

        $letter = CrmEmail::query()->where('origin', CrmEmail::ORIGIN_SYSTEM)->firstOrFail();

        $this->assertSame('orders.items_updated', $letter->origin_event);
        $this->assertContains('недобор', $letter->tagList());
        // Числа изменения обязаны доехать до условий правил: на них строятся
        // фильтры вида «выбыло больше двух позиций».
        $this->assertSame(1, $letter->origin_data['removed_count']);
        $this->assertStringContainsString('Товар', $letter->body_html);
    }

    #[Test]
    public function letter_about_a_printed_document_does_not_break_the_list(): void
    {
        // Разбор прода: письмо о выложенном документе ссылалось на печатную
        // форму, которой не было в карте CRM, и describe() ронял весь список
        // писем пятисоткой. Теперь тип в карте есть.
        $document = \App\Models\PrintedDocument::factory()->create([
            'user_id' => $this->client->id,
            'number' => '1023',
        ]);

        $letter = app(MailStream::class)->capture(new Occasion(
            key: 'documents.published',
            clientUserId: $this->client->id,
            subject: $document,
            data: ['document_type' => 'reconciliation_act', 'document_number' => '1023', 'document_title' => 'Акт сверки'],
            view: ['title' => 'Акт сверки', 'body' => 'Документ выложен'],
        ));

        $payload = app(\App\Services\Crm\CrmEmailService::class)->payload($letter->fresh(), $this->manager);

        $this->assertSame('printed_document', $payload['entity']['type']);
        $this->assertStringContainsString('1023', $payload['entity']['title']);
    }

    #[Test]
    public function unknown_attachment_costs_the_binding_not_the_page(): void
    {
        // Одна непонятная строка не имеет права уносить страницу: привязку
        // показать не смогли — показываем письмо без неё.
        $letter = CrmEmail::factory()->by($this->manager)->on($this->client)->create();

        // Тип, которого нет в карте CRM.
        $letter->forceFill([
            'related_type' => \App\Models\Media::class,
            'related_id' => 1,
        ])->save();

        $payload = app(\App\Services\Crm\CrmEmailService::class)->payload($letter->fresh(), $this->manager);

        $this->assertNull($payload['entity']);
        $this->assertSame($letter->subject, $payload['subject']);
    }

    #[Test]
    public function manual_letter_is_part_of_the_same_stream(): void
    {
        $order = Order::factory()->create(['user_id' => $this->client->id]);

        $letter = app(\App\Services\Crm\CrmEmailService::class)->createDraft(
            $this->manager,
            ['to' => ['buh@example.com'], 'subject' => 'Счёт', 'body_html' => '<p>Здравствуйте</p>'],
            $order,
        );

        // Правило «всё по этому контрагенту» обязано ловить и письма менеджера,
        // иначе поток был бы общим только на словах.
        $this->assertSame(CrmEmail::ORIGIN_MANUAL, $letter->origin);
        $this->assertContains('партнёр:'.$this->client->display_name, $letter->tagList());
        $this->assertSame(EmailStatus::DRAFT, $letter->status);
    }
}
