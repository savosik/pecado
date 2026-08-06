<?php

namespace App\Services\Crm;

use App\Enums\Crm\PlanTarget;
use App\Models\PersonalManager;
use App\Models\User;

/**
 * Чей срез показываем: отдел, менеджера или клиента.
 *
 * Живёт отдельным сервисом, потому что это граница доступа, а не удобство:
 * «менеджеру доступен только собственный скоуп» должно быть написано ровно один
 * раз. Второй копией правила в другом контроллере рано или поздно оказалось бы,
 * что один экран показывает выручку соседа, а другой нет.
 */
class PlanScopeResolver
{
    /**
     * Скоуп по запрошенным параметрам с оглядкой на права актора.
     *
     * Менеджеру подставленный в адрес чужой `scope_id` не открывает ни отдел
     * целиком, ни выручку соседа: параметр для него игнорируется, а не
     * проверяется — проверять нечего.
     */
    public function resolve(User $actor, ?string $type, ?int $scopeId): PlanScope
    {
        $seesAll = $actor->can('crm-clients-all.view');
        $target = PlanTarget::tryFrom((string) $type) ?? PlanTarget::DEPARTMENT;

        if ($target === PlanTarget::CLIENT && $scopeId !== null) {
            $client = User::query()->visibleInCrm($actor)->whereKey($scopeId)->first(['id', 'name', 'erp_name']);

            return $client === null
                ? PlanScope::empty()
                : PlanScope::client((int) $client->getKey(), (string) $client->display_name);
        }

        if ($target === PlanTarget::MANAGER || ! $seesAll) {
            $managerId = $seesAll ? $scopeId : $actor->managerProfile?->id;

            if ($managerId === null) {
                return $seesAll ? $this->department($actor) : PlanScope::empty();
            }

            $manager = PersonalManager::query()->find($managerId, ['id', 'name']);

            if ($manager === null) {
                return PlanScope::empty();
            }

            /** @var list<int> $clientIds */
            $clientIds = User::query()
                ->visibleInCrm($actor)
                ->where('personal_manager_id', $manager->getKey())
                ->pluck('users.id')
                ->map('intval')
                ->all();

            return PlanScope::manager((int) $manager->getKey(), $clientIds, (string) $manager->name);
        }

        return $this->department($actor);
    }

    /**
     * Весь видимый актору отдел.
     */
    public function department(User $actor): PlanScope
    {
        /** @var list<int> $clientIds */
        $clientIds = User::query()->visibleInCrm($actor)->pluck('users.id')->map('intval')->all();

        return PlanScope::department($clientIds);
    }

    /**
     * Что можно выбрать в переключателе скоупа. Менеджеру выбирать нечего —
     * его скоуп единственный, и список пустой.
     *
     * @return list<array{id: int, name: string}>
     */
    public function options(User $actor): array
    {
        if (! $actor->can('crm-clients-all.view')) {
            return [];
        }

        return PersonalManager::query()
            ->active()
            ->select('id', 'name')
            ->orderBy('name')
            ->get()
            ->map(fn (PersonalManager $manager): array => [
                'id' => (int) $manager->getKey(),
                'name' => (string) $manager->name,
            ])
            ->all();
    }

    /**
     * @return array{type: string, id: int|null, label: string, clients_count: int}
     */
    public function payload(PlanScope $scope): array
    {
        return [
            'type' => $scope->target->value,
            'id' => $scope->targetId,
            'label' => $scope->label,
            'clients_count' => count($scope->clientIds),
        ];
    }
}
