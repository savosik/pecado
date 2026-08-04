<?php

namespace App\Services\Crm;

use App\Enums\Crm\PlanTarget;

/**
 * Скоуп расчёта выполнения плана: чей план берём и чьи отгрузки считаем фактом.
 *
 * Две части неразделимы. План отдела без набора клиентов отдела не с чем сравнивать,
 * а набор клиентов без цели плана не отвечает на вопрос «сколько должны были».
 * Поэтому объект несёт и то, и другое — и служит ключом кэша.
 */
final class PlanScope
{
    /**
     * @param  list<int>  $clientIds  чьи отгрузки идут в факт (shipments.user_id)
     */
    private function __construct(
        public readonly PlanTarget $target,
        public readonly ?int $targetId,
        public readonly array $clientIds,
        public readonly string $label,
    ) {}

    /**
     * Отдел целиком: план отдела против отгрузок всех клиентов отдела.
     *
     * @param  list<int>  $clientIds
     */
    public static function department(array $clientIds): self
    {
        return new self(PlanTarget::DEPARTMENT, null, self::normalize($clientIds), 'Отдел');
    }

    /**
     * Один менеджер: его план против отгрузок его клиентов.
     *
     * @param  list<int>  $clientIds
     */
    public static function manager(int $managerId, array $clientIds, string $label): self
    {
        return new self(PlanTarget::MANAGER, $managerId, self::normalize($clientIds), $label);
    }

    /**
     * Один клиент.
     */
    public static function client(int $clientId, string $label): self
    {
        return new self(PlanTarget::CLIENT, $clientId, [$clientId], $label);
    }

    /**
     * Пустой скоуп — сотрудник без карточки менеджера и без клиентов.
     *
     * Отдельный конструктор, чтобы дашборд показывал «клиентов нет», а не падал
     * на попытке посчитать факт по пустому IN ().
     */
    public static function empty(): self
    {
        return new self(PlanTarget::MANAGER, null, [], 'Клиенты не назначены');
    }

    public function isEmpty(): bool
    {
        return $this->clientIds === [];
    }

    /**
     * Ключ цели плана: 'department', 'manager:7', 'client:120'.
     */
    public function planKey(): string
    {
        return $this->target->needsTarget()
            ? $this->target->value.':'.$this->targetId
            : $this->target->value;
    }

    /**
     * Ключ кэша: цель плюс отпечаток состава клиентов.
     *
     * Состав входит в ключ обязательно: у менеджера могут забрать клиента, и факт
     * по старому набору остался бы в кэше как цифра «его» продаж.
     */
    public function cacheKey(): string
    {
        $ids = $this->clientIds;
        sort($ids);

        return $this->planKey().':'.md5(implode(',', $ids));
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private static function normalize(array $ids): array
    {
        $normalized = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            fn (int $id): bool => $id > 0,
        )));

        sort($normalized);

        return $normalized;
    }
}
