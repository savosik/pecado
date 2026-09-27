<?php

namespace App\Services\Order;

use App\Enums\DeliveryMethod;
use App\Enums\OrderType;
use App\Models\Company;
use App\Models\Currency;
use App\Models\User;

/**
 * Заявка на создание заказов: всё, что нужно сборщику, и ничего лишнего.
 *
 * Вся вариативность каналов (проверка остатков в чекауте, дружелюбное урезание
 * количеств в клиентском API, лист отбора уценки) остаётся снаружи — здесь уже
 * готовые строки, разложенные по типам заказов. Сборщик от этого тупой
 * и предсказуемый, а добавление типа `promo` не трогает ни чекаут, ни API.
 */
final readonly class OrderDraft
{
    /**
     * @param  array<string, list<OrderLine>>  $groups  значение OrderType → строки заказа
     * @param  array<string, string|null>  $warehouseComments  переопределение комментария склада для отдельного типа
     */
    public function __construct(
        public User $user,
        public Company $company,
        public DeliveryMethod $deliveryMethod,
        public array $groups,
        public ?string $deliveryAddress = null,
        public ?string $comment = null,
        public ?string $managerComment = null,
        public ?string $warehouseComment = null,
        public ?int $cartId = null,
        public ?Currency $currency = null,
        public array $warehouseComments = [],
        // v16.9.0 (режим «Заказы в резерве», res-06): применяется ТОЛЬКО к группе
        // type=order — предзаказ/уценка/промо в резерв не ставятся.
        // reservedUntil — запрошенный сайтом срок; фактический вернёт 1С.
        public bool $reserve = false,
        public ?\Carbon\CarbonInterface $reservedUntil = null,
        // res-12: момент, от которого считан reservedUntil, — он же created_at резервного
        // заказа и `date` в order.created. 1С режет срок по date + предел, поэтому обе
        // даты обязаны стоять на одной секунде.
        public ?\Carbon\CarbonInterface $reservedFrom = null,
    ) {}

    /**
     * Комментарий склада для конкретного типа заказа.
     *
     * Уценка дописывает к общему комментарию лист отбора по партиям —
     * кладовщик собирает по нему, а не по WMS.
     */
    public function warehouseCommentFor(OrderType|string $type): ?string
    {
        $key = $type instanceof OrderType ? $type->value : $type;

        return ($this->warehouseComments[$key] ?? $this->warehouseComment) ?: null;
    }

    /**
     * Непустые группы в порядке объявления.
     *
     * @return array<string, list<OrderLine>>
     */
    public function filledGroups(): array
    {
        return array_filter($this->groups, static fn (array $lines) => $lines !== []);
    }
}
