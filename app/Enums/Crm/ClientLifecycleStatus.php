<?php

namespace App\Enums\Crm;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Стадия работы с партнёром — поле сайта, о котором 1С не знает.
 *
 * Не путать со статусом лояльности (users.client_status_id): тем владеет 1С,
 * и в CRM он только читается.
 *
 * Стадии делятся на две группы, и порядок случаев — это порядок в интерфейсе:
 * сначала работа с партнёром (лид → в работе → активен → спящий → риск ухода),
 * потом причины ухода. Причина ухода — это отдельный статус, а не комментарий
 * к общему «Ушёл»: «ушёл к конкуренту» требует разбора, «закрылся» и «банкрот»
 * не требуют ничего, и различать их постфактум по тексту причины невозможно.
 *
 * Общий «Ушёл» оставлен намеренно — как посадочная площадка для тех, о ком
 * причина неизвестна. Без него менеджер выдумывал бы её задним числом, и группа
 * ухода перестала бы что-либо значить.
 */
enum ClientLifecycleStatus: string
{
    use HasLabeledOptions;

    // --- Работаем с партнёром ---
    case LEAD = 'lead';
    case IN_WORK = 'in_work';
    case ACTIVE = 'active';
    case SLEEPING = 'sleeping';
    case AT_RISK = 'at_risk';

    // --- Больше не покупает ---
    case COMPETITOR = 'competitor';
    case CLOSED = 'closed';
    case BANKRUPT = 'bankrupt';
    case CHURNED = 'churned';

    /** Группа «работаем» — партнёр ещё в обороте отдела. */
    public const GROUP_WORKING = 'working';

    /** Группа «не покупает» — терминальные стадии, из них не выходят сами. */
    public const GROUP_LOST = 'lost';

    /**
     * Стадии, которых больше нет, и чем они стали.
     *
     * Нужны для чтения истории смен и старых выгрузок отдела: в журнале
     * `crm_client_status_changes` значение записано навсегда, и без словаря
     * карточка показывала бы там сырое `hopeless`.
     */
    private const RETIRED = [
        // «Непреодолимо» из таблицы «План 2026»: подписи не понимал никто,
        // включая руководителя отдела. Разобрано вручную 08.09.2026.
        'hopeless' => ['Непреодолимо (устар.)', self::CHURNED],
        // «Закрывается» путалось с «Закрылся», хотя означало другое —
        // партнёр ещё наш, но сыпется. Стало «Риск ухода».
        'closing' => ['Закрывается (устар.)', self::AT_RISK],
    ];

    public function label(): string
    {
        return match ($this) {
            self::LEAD => 'Лид',
            self::IN_WORK => 'В работе',
            self::ACTIVE => 'Активен',
            self::SLEEPING => 'Спящий',
            self::AT_RISK => 'Риск ухода',
            self::COMPETITOR => 'Ушёл к конкуренту',
            self::CLOSED => 'Закрылся',
            self::BANKRUPT => 'Банкрот',
            self::CHURNED => 'Ушёл',
        };
    }

    /**
     * Пояснение к стадии — то, что менеджер должен понимать без обучения.
     *
     * Показывается подсказкой в списке вариантов: короткая подпись «Спящий»
     * сама по себе не отвечает на вопрос «а с какого момента спящий».
     */
    public function description(): string
    {
        return match ($this) {
            self::LEAD => 'Заведён, но ещё ни разу не отгружался',
            self::IN_WORK => 'Идут переговоры или первая сделка',
            self::ACTIVE => 'Покупает регулярно',
            self::SLEEPING => 'Давно не отгружался, но связь есть',
            self::AT_RISK => 'Ещё наш, но объёмы падают или перестал отвечать',
            self::COMPETITOR => 'Покупает у конкурента — разбор обязателен',
            self::CLOSED => 'Прекратил деятельность или закрыл направление',
            self::BANKRUPT => 'Признан банкротом, задолженность списана',
            self::CHURNED => 'Больше не покупает, причина не выяснена',
        };
    }

    /**
     * Цвет бейджа на фронте (Chakra colorPalette).
     *
     * Лестница читается цветом: работа с партнёром идёт от фиолетового к
     * оранжевому по мере остывания, уход к конкуренту — единственный красный
     * (это потеря, с которой работают), остальные терминальные — серые.
     */
    public function color(): string
    {
        return match ($this) {
            self::LEAD => 'purple',
            self::IN_WORK => 'blue',
            self::ACTIVE => 'green',
            self::SLEEPING => 'yellow',
            self::AT_RISK => 'orange',
            self::COMPETITOR => 'red',
            self::CLOSED, self::BANKRUPT, self::CHURNED => 'gray',
        };
    }

    /**
     * Партнёр больше не покупает: стадия терминальная.
     *
     * Из терминальной стадии система сама не выводит — возврат партнёра это
     * решение менеджера, а не следствие одной отгрузки.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPETITOR, self::CLOSED, self::BANKRUPT, self::CHURNED], true);
    }

    /** Ключ группы: с партнёром работают или он ушёл. */
    public function group(): string
    {
        return $this->isTerminal() ? self::GROUP_LOST : self::GROUP_WORKING;
    }

    /** Заголовок группы в селектах и меню. */
    public function groupLabel(): string
    {
        return $this->isTerminal() ? 'Больше не покупает' : 'Работаем с партнёром';
    }

    /**
     * Подпись значения, которого уже нет в перечне (история статусов).
     *
     * Возвращает null для действующих и вовсе незнакомых значений — вызывающий
     * сам решает, что показать вместо них.
     */
    public static function retiredLabel(string $value): ?string
    {
        return self::RETIRED[$value][0] ?? null;
    }

    /**
     * Во что превращается снятая стадия при переносе данных.
     */
    public static function replacementFor(string $value): ?self
    {
        return self::RETIRED[$value][1] ?? null;
    }

    /**
     * Стадии, на которых партнёр считается работающим.
     *
     * По ним ночная команда ищет тех, кто похож на спящего: у лида отгрузок
     * не было никогда, а ушедшего незачем возвращать в «спящие».
     *
     * @return list<self>
     */
    public static function workingStates(): array
    {
        return [self::IN_WORK, self::ACTIVE];
    }

    /**
     * Варианты для фронта вместе с цветом бейджа и группой.
     *
     * @return list<array{value: string, label: string, color: string, description: string, group: string, group_label: string}>
     */
    public static function optionsWithColor(): array
    {
        return array_map(
            fn (self $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
                'color' => $case->color(),
                'description' => $case->description(),
                'group' => $case->group(),
                'group_label' => $case->groupLabel(),
            ],
            self::cases(),
        );
    }
}
