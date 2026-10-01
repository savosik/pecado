<?php

namespace App\Enums\Order;

use App\Enums\Shortage\ShortageReasonCategory;

/**
 * Причина отмены строки заказа, как её сообщила 1С (`items[].cancel_reason`, v16.17.0).
 *
 * Это факт из 1С, а не разметка отдела продаж: причину недобора из справочника
 * (`order_items.cancel_reason_id`, {@see \App\Models\ShortageReason}) по-прежнему
 * выбирает менеджер. Справочник подробнее — девять причин и шесть категорий против
 * пяти значений 1С, — поэтому причина 1С служит подсказкой, а не заменой разметки.
 *
 * В схеме поле намеренно не перечисление: 1С вольна завести новую причину, и приём
 * заказа из-за этого останавливаться не должен. Всё незнакомое сводится к `OTHER`.
 */
enum OrderLineCancelReason: string
{
    /** «Нет остатка»: свободного остатка нет при приёме заказа или при переводе в «К отгрузке». */
    case OUT_OF_STOCK = 'out_of_stock';

    /** «Недобор при сборке»: товара не хватило на складе при сборке. */
    case SHORTAGE = 'shortage';

    /** «Отмена клиентом сайта»: строку или заказ отменили с сайта. */
    case CLIENT = 'client';

    /** «Истек срок резерва сайта». */
    case RESERVE_EXPIRED = 'reserve_expired';

    /** Любая другая причина 1С: маркетплейсы, ручные причины менеджера, неизвестное значение. */
    case OTHER = 'other';

    /**
     * Значение из payload → причина.
     *
     * Пустое значение — «причина не передана» (сообщения до v16.17.0), это `null`,
     * а не `OTHER`: «1С не сказала» и «1С сказала: другое» в журнале различаются.
     */
    public static function fromErp(mixed $raw): ?self
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        return self::tryFrom(mb_strtolower(trim($raw))) ?? self::OTHER;
    }

    /** Знает ли сайт это значение — иначе оно будет сведено к `OTHER` с предупреждением в журнале. */
    public static function isKnown(mixed $raw): bool
    {
        return is_string($raw) && self::tryFrom(mb_strtolower(trim($raw))) !== null;
    }

    /** Подпись для сотрудников: журнал недоборов, карточка заказа в админке. */
    public function label(): string
    {
        return match ($this) {
            self::OUT_OF_STOCK => 'Нет остатка',
            self::SHORTAGE => 'Недобор при сборке',
            self::CLIENT => 'Отмена клиентом сайта',
            self::RESERVE_EXPIRED => 'Истёк срок резерва',
            self::OTHER => 'Другая причина',
        };
    }

    /** Пояснение для подсказки в журнале недоборов. */
    public function description(): string
    {
        return match ($this) {
            self::OUT_OF_STOCK => '1С отменила строку: свободного остатка не было при приёме заказа или при переводе в «К отгрузке».',
            self::SHORTAGE => '1С отменила строку при сборке: товара на складе не хватило.',
            self::CLIENT => 'Строку или заказ отменил клиент на сайте.',
            self::RESERVE_EXPIRED => 'Резерв сайта снят по сроку — клиент не отправил заказ в отгрузку.',
            self::OTHER => 'Причина в 1С не из числа известных сайту: причина маркетплейса или ручная причина менеджера.',
        };
    }

    /** Подпись отменённой строки для клиента (кабинет). */
    public function clientLabel(): string
    {
        return match ($this) {
            self::OUT_OF_STOCK, self::SHORTAGE => 'Отменена — нет в наличии',
            self::CLIENT => 'Отменена вами',
            self::RESERVE_EXPIRED => 'Отменена — истёк срок резерва',
            self::OTHER => 'Отменена',
        };
    }

    /**
     * Категория справочника причин, в которой менеджеру стоит искать причину.
     *
     * Только подсказка: причину в справочнике автоматика не проставляет
     * (решение заказчика, short-01).
     */
    public function suggestedCategory(): ?ShortageReasonCategory
    {
        return match ($this) {
            self::OUT_OF_STOCK => ShortageReasonCategory::STOCK,
            self::SHORTAGE => ShortageReasonCategory::WAREHOUSE,
            self::CLIENT, self::RESERVE_EXPIRED => ShortageReasonCategory::CLIENT,
            self::OTHER => null,
        };
    }

    /**
     * Товара не хватило — повод для письма клиенту «части товара не хватило».
     *
     * `client` и `reserve_expired` — не нехватка: строку снял сам клиент либо
     * резерв истёк (о нём своё письмо `orders.reserve_released`).
     */
    public function isShortfall(): bool
    {
        return match ($this) {
            self::OUT_OF_STOCK, self::SHORTAGE, self::OTHER => true,
            self::CLIENT, self::RESERVE_EXPIRED => false,
        };
    }

    /**
     * 1С прямо назвала нехватку товара. Только с такой причиной письмо уходит
     * и по заказу в резерве: без причины отмену в окне резерва не отличить от
     * правки состава самим клиентом.
     */
    public function isConfirmedStockShortfall(): bool
    {
        return $this === self::OUT_OF_STOCK || $this === self::SHORTAGE;
    }
}
