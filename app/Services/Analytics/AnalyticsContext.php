<?php

namespace App\Services\Analytics;

use App\Models\Currency;
use App\Models\User;

/**
 * Контекст выполнения аналитики по отгрузкам: чьи отгрузки считаем,
 * по какой бизнес-дате и в какой валюте отдаём результат.
 *
 * Заменяет прежнюю жёсткую привязку сервиса к одному `User`:
 *  - кабинет партнёра  → forUser()  (один пользователь, дата отгрузки, валюта региона);
 *  - CRM менеджер/РОП  → forScope() (набор клиентов, дата документа 1С, рубли).
 *
 * ВАЖНО: dateColumn используется в сырых SQL-выражениях (DATE(...)), поэтому
 * значение берётся только из белого списка и никогда из пользовательского ввода.
 */
class AnalyticsContext
{
    public const DATE_SHIPMENT = 'shipments.date';

    public const DATE_ERP = 'shipments.erp_created_at';

    private const ALLOWED_DATE_COLUMNS = [self::DATE_SHIPMENT, self::DATE_ERP];

    /**
     * @param  array<int, int>  $userIds  shipments.user_id IN (...)
     */
    public function __construct(
        public readonly array $userIds,
        public readonly string $dateColumn = self::DATE_SHIPMENT,
        public readonly ?Currency $currency = null,
    ) {
        if (! in_array($this->dateColumn, self::ALLOWED_DATE_COLUMNS, true)) {
            throw new \InvalidArgumentException("Недопустимая колонка даты: {$this->dateColumn}");
        }
    }

    /**
     * Контекст кабинета партнёра — поведение как до рефакторинга:
     * скоуп по одному пользователю, дата отгрузки, валюта его региона.
     */
    public static function forUser(User $user): self
    {
        return new self(
            userIds: [(int) $user->id],
            dateColumn: self::DATE_SHIPMENT,
            currency: $user->region?->currency,
        );
    }

    /**
     * Контекст CRM — набор клиентов менеджера/отдела, бизнес-дата 1С, рубли.
     *
     * @param  array<int, int>  $userIds
     */
    public static function forScope(
        array $userIds,
        string $dateColumn = self::DATE_ERP,
        ?Currency $currency = null,
    ): self {
        $normalized = array_values(array_unique(array_filter(
            array_map('intval', $userIds),
            fn (int $id) => $id > 0,
        )));

        return new self($normalized, $dateColumn, $currency);
    }

    public function isEmpty(): bool
    {
        return $this->userIds === [];
    }
}
