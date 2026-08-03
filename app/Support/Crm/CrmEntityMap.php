<?php

namespace App\Support\Crm;

use App\Models\CrmComment;
use App\Models\CrmTask;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Единственное место, где перечислено, к чему в CRM можно что-то привязать.
 *
 * Комментарии, вложения и задачи полиморфны одинаково. Без общей карты у каждого из трёх
 * был бы свой whitelist типов, и добавление новой привязываемой сущности означало бы три
 * правки в разных местах — которые неизбежно разъедутся.
 *
 * Тип из HTTP-запроса резолвится ТОЛЬКО через эту карту, никогда через сырое имя класса:
 * иначе `entity_type` из запроса превратился бы в произвольный класс приложения.
 */
final class CrmEntityMap
{
    public const CLIENT = 'client';

    public const ORDER = 'order';

    public const SHIPMENT = 'shipment';

    public const COMMENT = 'comment';

    public const TASK = 'task';

    /**
     * Строковый тип для API → класс модели.
     *
     * @var array<string, class-string<Model>>
     */
    private const MODELS = [
        self::CLIENT => User::class,
        self::ORDER => Order::class,
        self::SHIPMENT => Shipment::class,
        self::COMMENT => CrmComment::class,
        self::TASK => CrmTask::class,
    ];

    /**
     * Русские названия типов для интерфейса и бейджей ленты.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        self::CLIENT => 'Клиент',
        self::ORDER => 'Заказ',
        self::SHIPMENT => 'Реализация',
        self::COMMENT => 'Комментарий',
        self::TASK => 'Задача',
    ];

    /**
     * Типы, к которым можно привязать задачу.
     *
     * Задача на задачу — это подзадача, а их мы сознательно не делаем (см. карточку
     * crm-09). Комментарий как объект поручения тоже бессмыслен.
     *
     * @return list<string>
     */
    public static function taskableTypes(): array
    {
        return array_values(array_diff(self::types(), [self::COMMENT, self::TASK]));
    }

    /**
     * Типы, к которым можно оставить комментарий.
     *
     * Комментарий сам в карте есть (к нему прикрепляют файлы), но комментировать
     * комментарий нельзя: вложенные треды в ленте продаж сознательно не делаем.
     *
     * @return list<string>
     */
    public static function commentableTypes(): array
    {
        return array_values(array_diff(self::types(), [self::COMMENT]));
    }

    /**
     * Поддерживаемые типы — для правил валидации `Rule::in(...)`.
     *
     * @return list<string>
     */
    public static function types(): array
    {
        return array_keys(self::MODELS);
    }

    public static function supports(string $type): bool
    {
        return isset(self::MODELS[$type]);
    }

    /**
     * @return class-string<Model>
     */
    public static function modelClass(string $type): string
    {
        if (! self::supports($type)) {
            throw new InvalidArgumentException("Неизвестный тип сущности CRM: {$type}");
        }

        return self::MODELS[$type];
    }

    /**
     * Обратный резолв: модель → строковый тип. NULL для сущности вне карты.
     */
    public static function typeOf(Model $entity): ?string
    {
        $type = array_search($entity::class, self::MODELS, true);

        return $type === false ? null : $type;
    }

    /**
     * Найти сущность по типу и ID. NULL, если её нет.
     *
     * Проверка доступа здесь НЕ делается — она зависит от актора и живёт в политике
     * вызывающего (комментарии, вложения, задачи проверяют видимость клиента сами).
     */
    public static function resolve(string $type, int $id): ?Model
    {
        $modelClass = self::modelClass($type);

        return $modelClass::query()->find($id);
    }

    /**
     * Клиент, в чью ленту попадёт запись, привязанная к этой сущности.
     *
     * NULL означает «сущность к клиенту не сводится»: заказ без user_id (партнёрские
     * из 1С) комментируется нормально, но в ленту не идёт.
     */
    public static function clientIdFor(Model $entity): ?int
    {
        $clientId = match (self::typeOf($entity)) {
            self::CLIENT => $entity->getKey(),
            self::ORDER, self::SHIPMENT => $entity->getAttribute('user_id'),
            // Комментарий и задача уже знают своего клиента — денормализация из crm-01.
            self::COMMENT, self::TASK => $entity->getAttribute('client_user_id'),
            default => null,
        };

        return $clientId === null ? null : (int) $clientId;
    }

    public static function labelFor(string $type): string
    {
        return self::LABELS[$type] ?? $type;
    }

    /**
     * Описание сущности для ленты и списков: тип, заголовок и ссылка.
     *
     * Ссылка на заказ и реализацию ведёт в админку, куда роли `sales-head` и
     * `sales-manager-crm` не ходят. Поэтому URL отдаётся только тем, кто реально
     * может его открыть — остальным сущность показывается подписью без ссылки,
     * чтобы не вести менеджера в 403.
     *
     * @return array{type: string, id: int, label: string, title: string, url: string|null}
     */
    public static function describe(Model $entity, ?User $viewer = null): array
    {
        $type = self::typeOf($entity);

        if ($type === null) {
            throw new InvalidArgumentException('Сущность '.$entity::class.' отсутствует в карте CRM');
        }

        return [
            'type' => $type,
            'id' => (int) $entity->getKey(),
            'label' => self::labelFor($type),
            'title' => self::titleFor($entity, $type),
            'url' => self::urlFor($entity, $type, $viewer),
        ];
    }

    private static function titleFor(Model $entity, string $type): string
    {
        return match ($type) {
            self::CLIENT => (string) ($entity->getAttribute('name') ?: 'Клиент №'.$entity->getKey()),
            self::ORDER => 'Заказ №'.($entity->getAttribute('number') ?: $entity->getKey()),
            self::SHIPMENT => 'Реализация №'.($entity->getAttribute('number') ?: $entity->getKey()),
            self::COMMENT => 'Комментарий от '.($entity->getAttribute('created_at')?->format('d.m.Y H:i') ?? '—'),
            self::TASK => (string) $entity->getAttribute('title'),
            default => (string) $entity->getKey(),
        };
    }

    private static function urlFor(Model $entity, string $type, ?User $viewer): ?string
    {
        if ($type === self::CLIENT) {
            return route('crm.clients.show', $entity->getKey());
        }

        // Раздел задач живёт в CRM, куда ходят все роли продаж — гейт по админке
        // ниже к нему неприменим.
        if ($type === self::TASK) {
            return route('crm.tasks.index', ['task' => $entity->getKey()]);
        }

        if ($viewer !== null && ! $viewer->hasAdminAccess()) {
            return null;
        }

        return match ($type) {
            self::ORDER => route('admin.orders.show', $entity->getKey()),
            self::SHIPMENT => route('admin.shipments.show', $entity->getKey()),
            default => null,
        };
    }
}
