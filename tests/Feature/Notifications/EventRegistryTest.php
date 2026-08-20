<?php

namespace Tests\Feature\Notifications;

use App\Notifications\Pulse\Events\AbstractNotificationEvent;
use App\Notifications\Pulse\Support\FieldSpec;
use App\Services\Notifications\Pulse\ConditionValidator;
use App\Services\Notifications\Pulse\NotificationEventRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Реестр событий и валидация условий.
 *
 * Ключевая проверка карточки — расширяемость: добавление события не должно
 * требовать правок в движке, конструкторе и журнале.
 */
class EventRegistryTest extends TestCase
{
    private NotificationEventRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = app(NotificationEventRegistry::class);
    }

    #[Test]
    #[TestDox('Все семь событий заказов зарегистрированы и включены')]
    public function order_events_are_registered(): void
    {
        foreach ([
            'orders.created',
            'orders.status_changed',
            'orders.items_updated',
            'orders.attributes_updated',
            'orders.shortfall',
            'orders.substitution_offered',
            'orders.shipped',
        ] as $key) {
            $this->assertTrue($this->registry->exists($key), "событие {$key} не зарегистрировано");
            $this->assertTrue($this->registry->isEnabled($key), "событие {$key} выключено");
        }
    }

    #[Test]
    #[TestDox('Маска раскрывается в точный ключ, домен и «все события»')]
    public function match_keys_include_masks(): void
    {
        $this->assertSame(
            ['orders.status_changed', 'orders.*', '*'],
            $this->registry->matchKeys('orders.status_changed'),
        );
    }

    #[Test]
    #[TestDox('В правиле допустимы точный ключ и маски, но не выдумка')]
    public function rule_keys_are_validated(): void
    {
        $this->assertTrue($this->registry->isValidRuleKey('orders.status_changed'));
        $this->assertTrue($this->registry->isValidRuleKey('orders.*'));
        $this->assertTrue($this->registry->isValidRuleKey('*'));

        $this->assertFalse($this->registry->isValidRuleKey('orders.nonexistent'));
        $this->assertFalse($this->registry->isValidRuleKey('wat.*'));
    }

    #[Test]
    #[TestDox('Событие отдаёт свои поля вместе с общими')]
    public function fields_merge_common_and_own(): void
    {
        $fields = $this->registry->fieldsFor('orders.status_changed');

        $this->assertArrayHasKey('status', $fields, 'нет собственного поля события');
        $this->assertArrayHasKey('company_tax_id', $fields, 'нет общего поля сигнала');
    }

    #[Test]
    #[TestDox('У маски доступны только общие поля')]
    public function mask_exposes_common_fields_only(): void
    {
        $fields = $this->registry->fieldsFor('orders.*');

        $this->assertArrayNotHasKey('status', $fields);
        $this->assertArrayHasKey('company_tax_id', $fields);
    }

    #[Test]
    #[TestDox('Варианты статуса берутся из перечисления, а не дублируются')]
    public function enum_options_come_from_enum(): void
    {
        $status = $this->registry->fieldsFor('orders.status_changed')['status'];

        $this->assertSame(FieldSpec::TYPE_ENUM, $status->type);
        $this->assertNotEmpty($status->options);

        $values = array_column($status->options, 'value');
        $this->assertContains('closed', $values);
        // Метка русская — её видит менеджер в конструкторе
        $labels = array_column($status->options, 'label');
        $this->assertNotContains('closed', $labels);
    }

    #[Test]
    #[TestDox('Каталог для конструктора сгруппирован и начинается с маски раздела')]
    public function constructor_catalog_is_grouped(): void
    {
        $catalog = $this->registry->groupedForConstructor();

        $this->assertNotEmpty($catalog);

        $orders = collect($catalog)->firstWhere('group', 'Заказы');
        $this->assertNotNull($orders, 'группа «Заказы» не найдена');

        $this->assertSame('orders.*', $orders['items'][0]['value'], 'маска раздела должна идти первой');

        // Менеджер видит русские названия, а не технические ключи
        foreach ($orders['items'] as $item) {
            $this->assertNotSame($item['value'], $item['label']);
        }
    }

    #[Test]
    #[TestDox('Метки события содержат контрагента и тип события')]
    public function tags_include_subject_and_event(): void
    {
        $event = $this->registry->get('orders.shortfall');

        $tags = $event->tags([
            'client_user_id' => 42,
            'company_id' => 7,
            'company_tax_id' => '7701234567',
            'is_full_cancel' => true,
        ]);

        $this->assertContains('инн:7701234567', $tags);
        $this->assertContains('партнёр:42', $tags);
        $this->assertContains('событие:orders.shortfall', $tags);
        $this->assertContains('раздел:orders', $tags);
        $this->assertContains('недобор:есть', $tags);
        $this->assertContains('недобор:полный', $tags);
    }

    #[Test]
    #[TestDox('Пустые поля события в метки не попадают')]
    public function empty_fields_do_not_produce_tags(): void
    {
        $tags = $this->registry->get('orders.created')->tags(['client_user_id' => 42]);

        foreach ($tags as $tag) {
            $this->assertStringNotContainsString(':null', $tag);
            $this->assertStringNotContainsString(':,', $tag);
        }

        $this->assertNotContains('инн:', $tags);
    }

    #[Test]
    #[TestDox('Условие с неизвестным полем отбивается с русским пояснением')]
    public function unknown_field_is_rejected(): void
    {
        $errors = app(ConditionValidator::class)->validate(
            ['field' => 'wat', 'op' => '=', 'value' => 'x'],
            'orders.status_changed',
        );

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('недоступно', $errors[0]);
    }

    #[Test]
    #[TestDox('Оператор, неподходящий типу поля, отбивается')]
    public function wrong_operator_is_rejected(): void
    {
        // Сумма — число, «содержит подстроку» к ней неприменимо
        $errors = app(ConditionValidator::class)->validate(
            ['field' => 'total', 'op' => 'contains', 'value' => '100'],
            'orders.status_changed',
        );

        $this->assertNotEmpty($errors);
    }

    #[Test]
    #[TestDox('Значение вне списка вариантов отбивается: опечатка не должна молчать')]
    public function value_outside_enum_is_rejected(): void
    {
        $errors = app(ConditionValidator::class)->validate(
            ['field' => 'status', 'op' => 'in', 'value' => ['closed', 'zakryt']],
            'orders.status_changed',
        );

        $this->assertNotEmpty($errors);
    }

    #[Test]
    #[TestDox('Слишком глубокое дерево условий отбивается')]
    public function too_deep_tree_is_rejected(): void
    {
        $deep = ['all' => [['any' => [['all' => [['any' => [
            ['field' => 'status', 'op' => '=', 'value' => 'closed'],
        ]]]]]]]];

        $errors = app(ConditionValidator::class)->validate($deep, 'orders.status_changed');

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('вложенность', $errors[0]);
    }

    #[Test]
    #[TestDox('Корректное условие проходит валидацию')]
    public function valid_conditions_pass(): void
    {
        $validator = app(ConditionValidator::class);

        $this->assertTrue($validator->passes(['all' => [
            ['field' => 'status', 'op' => 'in', 'value' => ['closed']],
            ['field' => 'total', 'op' => '>=', 'value' => 100000],
        ]], 'orders.status_changed'));

        $this->assertTrue($validator->passes(['op' => 'has_tag', 'value' => 'инн:7701234567'], 'orders.*'));
        $this->assertTrue($validator->passes(null, 'orders.status_changed'));
    }

    #[Test]
    #[TestDox('Событие описывается одним классом: движок и интерфейс не трогаются')]
    public function adding_event_touches_only_its_class(): void
    {
        // Регистрируем событие «на лету» — так же, как это сделает разработчик,
        // добавив класс и строку в конфиг. Ничего больше править не требуется.
        config(['notification_pulse.events' => array_merge(
            config('notification_pulse.events'),
            [FakeProbeEvent::class],
        )]);

        $registry = new NotificationEventRegistry;

        $this->assertTrue($registry->exists('orders.probe'));
        $this->assertArrayHasKey('probe_field', $registry->fieldsFor('orders.probe'));
        $this->assertContains('проба:да', $registry->get('orders.probe')->tags([]));

        $orders = collect($registry->groupedForConstructor())->firstWhere('group', 'Заказы');
        $this->assertContains('Пробное событие', array_column($orders['items'], 'label'));
    }
}

/**
 * Событие-пустышка для проверки расширяемости реестра.
 */
class FakeProbeEvent extends AbstractNotificationEvent
{
    public function key(): string
    {
        return 'orders.probe';
    }

    public function label(): string
    {
        return 'Пробное событие';
    }

    public function fields(): array
    {
        return ['probe_field' => new FieldSpec('probe_field', 'Пробное поле', FieldSpec::TYPE_NUMBER)];
    }

    protected function ownTags(array $data): array
    {
        return ['проба:да'];
    }
}
