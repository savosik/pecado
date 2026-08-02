<?php

namespace App\Services\Crm;

use App\Models\User;
use App\Support\Crm\CrmEntityMap;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Резолв сущности из запроса вместе с проверкой доступа актора.
 *
 * Общая точка для комментариев, вложений и задач: все три принимают из запроса пару
 * (entity_type, entity_id) и обязаны убедиться, что актор вправе с этой сущностью работать.
 * Без общего резолвера проверку пришлось бы повторять в каждом контроллере — и однажды
 * забыть, превратив загрузку файла в обход скоупа карточки.
 *
 * Отказ — всегда ModelNotFoundException (404), а не 403: 403 подтвердил бы менеджеру
 * существование чужого клиента, заказа или отгрузки.
 */
class CrmEntityResolver
{
    /**
     * Найти сущность, с которой актору разрешено работать.
     *
     * @throws ModelNotFoundException
     */
    public function resolveForActor(User $actor, string $type, int $id): Model
    {
        if (! CrmEntityMap::supports($type)) {
            throw new ModelNotFoundException("Неизвестный тип сущности CRM: {$type}");
        }

        $entity = CrmEntityMap::resolve($type, $id);

        if ($entity === null) {
            throw (new ModelNotFoundException)->setModel(CrmEntityMap::modelClass($type), [$id]);
        }

        if (! $this->canAccess($actor, $entity)) {
            throw (new ModelNotFoundException)->setModel(CrmEntityMap::modelClass($type), [$id]);
        }

        return $entity;
    }

    /**
     * Вправе ли актор работать с сущностью.
     *
     * Сущность без клиента (заказ, пришедший из 1С без user_id) доступна только тем,
     * кто видит весь отдел: иначе любой менеджер комментировал бы чужие партнёрские
     * документы, к которым его скоуп отношения не имеет.
     */
    public function canAccess(User $actor, Model $entity): bool
    {
        $clientId = CrmEntityMap::clientIdFor($entity);

        if ($clientId === null) {
            return $actor->can('crm-clients-all.view');
        }

        return $this->clientVisible($actor, $clientId);
    }

    /**
     * Попадает ли клиент в скоуп актора — тот же scope, что и в списке клиентов.
     */
    public function clientVisible(User $actor, int $clientId): bool
    {
        return User::query()
            ->visibleInCrm($actor)
            ->whereKey($clientId)
            ->exists();
    }
}
