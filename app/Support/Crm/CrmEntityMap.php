<?php

namespace App\Support\Crm;

use App\Models\Company;
use App\Models\CrmCall;
use App\Models\CrmComment;
use App\Models\CrmEmail;
use App\Models\CrmLead;
use App\Models\CrmTask;
use App\Models\Order;
use App\Models\Payment;
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
    /**
     * Партнёр — то, что в 1С называется партнёром, а в коде исторически `client`.
     *
     * Строковый тип менять нельзя: он лежит в `related_type`/`commentable_type`
     * уже созданных записей и приезжает от ИИ-агентов через REST.
     */
    public const CLIENT = 'client';

    /**
     * Контрагент — юрлицо партнёра (`companies`), от имени которого идут документы.
     *
     * У партнёра их может быть несколько: план и его выполнение считаются по
     * партнёру, а переписка о реквизитах, доверенностях и сверках — по контрагенту.
     */
    public const CONTRACTOR = 'contractor';

    public const ORDER = 'order';

    public const SHIPMENT = 'shipment';

    public const PAYMENT = 'payment';

    public const COMMENT = 'comment';

    public const TASK = 'task';

    public const EMAIL = 'email';

    public const CALL = 'call';

    /**
     * Лид — потенциальный клиент до появления партнёра в 1С (crm-26).
     *
     * Живёт вне `users`, поэтому в ленту партнёра его записи не сводятся:
     * clientIdFor() возвращает по нему NULL, пока лид не привязан к партнёру.
     */
    public const LEAD = 'lead';

    /**
     * Строковый тип для API → класс модели.
     *
     * @var array<string, class-string<Model>>
     */
    private const MODELS = [
        self::CLIENT => User::class,
        self::CONTRACTOR => Company::class,
        self::ORDER => Order::class,
        self::SHIPMENT => Shipment::class,
        self::PAYMENT => Payment::class,
        self::COMMENT => CrmComment::class,
        self::TASK => CrmTask::class,
        self::EMAIL => CrmEmail::class,
        self::CALL => CrmCall::class,
        self::LEAD => CrmLead::class,
    ];

    /**
     * Русские названия типов для интерфейса и бейджей ленты.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        self::CLIENT => 'Партнёр',
        self::CONTRACTOR => 'Контрагент',
        self::ORDER => 'Заказ',
        self::SHIPMENT => 'Реализация',
        self::PAYMENT => 'Платёж',
        self::COMMENT => 'Комментарий',
        self::TASK => 'Задача',
        self::EMAIL => 'Письмо',
        self::CALL => 'Звонок',
        self::LEAD => 'Лид',
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
        return array_values(array_diff(self::types(), [self::COMMENT, self::TASK, self::EMAIL, self::CALL]));
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
     * вызывающего (комментарии, вложения, задачи проверяют видимость партнёра сами).
     */
    public static function resolve(string $type, int $id): ?Model
    {
        $modelClass = self::modelClass($type);

        return $modelClass::query()->find($id);
    }

    /**
     * Партнёр, в чью ленту попадёт запись, привязанная к этой сущности.
     *
     * NULL означает «сущность к партнёру не сводится»: заказ без user_id (партнёрские
     * из 1С) комментируется нормально, но в ленту не идёт.
     */
    public static function clientIdFor(Model $entity): ?int
    {
        $clientId = match (self::typeOf($entity)) {
            self::CLIENT => $entity->getKey(),
            // Контрагент без партнёра встречается: 1С присылает юрлицо раньше,
            // чем оно привязано к партнёру. Такой контрагент комментируется, но
            // в ленту партнёра не идёт — попадать ей некуда.
            self::CONTRACTOR, self::ORDER, self::SHIPMENT, self::PAYMENT => $entity->getAttribute('user_id'),
            // Комментарий и задача уже знают своего партнёра — денормализация из crm-01.
            self::COMMENT, self::TASK, self::EMAIL, self::CALL => $entity->getAttribute('client_user_id'),
            // Лид сводится к партнёру только после конверсии: до неё
            // партнёра, в чью ленту писать, попросту нет.
            self::LEAD => $entity->getAttribute('converted_user_id'),
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
     * Все ссылки ведут внутрь CRM, включая заказы и реализации: раньше они уходили
     * в /admin, куда роли `sales-head` и `sales-manager-crm` намеренно не пускают,
     * и менеджер упирался в 403. `$viewer` остаётся в сигнатуре — он понадобится,
     * когда у ссылок появятся правила видимости сложнее скоупа партнёра.
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
            'url' => self::urlFor($entity, $type),
        ];
    }

    private static function titleFor(Model $entity, string $type): string
    {
        return match ($type) {
            self::CLIENT => (string) ($entity->getAttribute('name') ?: 'Партнёр №'.$entity->getKey()),
            self::CONTRACTOR => (string) (
                $entity->getAttribute('name')
                ?: $entity->getAttribute('legal_name')
                ?: 'Контрагент №'.$entity->getKey()
            ),
            self::ORDER => 'Заказ №'.($entity->getAttribute('number') ?: $entity->getKey()),
            self::SHIPMENT => 'Реализация №'.($entity->getAttribute('number') ?: $entity->getKey()),
            self::PAYMENT => 'Платёж №'.($entity->getAttribute('number') ?: $entity->getKey()),
            self::COMMENT => 'Комментарий от '.($entity->getAttribute('created_at')?->format('d.m.Y H:i') ?? '—'),
            self::TASK => (string) $entity->getAttribute('title'),
            self::EMAIL => 'Письмо: '.$entity->getAttribute('subject'),
            self::CALL => 'Звонок от '.($entity->getAttribute('started_at')?->format('d.m.Y H:i') ?? '—'),
            // Организация в скобках: лида заводят и по имени человека, и по названию
            // фирмы, и в списке задач одно без другого часто не узнаётся.
            self::LEAD => trim((string) $entity->getAttribute('name')).(
                filled($entity->getAttribute('company_name'))
                    ? ' ('.$entity->getAttribute('company_name').')'
                    : ''
            ),
            default => (string) $entity->getKey(),
        };
    }

    private static function urlFor(Model $entity, string $type): ?string
    {
        if ($type === self::CLIENT) {
            return route('crm.clients.show', $entity->getKey());
        }

        if ($type === self::CONTRACTOR) {
            return route('crm.contractors.show', $entity->getKey());
        }

        // Раздел задач живёт в CRM, куда ходят все роли продаж — гейт по админке
        // ниже к нему неприменим.
        if ($type === self::TASK) {
            return route('crm.tasks.index', ['task' => $entity->getKey()]);
        }

        if ($type === self::EMAIL) {
            return route('crm.emails.index', ['email' => $entity->getKey()]);
        }

        // Отдельной страницы у лида нет — он живёт карточкой на доске, и ссылка
        // открывает доску сразу с раскрытой карточкой. Тот же приём, что у задач
        // и писем выше.
        if ($type === self::LEAD) {
            return route('crm.leads.index', ['lead' => $entity->getKey()]);
        }

        // Отдельного раздела звонков нет — звонок живёт в ленте своего партнёра.
        if ($type === self::CALL) {
            $clientId = $entity->getAttribute('client_user_id');

            return $clientId === null ? null : route('crm.clients.show', $clientId);
        }

        // Заказы и реализации открываются в CRM, а не в админке. Раньше ссылка вела
        // в /admin, куда роли sales-head и sales-manager-crm намеренно не пускают:
        // менеджер видел ссылку и упирался в 403, а без неё не мог посмотреть состав
        // документа вообще. Кнопка «Открыть в админке» осталась в самой карточке —
        // для тех, у кого доступ есть.
        return match ($type) {
            self::ORDER => route('crm.orders.show', $entity->getKey()),
            self::SHIPMENT => route('crm.shipments.show', $entity->getKey()),
            self::PAYMENT => route('crm.payments.show', $entity->getKey()),
            default => null,
        };
    }
}
