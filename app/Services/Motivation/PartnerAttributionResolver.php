<?php

namespace App\Services\Motivation;

use App\Models\Motivation\MotivationPartnerAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Кто вёл партнёра в этот период (эпик mot-00).
 *
 * Реестр закрепления — единственное основание отнесения отгрузок к показателям
 * работника (п. 8.1). Пока бэкфилл не выполнен, реестр пуст, и атрибуция идёт
 * по текущему значению users.personal_manager_id: для прошлых месяцев это даёт
 * сегодняшнюю картину, о чём обязан предупреждать экран.
 *
 * Отдельный класс, потому что вопрос задают двое — месячный сбор входов
 * и квартальный зачёт, — и ответ у них обязан быть один.
 */
class PartnerAttributionResolver
{
    /**
     * Партнёры работника на указанную дату: id → отображаемое имя.
     *
     * @return array<int, string>
     */
    public function partnersOf(int $managerId, CarbonInterface $on): array
    {
        $date = CarbonImmutable::instance($on)->startOfDay();

        $fromRegistry = MotivationPartnerAssignment::query()
            ->where('personal_manager_id', $managerId)
            ->activeOn($date)
            ->pluck('user_id')
            ->map('intval')
            ->all();

        $query = User::query()->clients();

        if ($fromRegistry !== []) {
            $query->whereIn('id', $fromRegistry);
        } elseif ($this->registryFilled()) {
            return [];   // реестр ведётся, но в этот период партнёров у работника не было
        } else {
            $query->where('personal_manager_id', $managerId);
        }

        $names = [];
        foreach ($query->get(['id', 'name', 'erp_name']) as $client) {
            $names[(int) $client->getKey()] = (string) $client->display_name;
        }

        return $names;
    }

    /**
     * Менеджер каждого партнёра на дату: partner_id → personal_manager_id|null.
     *
     * @param  list<int>  $partnerIds
     * @return array<int, int|null>
     */
    public function managersOf(array $partnerIds, CarbonInterface $on): array
    {
        if ($partnerIds === []) {
            return [];
        }

        $date = CarbonImmutable::instance($on)->startOfDay();
        $managers = [];

        if ($this->registryFilled()) {
            $rows = MotivationPartnerAssignment::query()
                ->whereIn('user_id', $partnerIds)
                ->activeOn($date)
                ->get(['user_id', 'personal_manager_id']);

            foreach ($rows as $row) {
                $managers[(int) $row->user_id] = $row->personal_manager_id === null
                    ? null
                    : (int) $row->personal_manager_id;
            }
        }

        // Партнёры, которых реестр не покрывает, — по текущему значению карточки.
        $missing = array_values(array_diff($partnerIds, array_keys($managers)));

        if ($missing !== []) {
            foreach (User::query()->whereIn('id', $missing)->get(['id', 'personal_manager_id']) as $client) {
                $managers[(int) $client->getKey()] = $client->personal_manager_id === null
                    ? null
                    : (int) $client->personal_manager_id;
            }
        }

        return $managers;
    }

    /**
     * Партнёры Пула на дату: ни за кем не закреплены.
     *
     * @return list<int>
     */
    public function poolPartnerIds(CarbonInterface $on): array
    {
        if ($this->registryFilled()) {
            return MotivationPartnerAssignment::query()
                ->whereNull('personal_manager_id')
                ->activeOn(CarbonImmutable::instance($on)->startOfDay())
                ->pluck('user_id')
                ->map('intval')
                ->all();
        }

        return User::query()->clients()->whereNull('personal_manager_id')->pluck('id')->map('intval')->all();
    }

    public function registryFilled(): bool
    {
        return MotivationPartnerAssignment::query()->exists();
    }
}
