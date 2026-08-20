<?php

namespace Tests\Unit\Notifications;

use App\Services\Notifications\Pulse\ConditionEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Вычисление условий правила.
 *
 * Ядро пульта: ошибка здесь означает письмо не тому или молчание там, где
 * менеджер ждёт уведомления. Поэтому таблица кейсов на каждый оператор,
 * включая null и пустые значения.
 */
class ConditionEvaluatorTest extends TestCase
{
    private ConditionEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new ConditionEvaluator;
    }

    #[Test]
    #[TestDox('Правило без условий срабатывает всегда')]
    public function empty_conditions_always_match(): void
    {
        $this->assertTrue($this->evaluator->matches(null, ['status' => 'closed']));
        $this->assertTrue($this->evaluator->matches([], ['status' => 'closed']));
    }

    /**
     * @return array<string, array{mixed, string, mixed, bool}>
     */
    public static function operators(): array
    {
        return [
            'равно строке' => ['closed', '=', 'closed', true],
            'равно: не совпало' => ['shipping', '=', 'closed', false],
            'равно числу как строке' => ['10', '=', 10, true],
            'равно булеву' => [true, '=', true, true],
            'равно: true и строка' => [true, '=', 'true', true],
            'не равно' => ['shipping', '!=', 'closed', true],
            'входит в список' => ['closed', 'in', ['shipping', 'closed'], true],
            'не входит в список' => ['pending', 'in', ['shipping', 'closed'], false],
            'исключён из списка' => ['pending', 'not_in', ['shipping', 'closed'], true],
            'больше' => [150, '>', 100, true],
            'больше: равно не считается' => [100, '>', 100, false],
            'больше или равно' => [100, '>=', 100, true],
            'меньше' => [50, '<', 100, true],
            'меньше или равно' => [100, '<=', 100, true],
            'диапазон: внутри' => [50, 'between', [10, 100], true],
            'диапазон: на границе' => [100, 'between', [10, 100], true],
            'диапазон: снаружи' => [150, 'between', [10, 100], false],
            'диапазон: перевёрнутый тоже работает' => [50, 'between', [100, 10], true],
            'подстрока' => ['Акт сверки', 'contains', 'сверк', true],
            'подстрока без учёта регистра' => ['АКТ СВЕРКИ', 'contains', 'сверк', true],
            'подстроки нет' => ['Накладная', 'contains', 'сверк', false],
            'массив содержит элемент' => [['city', 'comment'], 'contains', 'comment', true],
            'массив не содержит' => [['city'], 'contains', 'comment', false],
            'пусто: null' => [null, 'is_empty', null, true],
            'пусто: пустая строка' => ['', 'is_empty', null, true],
            'пусто: ноль значением остаётся' => [0, 'is_empty', null, false],
            'пусто: пустой массив' => [[], 'is_empty', null, true],
            'не пусто' => ['x', 'not_empty', null, true],
            'сравнение с null не проходит' => [null, '>', 10, false],
            'нечисловое сравнение не проходит' => ['abc', '>', 10, false],
            'неизвестный оператор не срабатывает' => ['x', 'wat', 'x', false],
        ];
    }

    #[Test]
    #[DataProvider('operators')]
    public function operator_behaves_as_expected(mixed $actual, string $op, mixed $expected, bool $result): void
    {
        $matched = $this->evaluator->matches(
            ['field' => 'value', 'op' => $op, 'value' => $expected],
            ['value' => $actual],
        );

        $this->assertSame($result, $matched);
    }

    #[Test]
    #[TestDox('Отсутствующее поле не ломает разбор и не совпадает')]
    public function missing_field_does_not_match(): void
    {
        $this->assertFalse($this->evaluator->matches(
            ['field' => 'nope', 'op' => '=', 'value' => 'x'],
            ['status' => 'closed'],
        ));
    }

    #[Test]
    #[TestDox('Группа «все условия» требует выполнения каждого')]
    public function all_group_requires_every_child(): void
    {
        $data = ['status' => 'closed', 'total' => 150000];

        $this->assertTrue($this->evaluator->matches(['all' => [
            ['field' => 'status', 'op' => '=', 'value' => 'closed'],
            ['field' => 'total', 'op' => '>=', 'value' => 100000],
        ]], $data));

        $this->assertFalse($this->evaluator->matches(['all' => [
            ['field' => 'status', 'op' => '=', 'value' => 'closed'],
            ['field' => 'total', 'op' => '>=', 'value' => 200000],
        ]], $data));
    }

    #[Test]
    #[TestDox('Группа «любое из» срабатывает от одного условия')]
    public function any_group_needs_one_child(): void
    {
        $data = ['status' => 'closed', 'total' => 100];

        $this->assertTrue($this->evaluator->matches(['any' => [
            ['field' => 'status', 'op' => '=', 'value' => 'closed'],
            ['field' => 'total', 'op' => '>=', 'value' => 100000],
        ]], $data));
    }

    #[Test]
    #[TestDox('Пустая группа «любое из» не пропускает всё подряд')]
    public function empty_any_group_does_not_match(): void
    {
        // Недособранное правило безопаснее считать несработавшим, чем
        // пропускающим любое событие.
        $this->assertFalse($this->evaluator->matches(['any' => []], ['status' => 'closed']));
    }

    #[Test]
    #[TestDox('Вложенные группы разбираются')]
    public function nested_groups_work(): void
    {
        $conditions = ['all' => [
            ['field' => 'status', 'op' => '=', 'value' => 'closed'],
            ['any' => [
                ['field' => 'total', 'op' => '>=', 'value' => 100000],
                ['field' => 'order_type', 'op' => '=', 'value' => 'preorder'],
            ]],
        ]];

        $this->assertTrue($this->evaluator->matches($conditions, [
            'status' => 'closed', 'total' => 10, 'order_type' => 'preorder',
        ]));

        $this->assertFalse($this->evaluator->matches($conditions, [
            'status' => 'closed', 'total' => 10, 'order_type' => 'order',
        ]));
    }

    #[Test]
    #[TestDox('Метка сравнивается целиком, а не как кусок строки')]
    public function tag_match_is_exact(): void
    {
        $tags = ['инн:7701234567', 'событие:orders.shortfall'];

        $this->assertTrue($this->evaluator->matches(
            ['op' => 'has_tag', 'value' => 'инн:7701234567'],
            [],
            $tags,
        ));

        // Главная ловушка текстового поиска: ИНН лежит внутри более длинного
        // числа. При сравнении целиком ложного срабатывания не будет.
        $this->assertFalse($this->evaluator->matches(
            ['op' => 'has_tag', 'value' => 'инн:77012345678'],
            [],
            $tags,
        ));

        $this->assertFalse($this->evaluator->matches(
            ['op' => 'has_tag', 'value' => 'инн:770123456'],
            [],
            $tags,
        ));
    }

    #[Test]
    #[TestDox('Условие «нет метки» работает как отрицание')]
    public function not_has_tag_negates(): void
    {
        $tags = ['недобор:есть'];

        $this->assertTrue($this->evaluator->matches(['op' => 'not_has_tag', 'value' => 'недобор:полный'], [], $tags));
        $this->assertFalse($this->evaluator->matches(['op' => 'not_has_tag', 'value' => 'недобор:есть'], [], $tags));
    }

    #[Test]
    #[TestDox('Кейс заказчика: закрытие заказа контрагента Пупкина')]
    public function customer_case_closed_order(): void
    {
        $conditions = ['all' => [
            ['op' => 'has_tag', 'value' => 'инн:7701234567'],
            ['field' => 'status', 'op' => 'in', 'value' => ['closed']],
        ]];

        $pupkinClosed = ['status' => 'closed'];
        $pupkinTags = ['инн:7701234567', 'событие:orders.status_changed', 'статус:closed'];

        $this->assertTrue($this->evaluator->matches($conditions, $pupkinClosed, $pupkinTags));

        // Тот же статус, но другой контрагент — правило Пупкина не срабатывает
        $this->assertFalse($this->evaluator->matches($conditions, $pupkinClosed, ['инн:7709999999']));

        // Пупкин, но другой статус
        $this->assertFalse($this->evaluator->matches($conditions, ['status' => 'shipping'], $pupkinTags));
    }
}
