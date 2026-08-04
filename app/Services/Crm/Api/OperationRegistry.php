<?php

namespace App\Services\Crm\Api;

use App\Enums\Crm\CallDirection;
use App\Enums\Crm\CallResult;
use App\Enums\Crm\ClientLifecycleStatus;
use App\Enums\Crm\ClientSentiment;
use App\Enums\Crm\OpportunityPreset;
use App\Enums\Crm\PaymentBehavior;
use App\Enums\Crm\PreferredChannel;
use App\Enums\Crm\TaskPriority;
use App\Enums\Crm\TaskStatus;
use App\Models\User;
use App\Services\Crm\Api\Operations\AttachmentOperations;
use App\Services\Crm\Api\Operations\CallOperations;
use App\Services\Crm\Api\Operations\ClientOperations;
use App\Services\Crm\Api\Operations\CommentOperations;
use App\Services\Crm\Api\Operations\EmailOperations;
use App\Services\Crm\Api\Operations\OpportunityOperations;
use App\Services\Crm\Api\Operations\PlanOperations;
use App\Services\Crm\Api\Operations\ProfileOperations;
use App\Services\Crm\Api\Operations\TaskOperations;
use App\Support\Crm\ClientListFilters;
use App\Support\Crm\CrmEntityMap;

/**
 * Каталог операций CRM, доступных машинному потребителю.
 *
 * Единственный список эндпоинтов в проекте: из него строятся маршруты REST,
 * ответ discovery-метода, OpenAPI-документ и каталог инструментов MCP. Второго
 * перечня нет намеренно — у `/api/content/me` он захардкожен строками и уже
 * разошёлся с маршрутами, а для агента, который собирает вызов по такому ответу,
 * расхождение означает тихую поломку без единой ошибки в логах.
 *
 * Операции удаления сюда не попадают, кроме мягкого удаления своего комментария:
 * ошибочный вызов агента не должен приводить к безвозвратной потере.
 */
class OperationRegistry
{
    /** @var array<string, string> */
    private const SECTIONS = [
        'clients' => 'Клиенты',
        'profile' => 'Профиль клиента',
        'comments' => 'Комментарии и лента',
        'tasks' => 'Задачи',
        'calls' => 'Звонки',
        'emails' => 'Письма',
        'plans' => 'Планы продаж',
        'opportunities' => 'Возможности',
        'attachments' => 'Вложения',
    ];

    /** @var list<Operation>|null */
    private ?array $operations = null;

    /**
     * @return list<Operation>
     */
    public function all(): array
    {
        return $this->operations ??= array_merge(
            $this->clients(),
            $this->profile(),
            $this->comments(),
            $this->tasks(),
            $this->calls(),
            $this->emails(),
            $this->plans(),
            $this->opportunities(),
            $this->attachments(),
        );
    }

    public function find(string $id): ?Operation
    {
        foreach ($this->all() as $operation) {
            if ($operation->id === $id) {
                return $operation;
            }
        }

        return null;
    }

    /**
     * Операции, которые вообще можно вызвать: из них строятся маршруты REST.
     * Закрытые для агента остаются видимыми в каталоге, но адреса не получают.
     *
     * @return list<Operation>
     */
    public function callable(): array
    {
        return array_values(array_filter($this->all(), fn (Operation $o) => $o->agentAllowed));
    }

    /**
     * @return array<string, string>
     */
    public function sections(): array
    {
        return self::SECTIONS;
    }

    /**
     * Каталог для агента: что есть и что из этого ему доступно.
     *
     * @return list<array<string, mixed>>
     */
    public function catalog(User $actor, ?string $section = null): array
    {
        $operations = $this->all();

        if ($section !== null && $section !== '') {
            $operations = array_filter($operations, fn (Operation $o) => $o->section === $section);
        }

        return array_values(array_map(fn (Operation $o) => $o->catalogEntry($actor), $operations));
    }

    /**
     * @return list<Operation>
     */
    private function clients(): array
    {
        return [
            new Operation(
                id: 'client.list',
                section: 'clients',
                method: 'GET',
                uri: 'clients',
                permission: 'crm-clients.view',
                summary: 'Список клиентов с фильтрами и сортировкой',
                description: 'Рабочий список клиентов актора. Состав всегда ограничен его скоупом: '
                    .'менеджер видит своих, руководитель отдела — весь отдел. Фильтр по менеджеру '
                    .'у менеджера игнорируется и видимость не расширяет.',
                params: [
                    Param::string('search', 'Поиск по имени, e-mail, телефону или ИНН'),
                    Param::integer('manager_id', 'Менеджер — только для руководителя отдела'),
                    Param::string('lifecycle', 'Жизненный статус', enum: array_column(ClientLifecycleStatus::cases(), 'value')),
                    Param::string('task_state', 'Состояние задач по клиенту', enum: ClientListFilters::TASK_STATES),
                    Param::string('plan_state', 'Состояние выполнения плана', enum: ClientListFilters::PLAN_STATES),
                    Param::integer('inactive_days', 'Нет активности дольше скольких дней', rules: ['in:30,60,90']),
                    Param::string('sort_by', 'Поле сортировки', enum: ClientListFilters::SORTS),
                    Param::string('sort_order', 'Направление сортировки', enum: ['asc', 'desc']),
                    Param::integer('per_page', 'Клиентов на странице (до 100)', rules: ['min:1', 'max:100']),
                    Param::integer('page', 'Номер страницы', rules: ['min:1']),
                ],
                handler: [ClientOperations::class, 'list'],
            ),
            new Operation(
                id: 'client.show',
                section: 'clients',
                method: 'GET',
                uri: 'clients/{client}',
                permission: 'crm-clients.view',
                summary: 'Карточка клиента: контакты, менеджер, план и факт месяца',
                description: 'Профиль возвращается только при праве crm-profile.view. '
                    .'Клиент вне скоупа даёт 404, а не 403: существование записи не подтверждается.',
                params: [
                    Param::integer('client', 'Идентификатор клиента', required: true, rules: ['min:1']),
                ],
                handler: [ClientOperations::class, 'show'],
            ),
            new Operation(
                id: 'client.sales',
                section: 'clients',
                method: 'GET',
                uri: 'clients/{client}/sales',
                permission: 'crm-analytics.view',
                summary: 'Сводка продаж клиента: деньги, динамика, бренды, категории, товары',
                description: 'Факт продаж — отгрузки по дате документа в 1С (erp_created_at), '
                    .'а не по created_at: историю импортировали в мае 2026, и отчёт по created_at '
                    .'будет неверным и выглядеть правдоподобно.',
                params: [
                    Param::integer('client', 'Идентификатор клиента', required: true, rules: ['min:1']),
                    Param::integer('months', 'Глубина периода в месяцах (по умолчанию 12)', rules: ['min:1', 'max:60']),
                ],
                handler: [ClientOperations::class, 'sales'],
            ),
        ];
    }

    /**
     * @return list<Operation>
     */
    private function profile(): array
    {
        return [
            new Operation(
                id: 'client.profile.show',
                section: 'profile',
                method: 'GET',
                uri: 'clients/{client}/profile',
                permission: 'crm-profile.view',
                summary: 'Профиль клиента: ЛПР, платёжное поведение, заметки, интересы',
                description: 'То, что знает менеджер и не знает 1С.',
                params: [
                    Param::integer('client', 'Идентификатор клиента', required: true, rules: ['min:1']),
                ],
                handler: [ProfileOperations::class, 'show'],
            ),
            new Operation(
                id: 'client.profile.update',
                section: 'profile',
                method: 'PATCH',
                uri: 'clients/{client}/profile',
                permission: 'crm-profile.edit',
                summary: 'Обновить поля профиля и заметки менеджера',
                description: 'Передавайте только те поля, которые меняете: непереданное остаётся как было, '
                    .'а переданное пустым — очищается. Лояльность клиента (client_status) здесь не меняется: '
                    .'ею владеет 1С и перезапишет её следующим обменом.',
                params: [
                    Param::integer('client', 'Идентификатор клиента', required: true, rules: ['min:1']),
                    Param::string('decision_maker_name', 'Имя ЛПР', rules: ['max:255'], nullable: true),
                    Param::string('decision_maker_role', 'Должность ЛПР', rules: ['max:255'], nullable: true),
                    Param::string('decision_maker_contact', 'Контакт ЛПР', rules: ['max:255'], nullable: true),
                    Param::string('decision_process', 'Как принимается решение о закупке', rules: ['max:5000'], nullable: true),
                    Param::string('payment_behavior', 'Платёжное поведение', enum: array_column(PaymentBehavior::cases(), 'value'), nullable: true),
                    Param::string('payment_terms', 'Условия оплаты', rules: ['max:255'], nullable: true),
                    Param::integer('order_cycle_days', 'Обычная периодичность закупок, дней', rules: ['min:1', 'max:1095'], nullable: true),
                    Param::string('preferred_channel', 'Предпочитаемый канал связи', enum: array_column(PreferredChannel::cases(), 'value'), nullable: true),
                    Param::string('sentiment', 'Настроение клиента', enum: array_column(ClientSentiment::cases(), 'value'), nullable: true),
                    Param::string('notes_md', 'Заметки менеджера (Markdown)', rules: ['max:65535'], nullable: true),
                    Param::list('interests', 'Интересы клиента — список названий', required: false),
                ],
                handler: [ProfileOperations::class, 'update'],
                mutating: true,
            ),
            new Operation(
                id: 'client.lifecycle.change',
                section: 'profile',
                method: 'POST',
                uri: 'clients/{client}/lifecycle',
                permission: 'crm-profile.edit',
                summary: 'Сменить жизненный статус клиента с указанием причины',
                description: 'Жизненный статус — поле сайта, его ведёт отдел продаж. '
                    .'Это не лояльность из 1С и не блокировка аккаунта.',
                params: [
                    Param::integer('client', 'Идентификатор клиента', required: true, rules: ['min:1']),
                    Param::string('lifecycle_status', 'Новый жизненный статус', required: true, enum: array_column(ClientLifecycleStatus::cases(), 'value')),
                    Param::string('reason', 'Причина смены статуса', rules: ['max:500'], nullable: true),
                ],
                handler: [ProfileOperations::class, 'changeLifecycle'],
                mutating: true,
            ),
            new Operation(
                id: 'client.lifecycle.history',
                section: 'profile',
                method: 'GET',
                uri: 'clients/{client}/lifecycle',
                permission: 'crm-profile.view',
                summary: 'История смен жизненного статуса',
                description: 'Кто, когда и почему менял статус.',
                params: [
                    Param::integer('client', 'Идентификатор клиента', required: true, rules: ['min:1']),
                    Param::integer('limit', 'Сколько записей вернуть', rules: ['min:1', 'max:100']),
                ],
                handler: [ProfileOperations::class, 'lifecycleHistory'],
            ),
            new Operation(
                id: 'interest.search',
                section: 'profile',
                method: 'GET',
                uri: 'interests',
                permission: 'crm-profile.view',
                summary: 'Справочник интересов клиентов',
                description: 'Подсказки для поля «Интересы»: только теги своего типа, товарные сюда не попадают.',
                params: [
                    Param::string('query', 'Часть названия интереса', rules: ['max:100']),
                ],
                handler: [ProfileOperations::class, 'interests'],
            ),
        ];
    }

    /**
     * @return list<Operation>
     */
    private function comments(): array
    {
        return [
            new Operation(
                id: 'client.timeline',
                section: 'comments',
                method: 'GET',
                uri: 'clients/{client}/timeline',
                permission: 'crm-comments.view',
                summary: 'Сквозная лента клиента: записи менеджеров и его документы',
                description: 'Комментарии, задачи, письма, звонки, заказы и реализации в одной хронологии.',
                params: [
                    Param::integer('client', 'Идентификатор клиента', required: true, rules: ['min:1']),
                    Param::list('types', 'Оставить только эти типы записей'),
                    Param::integer('per_page', 'Записей на странице (до 100)', rules: ['min:1', 'max:100']),
                ],
                handler: [CommentOperations::class, 'clientTimeline'],
            ),
            new Operation(
                id: 'comment.list',
                section: 'comments',
                method: 'GET',
                uri: 'comments',
                permission: 'crm-comments.view',
                summary: 'Комментарии одной записи',
                description: 'Лента конкретного клиента, заказа, реализации или задачи.',
                params: [
                    Param::string('entity_type', 'Тип записи', required: true, enum: CrmEntityMap::commentableTypes()),
                    Param::integer('entity_id', 'Идентификатор записи', required: true, rules: ['min:1']),
                    Param::integer('per_page', 'Записей на странице (до 100)', rules: ['min:1', 'max:100']),
                ],
                handler: [CommentOperations::class, 'list'],
            ),
            new Operation(
                id: 'comment.create',
                section: 'comments',
                method: 'POST',
                uri: 'comments',
                permission: 'crm-comments.create',
                summary: 'Оставить комментарий по клиенту, заказу, реализации или задаче',
                description: 'Автором станет менеджер, от имени которого работает агент. '
                    .'В ленте запись помечается как сделанная агентом.',
                params: [
                    Param::string('entity_type', 'Тип записи', required: true, enum: CrmEntityMap::commentableTypes()),
                    Param::integer('entity_id', 'Идентификатор записи', required: true, rules: ['min:1']),
                    Param::string('body', 'Текст комментария', required: true, rules: ['min:1', 'max:5000']),
                    Param::boolean('is_pinned', 'Закрепить в начале ленты'),
                ],
                handler: [CommentOperations::class, 'create'],
                mutating: true,
            ),
            new Operation(
                id: 'comment.update',
                section: 'comments',
                method: 'PATCH',
                uri: 'comments/{comment}',
                permission: 'crm-comments.edit',
                summary: 'Изменить текст своего комментария',
                description: 'Чужой комментарий недоступен, даже если клиент виден.',
                params: [
                    Param::integer('comment', 'Идентификатор комментария', required: true, rules: ['min:1']),
                    Param::string('body', 'Новый текст', required: true, rules: ['min:1', 'max:5000']),
                    Param::boolean('is_pinned', 'Закрепить в начале ленты'),
                ],
                handler: [CommentOperations::class, 'update'],
                mutating: true,
            ),
            new Operation(
                id: 'comment.delete',
                section: 'comments',
                method: 'DELETE',
                uri: 'comments/{comment}',
                permission: 'crm-comments.delete',
                summary: 'Мягко удалить свой комментарий',
                description: 'Единственная операция удаления, доступная агенту: запись остаётся в базе '
                    .'и восстановима, поэтому цена ошибочного вызова здесь не «безвозвратно потеряно».',
                params: [
                    Param::integer('comment', 'Идентификатор комментария', required: true, rules: ['min:1']),
                ],
                handler: [CommentOperations::class, 'delete'],
                mutating: true,
            ),
        ];
    }

    /**
     * @return list<Operation>
     */
    private function tasks(): array
    {
        return [
            new Operation(
                id: 'task.list',
                section: 'tasks',
                method: 'GET',
                uri: 'tasks',
                permission: 'crm-tasks.view',
                summary: 'Задачи актора с фильтрами',
                description: 'Порядок — по дедлайну: сначала просроченные и ближайшие.',
                params: [
                    Param::string('status', 'Статус задачи', enum: array_column(TaskStatus::cases(), 'value')),
                    Param::integer('assignee_id', 'Исполнитель', rules: ['min:1']),
                    Param::integer('client_id', 'Задачи по конкретному клиенту', rules: ['min:1']),
                    Param::boolean('overdue', 'Только просроченные'),
                    Param::integer('per_page', 'Задач на странице (до 100)', rules: ['min:1', 'max:100']),
                ],
                handler: [TaskOperations::class, 'list'],
            ),
            new Operation(
                id: 'task.show',
                section: 'tasks',
                method: 'GET',
                uri: 'tasks/{task}',
                permission: 'crm-tasks.view',
                summary: 'Карточка задачи',
                description: 'Задача вне скоупа актора даёт 404.',
                params: [
                    Param::integer('task', 'Идентификатор задачи', required: true, rules: ['min:1']),
                ],
                handler: [TaskOperations::class, 'show'],
            ),
            new Operation(
                id: 'task.create',
                section: 'tasks',
                method: 'POST',
                uri: 'tasks',
                permission: 'crm-tasks.create',
                summary: 'Поставить задачу, при необходимости привязав к клиенту или документу',
                description: 'Без указания исполнителя задача остаётся на менеджере, от имени которого '
                    .'работает агент. Исполнителем можно назначить только сотрудника с доступом в CRM: '
                    .'задача, поручённая кладовщику, не появилась бы ни в одном интерфейсе.',
                params: [
                    Param::string('title', 'Что нужно сделать', required: true, rules: ['min:2', 'max:255']),
                    Param::string('description', 'Подробности', rules: ['max:5000'], nullable: true),
                    Param::integer('assignee_id', 'Исполнитель', rules: ['min:1']),
                    Param::string('status', 'Статус', enum: array_column(TaskStatus::cases(), 'value')),
                    Param::string('priority', 'Приоритет', enum: array_column(TaskPriority::cases(), 'value')),
                    Param::string('due_at', 'Дедлайн в формате ГГГГ-ММ-ДД или ГГГГ-ММ-ДД ЧЧ:ММ', rules: ['date'], nullable: true),
                    Param::string('entity_type', 'Тип привязки', enum: CrmEntityMap::taskableTypes()),
                    Param::integer('entity_id', 'Идентификатор привязки', rules: ['min:1']),
                ],
                handler: [TaskOperations::class, 'create'],
                mutating: true,
            ),
            new Operation(
                id: 'task.update',
                section: 'tasks',
                method: 'PATCH',
                uri: 'tasks/{task}',
                permission: 'crm-tasks.edit',
                summary: 'Изменить задачу',
                description: 'Переназначение исполнителя требует отдельного права: исполнитель может '
                    .'закрыть задачу, но не перевесить её на третьего.',
                params: [
                    Param::integer('task', 'Идентификатор задачи', required: true, rules: ['min:1']),
                    Param::string('title', 'Что нужно сделать', rules: ['min:2', 'max:255']),
                    Param::string('description', 'Подробности', rules: ['max:5000'], nullable: true),
                    Param::string('status', 'Статус', enum: array_column(TaskStatus::cases(), 'value')),
                    Param::string('priority', 'Приоритет', enum: array_column(TaskPriority::cases(), 'value')),
                    Param::string('due_at', 'Дедлайн', rules: ['date'], nullable: true),
                    Param::integer('assignee_id', 'Исполнитель', rules: ['min:1']),
                ],
                handler: [TaskOperations::class, 'update'],
                mutating: true,
            ),
            new Operation(
                id: 'task.close',
                section: 'tasks',
                method: 'POST',
                uri: 'tasks/{task}/close',
                permission: 'crm-tasks.edit',
                summary: 'Закрыть задачу с отчётом и следующим шагом',
                description: 'Отметка, комментарий и следующая задача ложатся одной транзакцией: '
                    .'закрытие с потерянным отчётом — тот самый разрыв, из-за которого работа '
                    .'с клиентом рассыпается на разовые дёрганья.',
                params: [
                    Param::integer('task', 'Идентификатор задачи', required: true, rules: ['min:1']),
                    Param::string('comment', 'Что сделано', rules: ['max:5000'], nullable: true),
                    new Param('follow_up', 'object', 'Следующий шаг: title, description, due_at, priority, assignee_id'),
                ],
                handler: [TaskOperations::class, 'close'],
                mutating: true,
            ),
            // Видна в каталоге, но не выполняется. Молча её не показывать было бы
            // хуже: агент раз за разом сообщал бы менеджеру «не нашёл, как удалить»,
            // вместо честного «удаление закрыто, удалите руками».
            new Operation(
                id: 'task.delete',
                section: 'tasks',
                method: 'DELETE',
                uri: 'tasks/{task}',
                permission: 'crm-tasks.delete',
                summary: 'Удалить задачу — недоступно агенту',
                description: 'Удаление выполняется только человеком в интерфейсе.',
                params: [
                    Param::integer('task', 'Идентификатор задачи', required: true, rules: ['min:1']),
                ],
                handler: [TaskOperations::class, 'show'],
                mutating: true,
                agentAllowed: false,
                deniedReason: 'Удаление задач через агента запрещено: ошибочный вызов привёл бы '
                    .'к безвозвратной потере. Удалите задачу в интерфейсе CRM.',
            ),
        ];
    }

    /**
     * @return list<Operation>
     */
    private function calls(): array
    {
        return [
            new Operation(
                id: 'call.list',
                section: 'calls',
                method: 'GET',
                uri: 'calls',
                permission: 'crm-calls.view',
                summary: 'Журнал звонков',
                description: 'Телефония не подключена: записи заводятся вручную.',
                params: [
                    Param::integer('client_id', 'Звонки по конкретному клиенту', rules: ['min:1']),
                    Param::string('result', 'Итог разговора', enum: array_column(CallResult::cases(), 'value')),
                    Param::integer('per_page', 'Записей на странице (до 100)', rules: ['min:1', 'max:100']),
                ],
                handler: [CallOperations::class, 'list'],
            ),
            new Operation(
                id: 'call.create',
                section: 'calls',
                method: 'POST',
                uri: 'calls',
                permission: 'crm-calls.create',
                summary: 'Записать состоявшийся звонок и следующий шаг',
                description: 'Звонок и следующая задача создаются одной транзакцией.',
                params: [
                    Param::string('direction', 'Направление', enum: array_column(CallDirection::cases(), 'value')),
                    Param::string('result', 'Итог разговора', enum: array_column(CallResult::cases(), 'value')),
                    Param::string('phone', 'Номер телефона', rules: ['max:32'], nullable: true),
                    Param::string('contact_name', 'С кем говорили', rules: ['max:255'], nullable: true),
                    Param::string('summary', 'О чём договорились', rules: ['max:5000'], nullable: true),
                    Param::string('started_at', 'Когда состоялся звонок', rules: ['date'], nullable: true),
                    Param::integer('duration_sec', 'Длительность, секунд', rules: ['min:0', 'max:86400'], nullable: true),
                    Param::string('entity_type', 'Тип привязки', enum: CrmEntityMap::taskableTypes()),
                    Param::integer('entity_id', 'Идентификатор привязки', rules: ['min:1']),
                    new Param('follow_up', 'object', 'Следующий шаг: title, description, due_at, priority, assignee_id'),
                ],
                handler: [CallOperations::class, 'create'],
                mutating: true,
            ),
        ];
    }

    /**
     * @return list<Operation>
     */
    private function emails(): array
    {
        return [
            new Operation(
                id: 'email.list',
                section: 'emails',
                method: 'GET',
                uri: 'emails',
                permission: 'crm-emails.view',
                summary: 'Журнал писем',
                description: 'Поле outbound_enabled показывает, включена ли отправка вообще.',
                params: [
                    Param::string('status', 'Статус письма', enum: ['draft', 'queued', 'sent', 'failed']),
                    Param::integer('client_id', 'Письма по конкретному клиенту', rules: ['min:1']),
                    Param::integer('per_page', 'Писем на странице (до 100)', rules: ['min:1', 'max:100']),
                ],
                handler: [EmailOperations::class, 'list'],
            ),
            new Operation(
                id: 'email.show',
                section: 'emails',
                method: 'GET',
                uri: 'emails/{email}',
                permission: 'crm-emails.view',
                summary: 'Письмо целиком',
                description: 'Письмо вне скоупа актора даёт 404.',
                params: [
                    Param::integer('email', 'Идентификатор письма', required: true, rules: ['min:1']),
                ],
                handler: [EmailOperations::class, 'show'],
            ),
            new Operation(
                id: 'email.draft',
                section: 'emails',
                method: 'POST',
                uri: 'emails',
                permission: 'crm-emails.create',
                summary: 'Создать черновик письма',
                description: 'Черновик создаётся и при выключенной отправке: агент готовит текст, '
                    .'менеджер решает, уходит ли письмо.',
                params: [
                    Param::list('to', 'Получатели', required: true),
                    Param::list('cc', 'Копия'),
                    Param::string('reply_to', 'Адрес для ответа; по умолчанию — почта менеджера', rules: ['email'], nullable: true),
                    Param::string('subject', 'Тема', required: true, rules: ['max:255']),
                    Param::string('body_html', 'Тело письма (HTML)', required: true, rules: ['max:65535']),
                    Param::string('entity_type', 'Тип привязки', enum: CrmEntityMap::taskableTypes()),
                    Param::integer('entity_id', 'Идентификатор привязки', rules: ['min:1']),
                ],
                handler: [EmailOperations::class, 'draft'],
                mutating: true,
            ),
            new Operation(
                id: 'email.send',
                section: 'emails',
                method: 'POST',
                uri: 'emails/{email}/send',
                permission: 'crm-emails.create',
                summary: 'Поставить письмо в очередь отправки',
                description: 'Отправка гейтится флагом MAIL_FEATURE_CRM_OUTBOUND. При выключенном флаге '
                    .'операция вернёт ошибку, а черновик останется на месте.',
                params: [
                    Param::integer('email', 'Идентификатор письма', required: true, rules: ['min:1']),
                ],
                handler: [EmailOperations::class, 'send'],
                mutating: true,
            ),
        ];
    }

    /**
     * @return list<Operation>
     */
    private function plans(): array
    {
        return [
            new Operation(
                id: 'plan.list',
                section: 'plans',
                method: 'GET',
                uri: 'plans',
                permission: 'crm-plans.view',
                summary: 'Планы периода, разложенные по целям',
                description: 'Цели: отдел, менеджер, клиент. Видны только те, что доступны актору.',
                params: [
                    Param::string('month', 'Месяц в формате ГГГГ-ММ; по умолчанию текущий', rules: ['max:7']),
                ],
                handler: [PlanOperations::class, 'list'],
            ),
            new Operation(
                id: 'plan.set',
                section: 'plans',
                method: 'POST',
                uri: 'plans',
                permission: 'crm-plans.edit',
                summary: 'Поставить планы строками «цель → сумма»',
                description: 'Строка: target_type (department|manager|client), target_id, amount, comment. '
                    .'Пустая сумма снимает план. Строки вне прав актора пропускаются молча и попадают '
                    .'в счётчик skipped — одна недоступная цель не должна ронять сохранение остальных.',
                params: [
                    Param::string('month', 'Месяц в формате ГГГГ-ММ; по умолчанию текущий', rules: ['max:7']),
                    Param::list('rows', 'Строки планов', itemType: 'object', required: true),
                ],
                handler: [PlanOperations::class, 'set'],
                mutating: true,
            ),
            new Operation(
                id: 'plan.progress',
                section: 'plans',
                method: 'GET',
                uri: 'plans/progress',
                permission: 'crm-plans.view',
                summary: 'Выполнение плана: план, факт, отставание и разбивка по клиентам',
                description: 'Факт — отгрузки по дате документа в 1С.',
                params: [
                    Param::string('month', 'Месяц в формате ГГГГ-ММ', rules: ['max:7']),
                    Param::string('scope', 'Разрез: department или manager', enum: ['department', 'manager']),
                    Param::integer('scope_id', 'Менеджер для разреза manager', rules: ['min:1']),
                    Param::integer('limit', 'Сколько клиентов вернуть (до 200)', rules: ['min:1', 'max:200']),
                ],
                handler: [PlanOperations::class, 'progress'],
            ),
            new Operation(
                id: 'plan.burndown',
                section: 'plans',
                method: 'GET',
                uri: 'plans/burndown',
                permission: 'crm-plans.view',
                summary: 'Точки burndown по дням месяца',
                description: 'Идеальный темп против фактического — видно, догоняет отдел план или нет.',
                params: [
                    Param::string('month', 'Месяц в формате ГГГГ-ММ', rules: ['max:7']),
                    Param::string('scope', 'Разрез: department или manager', enum: ['department', 'manager']),
                    Param::integer('scope_id', 'Менеджер для разреза manager', rules: ['min:1']),
                ],
                handler: [PlanOperations::class, 'burndown'],
            ),
            new Operation(
                id: 'plan.by-manager',
                section: 'plans',
                method: 'GET',
                uri: 'plans/by-manager',
                permission: 'crm-clients-all.view',
                summary: 'Выполнение плана в разрезе менеджеров',
                description: 'Только для руководителя отдела: менеджер не должен видеть выручку соседа.',
                params: [
                    Param::string('month', 'Месяц в формате ГГГГ-ММ', rules: ['max:7']),
                ],
                handler: [PlanOperations::class, 'byManager'],
            ),
        ];
    }

    /**
     * @return list<Operation>
     */
    private function opportunities(): array
    {
        return [
            new Operation(
                id: 'opportunity.list',
                section: 'opportunities',
                method: 'GET',
                uri: 'opportunities',
                permission: 'crm-opportunities.view',
                summary: 'Кому звонить сегодня: ранжированный список с объяснением',
                description: 'Каждая строка несёт причину попадания в список и оценку приоритета. '
                    .'Пресет not_buying требует измерения (бренд или категория) — без него список пуст.',
                params: [
                    Param::string('preset', 'Пресет отбора', enum: array_column(OpportunityPreset::cases(), 'value')),
                    Param::string('month', 'Месяц в формате ГГГГ-ММ', rules: ['max:7']),
                    Param::string('scope', 'Разрез: department или manager', enum: ['department', 'manager']),
                    Param::integer('scope_id', 'Менеджер для разреза manager', rules: ['min:1']),
                    Param::string('dimension', 'Измерение для пресета not_buying', enum: ['brand', 'category']),
                    Param::integer('value', 'Идентификатор бренда или категории', rules: ['min:1']),
                    Param::integer('limit', 'Сколько строк вернуть', rules: ['min:1', 'max:100']),
                ],
                handler: [OpportunityOperations::class, 'list'],
            ),
        ];
    }

    /**
     * @return list<Operation>
     */
    private function attachments(): array
    {
        return [
            new Operation(
                id: 'attachment.list',
                section: 'attachments',
                method: 'GET',
                uri: 'attachments',
                permission: 'crm-attachments.view',
                summary: 'Файлы, прикреплённые к записи',
                description: 'Только перечень со ссылками на скачивание. Загрузка файла остаётся '
                    .'действием браузера: агент оперирует текстом.',
                params: [
                    Param::string('entity_type', 'Тип записи', required: true, enum: CrmEntityMap::types()),
                    Param::integer('entity_id', 'Идентификатор записи', required: true, rules: ['min:1']),
                ],
                handler: [AttachmentOperations::class, 'list'],
            ),
        ];
    }
}
