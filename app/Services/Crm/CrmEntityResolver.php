<?php

namespace App\Services\Crm;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\CrmComment;
use App\Models\CrmLead;
use App\Models\CrmTask;
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
 * существование чужого партнёра, заказа или отгрузки.
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
     * Сущность без партнёра (заказ, пришедший из 1С без user_id) доступна только тем,
     * кто видит весь отдел: иначе любой менеджер комментировал бы чужие партнёрские
     * документы, к которым его скоуп отношения не имеет.
     */
    public function canAccess(User $actor, Model $entity): bool
    {
        // У задачи собственная модель доступа — участие в ней. Скоуп партнёров
        // к задаче неприменим: задача без привязки принадлежит автору и исполнителю,
        // и правило «партнёра нет — значит, только РОП» отобрало бы у менеджера
        // его же собственную задачу.
        if ($entity instanceof CrmTask) {
            return $actor->can('view', $entity);
        }

        // Комментарий наследует доступ от того, на чём висит.
        if ($entity instanceof CrmComment) {
            return $this->canAccessAttached($actor, $entity->client_user_id, $entity->commentable);
        }

        // У лида собственный скоуп: партнёра, по которому его можно было бы отмерить,
        // до конверсии просто нет. Без этой ветки clientIdFor() возвращал бы NULL,
        // правило вырождалось бы в «есть crm-department.view» — а оно есть у всех
        // ролей продаж, и любой менеджер писал бы в карточку чужого лида.
        if ($entity instanceof CrmLead) {
            return CrmLead::query()->visibleTo($actor)->whereKey($entity->getKey())->exists();
        }

        // Контрагент — отдельный раздел со своим правом. Без него скоуп партнёра
        // разрешал бы писать в карточку юрлица тому, кому раздел закрыт вовсе.
        if ($entity instanceof Company && ! $actor->can('crm-contractors.view')) {
            return false;
        }

        // У человека из справочника собственная политика: карточка без партнёра
        // (водитель перевозчика) доступна только тому, кто видит всю базу,
        // а правило ниже отдало бы её каждому, у кого есть видимость отдела.
        if ($entity instanceof Contact) {
            return $actor->can('view', $entity);
        }

        // Договор — отдельный раздел со своим правом; договор без партнёра
        // (иностранный поставщик) политика отдаёт только видящим отдел.
        if ($entity instanceof Contract) {
            return $actor->can('view', $entity);
        }

        return $this->canAccessAttached($actor, CrmEntityMap::clientIdFor($entity), null);
    }

    /**
     * Доступ к записи, привязанной к сущности (комментарий, задача, вложение).
     *
     * Партнёр есть — решает скоуп партнёров. Партнёра нет — решает сущность, на которой
     * запись висит; если и её нет, остаётся право видеть весь отдел.
     */
    public function canAccessAttached(User $actor, ?int $clientId, ?Model $entity): bool
    {
        if ($clientId !== null) {
            return $this->clientVisible($actor, $clientId);
        }

        return $entity instanceof Model
            ? $this->canAccess($actor, $entity)
            : $actor->can('crm-department.view');
    }

    /**
     * Попадает ли партнёр в скоуп актора — тот же scope, что и в списке партнёров.
     */
    public function clientVisible(User $actor, int $clientId): bool
    {
        return User::query()
            ->visibleInCrm($actor)
            ->whereKey($clientId)
            ->exists();
    }
}
