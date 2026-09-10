<?php

namespace App\Enums;

use App\Enums\Crm\Concerns\HasLabeledOptions;

/**
 * Тип аккаунта: за кого система считает пользователя.
 *
 * До появления этого поля клиентом считался любой пользователь с непустым
 * personal_manager_id, а менеджера проставляет 1С по всем партнёрам подряд.
 * В `partner.created` типа партнёра нет, поэтому в CRM попадали закупщики,
 * админы и технические учётки — менеджер видел их среди своих клиентов
 * и ставил им планы продаж.
 *
 * Не флаг «клиент/не клиент», а перечисление: сотрудника разумно показывать
 * в админке как живого человека (у него роли, доступы, иногда собственные
 * заказы), служебную учётку — прятать везде, кроме списка пользователей,
 * а удалённую — не показывать нигде и не пускать на сайт. Различать их
 * постфактум по одному булеву полю было бы уже нечем.
 *
 * «Удалён» — мягкое удаление без трейта SoftDeletes: на users завязаны сотни
 * belongsTo-связей (заказы, задачи, журналы), и глобальный скоуп превратил бы
 * их в null в исторических записях. Вместо этого строка остаётся, а прячут её
 * те же выборки, что и служебные учётки, плюс список пользователей и вход.
 * Момент удаления — users.deleted_at, модель держит его в согласии с типом.
 *
 * Владелец поля — сайт. Обработчики 1С (HandlePartnerCreated/Updated) пишут
 * фиксированный набор колонок и user_kind не трогают, поэтому ручная пометка
 * переживает любое количество partner.updated — удалённого 1С не воскрешает.
 */
enum UserKind: string
{
    use HasLabeledOptions;

    case CLIENT = 'client';
    case STAFF = 'staff';
    case SERVICE = 'service';
    case DELETED = 'deleted';

    public function label(): string
    {
        return match ($this) {
            self::CLIENT => 'Клиент',
            self::STAFF => 'Сотрудник',
            self::SERVICE => 'Служебный',
            self::DELETED => 'Удалён',
        };
    }

    /**
     * Место такому аккаунту в CRM: клиенты, планы, задачи, аналитика продаж.
     */
    public function belongsInCrm(): bool
    {
        return $this === self::CLIENT;
    }

    /**
     * Мягко удалён: скрыт из всех списков, вход и API-токены закрыты.
     */
    public function isDeleted(): bool
    {
        return $this === self::DELETED;
    }

    /**
     * Типы, с которыми аккаунт заводят: удалённым его не создают, а делают.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function assignableOptions(): array
    {
        return array_values(array_filter(
            self::options(),
            fn (array $option): bool => $option['value'] !== self::DELETED->value,
        ));
    }
}
