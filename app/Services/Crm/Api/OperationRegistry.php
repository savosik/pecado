<?php

namespace App\Services\Crm\Api;

use App\Enums\ContactRole;
use App\Enums\Crm\CallDirection;
use App\Enums\Crm\CallResult;
use App\Enums\Crm\ClientLifecycleStatus;
use App\Enums\Crm\ClientSentiment;
use App\Enums\Crm\OpportunityPreset;
use App\Enums\Crm\PaymentBehavior;
use App\Enums\Crm\PreferredChannel;
use App\Enums\Crm\TaskOutcome;
use App\Enums\Crm\TaskPriority;
use App\Enums\Crm\TaskStatus;
use App\Models\User;
use App\Services\Crm\Api\Operations\AttachmentOperations;
use App\Services\Crm\Api\Operations\CallOperations;
use App\Services\Crm\Api\Operations\ClientOperations;
use App\Services\Crm\Api\Operations\CommentOperations;
use App\Services\Crm\Api\Operations\ContactOperations;
use App\Services\Crm\Api\Operations\EmailOperations;
use App\Services\Crm\Api\Operations\OpportunityOperations;
use App\Services\Crm\Api\Operations\PaymentOperations;
use App\Services\Crm\Api\Operations\PlanOperations;
use App\Services\Crm\Api\Operations\ProfileOperations;
use App\Services\Crm\Api\Operations\SettlementOperations;
use App\Services\Crm\Api\Operations\TaskOperations;
use App\Support\Crm\ClientListFilters;
use App\Support\Crm\ClientPassport;
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
        'clients' => 'Партнёры',
        'profile' => 'Профиль партнёра',
        'comments' => 'Комментарии и лента',
        'tasks' => 'Задачи',
        'calls' => 'Звонки',
        'emails' => 'Письма',
        'plans' => 'Планы продаж',
        'opportunities' => 'Возможности',
        'attachments' => 'Вложения',
        'payments' => 'Платежи',
        'settlements' => 'Взаиморасчёты',
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
            $this->contacts(),
            $this->plans(),
            $this->opportunities(),
            $this->attachments(),
            $this->payments(),
            $this->settlements(),
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
    /**
     * Взаиморасчёты из регистра 1С (v16.0.0).
     *
     * Появляются только при включённом `settlements.ledger_enabled`. Пока флаг
     * выключен, регистр пуст, и операции отвечали бы «никто ничего не должен» —
     * агент принял бы это за факт и сообщил менеджеру с полной уверенностью.
     *
     * @return list<Operation>
     */
    private function settlements(): array
    {
        if (! config('settlements.ledger_enabled')) {
            return [];
        }

        return [
            new Operation(
                id: 'settlement.balance',
                section: 'settlements',
                method: 'GET',
                uri: 'settlements/balance',
                permission: 'crm-clients.view',
                summary: 'Сальдо, текущий долг и просрочка партнёра',
                description: 'ОТВЕТ на «сколько партнёр должен». Три числа сразу, и подменять '
                    .'их друг другом нельзя: `balance` — сальдо всех операций, `due_now` — '
                    .'обязательства, срок которых наступил, `overdue` — из них просроченные. '
                    .'Отрицательный баланс — партнёр должен нам, положительный — переплата. '
                    .'Строка — контрагент (юрлицо), у партнёра их бывает несколько.',
                params: [
                    Param::integer('client_id', 'Партнёр — расчёты только по нему', rules: ['min:1']),
                    Param::boolean('only_overdue', 'Только контрагенты с просрочкой'),
                ],
                handler: [SettlementOperations::class, 'balance'],
            ),
            new Operation(
                id: 'settlement.schedule',
                section: 'settlements',
                method: 'GET',
                uri: 'settlements/schedule',
                permission: 'crm-clients.view',
                summary: 'Плановые платежи с остатком: когда и сколько партнёр внесёт',
                description: 'Погашенную часть присылает 1С — сайт платежи не раскладывает. '
                    .'Суммы положительные: это «сколько должен заплатить», а не движение баланса. '
                    .'`is_settled_derived: true` означает, что погашение разнесено по этапам '
                    .'заказа ради календаря; в баланс и сверку такую величину не берите.',
                params: [
                    Param::integer('client_id', 'Партнёр — график только по нему', rules: ['min:1']),
                    Param::boolean('only_overdue', 'Только просроченные строки'),
                    Param::string('date_from', 'Плановая дата с (Y-m-d)', rules: ['date_format:Y-m-d']),
                    Param::string('date_to', 'Плановая дата по (Y-m-d)', rules: ['date_format:Y-m-d']),
                    Param::integer('per_page', 'Строк на странице (до 100)', rules: ['min:1', 'max:100']),
                    Param::integer('page', 'Номер страницы', rules: ['min:1']),
                ],
                handler: [SettlementOperations::class, 'schedule'],
            ),
            new Operation(
                id: 'settlement.reconciliation',
                section: 'settlements',
                method: 'GET',
                uri: 'settlements/reconciliation',
                permission: 'crm-clients.view',
                summary: 'Акт сверки: сальдо на начало, движения за период, сальдо на конец',
                description: 'Тот же документ, что менеджер отправляет партнёру. '
                    .'Формула: сальдо на начало + оплаты + возвраты товара − реализации − возврат денег. '
                    .'Если в ответе заполнено `discrepancy`, сумма движений не сходится с балансом 1С — '
                    .'акт неполный, и сообщать его партнёру нельзя.',
                params: [
                    Param::integer('client_id', 'Партнёр', required: true, rules: ['min:1']),
                    Param::integer('organization_id', 'Наше юрлицо — акт по одному', rules: ['min:1']),
                    Param::string('date_from', 'Начало периода (Y-m-d)', rules: ['date_format:Y-m-d']),
                    Param::string('date_to', 'Конец периода (Y-m-d)', rules: ['date_format:Y-m-d']),
                    Param::string('currency', 'Валюта расчётов (ISO-4217)'),
                ],
                handler: [SettlementOperations::class, 'reconciliation'],
            ),
            new Operation(
                id: 'settlement.debtors',
                section: 'settlements',
                method: 'GET',
                uri: 'settlements/debtors',
                permission: 'crm-clients.view',
                summary: 'Кому звонить: партнёры с просрочкой по убыванию суммы',
                description: 'Просрочка — непогашенные плановые платежи с датой раньше сегодняшней. '
                    .'Партнёр с переплатой сюда не попадает.',
                params: [
                    Param::integer('limit', 'Сколько партнёров вернуть (до 100)', rules: ['min:1', 'max:100']),
                ],
                handler: [SettlementOperations::class, 'debtors'],
            ),
        ];
    }

    /**
     * Платежи из 1С. Только чтение: реквизиты и разнесение ведёт учётная система.
     *
     * @return list<Operation>
     */
    private function payments(): array
    {
        return [
            // `payment.balances` читает `contractor_balances` — канал `balance.updated`,
            // который сторона 1С признала недостоверным (14.08.2026). При включённом
            // регистре операция скрывается: иначе агент видит две почти одинаковые
            // по описанию операции про долг и может ответить по сломанному источнику.
            // Симметрично тому, как `settlements()` скрыт при выключенном регистре.
            ...(config('settlements.ledger_enabled') ? [] : [$this->legacyBalancesOperation()]),
            ...$this->paymentDocuments(),
        ];
    }

    /**
     * Балансы по данным `balance.updated`. Замещены `settlement.balance`.
     */
    private function legacyBalancesOperation(): Operation
    {
        return new Operation(
            id: 'payment.balances',
            section: 'payments',
            method: 'GET',
            uri: 'payments/balances',
            permission: 'crm-clients.view',
            summary: 'Сальдо и просроченная задолженность партнёров по данным 1С',
            description: 'МАСТЕР-ДАННЫЕ по долгам: так их видит учётная система. '
                .'Именно отсюда отвечайте на «сколько партнёр должен» и «какая у него просрочка». '
                .'Не считайте долг суммированием остатков по документам (`payment.unpaid-shipments`): '
                .'1С закрывает долг не только платежами по накладным — есть авансы по заказам, '
                .'зачёты и корректировки, — и сумма по документам систематически больше реального долга. '
                .'Отрицательное сальдо — долг партнёра, положительное — переплата. '
                .'Строка — контрагент (юрлицо), у партнёра их может быть несколько.',
            params: [
                Param::integer('client_id', 'Партнёр — балансы только по нему', rules: ['min:1']),
                Param::boolean('only_overdue', 'Только контрагенты с просроченной задолженностью'),
                Param::integer('per_page', 'Строк на странице (до 100)', rules: ['min:1', 'max:100']),
                Param::integer('page', 'Номер страницы', rules: ['min:1']),
            ],
            handler: [PaymentOperations::class, 'balances'],
        );
    }

    /**
     * Документы оплат: сами платежи, неоплаченные отгрузки, график.
     *
     * @return list<Operation>
     */
    private function paymentDocuments(): array
    {
        return [
            new Operation(
                id: 'payment.list',
                section: 'payments',
                method: 'GET',
                uri: 'payments',
                permission: 'crm-clients.view',
                summary: 'Платежи партнёров: поступления и возвраты с разнесением по отгрузкам',
                description: 'Состав ограничен скоупом актора. Направление передавайте явно: '
                    .'`in` — поступление от партнёра, `out` — возврат партнёру, и суммировать их '
                    .'вместе нельзя. `only_unallocated` отбирает висящие авансы — деньги, '
                    .'которые партнёр заплатил, но которые не привязаны ни к одной отгрузке.',
                params: [
                    Param::integer('client_id', 'Партнёр — платежи только по нему', rules: ['min:1']),
                    Param::string('direction', 'Направление платежа', enum: ['in', 'out']),
                    Param::string('date_from', 'Дата платежа с (Y-m-d)', rules: ['date_format:Y-m-d']),
                    Param::string('date_to', 'Дата платежа по (Y-m-d)', rules: ['date_format:Y-m-d']),
                    Param::boolean('only_unallocated', 'Только платежи с нераспределённым остатком (авансы)'),
                    Param::integer('per_page', 'Платежей на странице (до 100)', rules: ['min:1', 'max:100']),
                    Param::integer('page', 'Номер страницы', rules: ['min:1']),
                ],
                handler: [PaymentOperations::class, 'list'],
            ),
            new Operation(
                id: 'payment.unpaid-shipments',
                section: 'payments',
                method: 'GET',
                uri: 'payments/unpaid-shipments',
                permission: 'crm-clients.view',
                summary: 'Неоплаченные и частично оплаченные отгрузки (остаток по документам)',
                description: 'Отвечает на «какие документы не закрыты», а НЕ на «сколько партнёр должен». '
                    .'Разница существенная: `paid_amount` растёт только от разнесения платежа на саму '
                    .'накладную, а 1С закрывает долг и авансами по заказам, и зачётами, и корректировками. '
                    .'Поэтому сумма остатков здесь бывает в разы больше реальной задолженности — '
                    .'для долга берите `payment.balances`. '
                    .'Суммы взяты из посчитанных полей, а не собраны запросом по разнесению, '
                    .'поэтому возвраты не попадают в приход.',
                params: [
                    Param::integer('client_id', 'Партнёр — отгрузки только по нему', rules: ['min:1']),
                    Param::string('date_from', 'Дата отгрузки с (Y-m-d)', rules: ['date_format:Y-m-d']),
                    Param::integer('per_page', 'Отгрузок на странице (до 100)', rules: ['min:1', 'max:100']),
                    Param::integer('page', 'Номер страницы', rules: ['min:1']),
                ],
                handler: [PaymentOperations::class, 'unpaidShipments'],
            ),
            new Operation(
                id: 'payment.schedule',
                section: 'payments',
                method: 'GET',
                uri: 'payments/schedule',
                permission: 'crm-clients.view',
                summary: 'Ожидаемые поступления по графику оплаты («Правила оплаты» 1С)',
                description: 'Готовый ответ на «сколько денег ждём за период». Отдаёт строки '
                    .'графика, по которым остались деньги, — по одной на плановую дату. '
                    .'Не путайте с `payment.unpaid-shipments`: там остаток по документу целиком, '
                    .'здесь — по конкретной дате, и отгрузка с рассрочкой попадает сюда '
                    .'несколькими строками в разные месяцы. График приходит не по всем '
                    .'отгрузкам: пустой ответ означает «1С его не присылала», а не «долгов нет». '
                    .'Строка считается закрытой, если её покрыли деньгами по накладной ИЛИ авансом '
                    .'по заказу — оба варианта означают, что деньги получены.',
                params: [
                    Param::integer('client_id', 'Партнёр — график только по нему', rules: ['min:1']),
                    Param::string('date_from', 'Плановая дата платежа с (Y-m-d)', rules: ['date_format:Y-m-d']),
                    Param::string('date_to', 'Плановая дата платежа по (Y-m-d)', rules: ['date_format:Y-m-d']),
                    Param::boolean('only_overdue', 'Только строки с прошедшей плановой датой'),
                    Param::integer('per_page', 'Строк на странице (до 100)', rules: ['min:1', 'max:100']),
                    Param::integer('page', 'Номер страницы', rules: ['min:1']),
                ],
                handler: [PaymentOperations::class, 'schedule'],
            ),
            new Operation(
                id: 'payment.show',
                section: 'payments',
                method: 'GET',
                uri: 'payments/{payment}',
                permission: 'crm-clients.view',
                summary: 'Карточка платежа: реквизиты и расшифровка по отгрузкам',
                description: 'Платёж вне скоупа даёт 404, а не 403. Строка расшифровки без '
                    .'`shipment_id` — отгрузка ещё не приехала из 1С, связь доклеится позже.',
                params: [
                    Param::integer('payment', 'Идентификатор платежа', required: true, rules: ['min:1']),
                ],
                handler: [PaymentOperations::class, 'show'],
            ),
        ];
    }

    private function clients(): array
    {
        return [
            new Operation(
                id: 'client.list',
                section: 'clients',
                method: 'GET',
                uri: 'clients',
                permission: 'crm-clients.view',
                summary: 'Список партнёров с фильтрами и сортировкой',
                description: 'Рабочий список партнёров актора. Состав всегда ограничен его скоупом: '
                    .'менеджер видит своих, руководитель отдела — весь отдел. Фильтр по менеджеру '
                    .'у менеджера игнорируется и видимость не расширяет.',
                params: [
                    Param::string('search', 'Поиск по имени, e-mail, телефону или ИНН'),
                    Param::integer('manager_id', 'Менеджер — только для руководителя отдела'),
                    Param::string('lifecycle', 'Жизненный статус', enum: array_column(ClientLifecycleStatus::cases(), 'value')),
                    Param::string('task_state', 'Состояние задач по партнёру', enum: ClientListFilters::TASK_STATES),
                    Param::string('plan_state', 'Состояние выполнения плана', enum: ClientListFilters::PLAN_STATES),
                    Param::integer('inactive_days', 'Нет активности дольше скольких дней', rules: ['in:30,60,90']),
                    Param::string('sort_by', 'Поле сортировки', enum: ClientListFilters::SORTS),
                    Param::string('sort_order', 'Направление сортировки', enum: ['asc', 'desc']),
                    Param::integer('per_page', 'Партнёров на странице (до 100)', rules: ['min:1', 'max:100']),
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
                summary: 'Карточка партнёра: контакты, менеджер, план и факт месяца',
                description: 'Профиль возвращается только при праве crm-profile.view. '
                    .'Партнёр вне скоупа даёт 404, а не 403: существование записи не подтверждается.',
                params: [
                    Param::integer('client', 'Идентификатор партнёра', required: true, rules: ['min:1']),
                ],
                handler: [ClientOperations::class, 'show'],
            ),
            new Operation(
                id: 'client.sales',
                section: 'clients',
                method: 'GET',
                uri: 'clients/{client}/sales',
                permission: 'crm-analytics.view',
                summary: 'Сводка продаж партнёра: деньги, динамика, бренды, категории, товары',
                description: 'Факт продаж — отгрузки по дате документа в 1С (erp_created_at), '
                    .'а не по created_at: историю импортировали в мае 2026, и отчёт по created_at '
                    .'будет неверным и выглядеть правдоподобно.',
                params: [
                    Param::integer('client', 'Идентификатор партнёра', required: true, rules: ['min:1']),
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
                summary: 'Профиль партнёра: ЛПР, паспорт бизнеса, условия, ограничения, заметки',
                description: 'То, что знает менеджер и не знает 1С. В ответе есть passport_completeness — '
                    .'сколько полей паспорта заполнено: по нему видно, кого стоит расспросить.',
                params: [
                    Param::integer('client', 'Идентификатор партнёра', required: true, rules: ['min:1']),
                ],
                handler: [ProfileOperations::class, 'show'],
            ),
            new Operation(
                id: 'client.profile.update',
                section: 'profile',
                method: 'PATCH',
                uri: 'clients/{client}/profile',
                permission: 'crm-profile.edit',
                summary: 'Обновить поля профиля, паспорт партнёра и заметки менеджера',
                description: 'Передавайте только те поля, которые меняете: непереданное остаётся как было, '
                    .'а переданное пустым — очищается. Лояльность партнёра (client_status) здесь не меняется: '
                    .'ею владеет 1С и перезапишет её следующим обменом. Поля паспорта (вид бизнеса, сегмент, '
                    .'логистика, условия, табу, контакты по ролям) собираются интервью с менеджером и заполняются '
                    .'по мере разговора — сохраняйте после каждого блока, а не в конце.',
                params: array_merge([
                    Param::integer('client', 'Идентификатор партнёра', required: true, rules: ['min:1']),
                    Param::string('decision_maker_name', 'Имя ЛПР', rules: ['max:255'], nullable: true),
                    Param::string('decision_maker_role', 'Должность ЛПР', rules: ['max:255'], nullable: true),
                    Param::string('decision_maker_contact', 'Контакт ЛПР', rules: ['max:255'], nullable: true),
                    Param::string('decision_process', 'Как принимается решение о закупке', rules: ['max:5000'], nullable: true),
                    Param::string('payment_behavior', 'Платёжное поведение — наблюдение менеджера', enum: array_column(PaymentBehavior::cases(), 'value'), nullable: true),
                    Param::string('payment_terms', 'Условия оплаты', rules: ['max:255'], nullable: true),
                    Param::integer('order_cycle_days', 'Обычная периодичность закупок, дней', rules: ['min:1', 'max:1095'], nullable: true),
                    Param::string('preferred_channel', 'Предпочитаемый канал связи', enum: array_column(PreferredChannel::cases(), 'value'), nullable: true),
                    Param::string('sentiment', 'Настроение партнёра', enum: array_column(ClientSentiment::cases(), 'value'), nullable: true),
                    Param::string('notes_md', 'Заметки менеджера (Markdown) — всё, что не уложилось в поля', rules: ['max:65535'], nullable: true),
                    Param::list('interests', 'Интересы партнёра — список названий', required: false),
                ], ClientPassport::apiParams()),
                handler: [ProfileOperations::class, 'update'],
                mutating: true,
            ),
            new Operation(
                id: 'client.lifecycle.change',
                section: 'profile',
                method: 'POST',
                uri: 'clients/{client}/lifecycle',
                permission: 'crm-profile.edit',
                summary: 'Сменить жизненный статус партнёра с указанием причины',
                description: 'Жизненный статус — поле сайта, его ведёт отдел продаж. '
                    .'Это не лояльность из 1С и не блокировка аккаунта.',
                params: [
                    Param::integer('client', 'Идентификатор партнёра', required: true, rules: ['min:1']),
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
                    Param::integer('client', 'Идентификатор партнёра', required: true, rules: ['min:1']),
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
                summary: 'Справочник интересов партнёров',
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
                summary: 'Сквозная лента партнёра: записи менеджеров и его документы',
                description: 'Комментарии, задачи, письма, звонки, заказы и реализации в одной хронологии.',
                params: [
                    Param::integer('client', 'Идентификатор партнёра', required: true, rules: ['min:1']),
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
                description: 'Лента конкретного партнёра, заказа, реализации или задачи.',
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
                summary: 'Оставить комментарий по партнёру, заказу, реализации или задаче',
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
                description: 'Чужой комментарий недоступен, даже если партнёр виден.',
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
                    Param::integer('client_id', 'Задачи по конкретному партнёру', rules: ['min:1']),
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
                summary: 'Поставить задачу, при необходимости привязав к партнёру или документу',
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
                    Param::integer('estimate_minutes', 'Плановая трудоёмкость в минутах', rules: ['min:1', 'max:4800'], nullable: true),
                    Param::list('co_assignee_ids', 'Соисполнители — массив идентификаторов сотрудников с CRM-доступом', 'integer'),
                    Param::list('checklist', 'Чек-лист — массив текстов пунктов, создаются вместе с задачей'),
                    Param::list('tags', 'Теги задачи — массив строк (до 10)'),
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
                    Param::integer('estimate_minutes', 'Плановая трудоёмкость в минутах', rules: ['min:1', 'max:4800'], nullable: true),
                    Param::list('co_assignee_ids', 'Соисполнители — полный новый состав (массив идентификаторов)', 'integer'),
                    Param::list('tags', 'Теги — полный новый набор (массив строк, до 10)'),
                ],
                handler: [TaskOperations::class, 'update'],
                mutating: true,
            ),
            new Operation(
                id: 'task.checklist.add',
                section: 'tasks',
                method: 'POST',
                uri: 'tasks/{task}/checklist',
                permission: 'crm-tasks.edit',
                summary: 'Добавить пункт чек-листа задачи',
                description: 'Пункт добавляется в конец списка. Чек-лист — плоские todo без сроков '
                    .'и исполнителей: «обзвонить 5 партнёров» — это 5 галочек, а не 5 задач.',
                params: [
                    Param::integer('task', 'Идентификатор задачи', required: true, rules: ['min:1']),
                    Param::string('title', 'Текст пункта', required: true, rules: ['min:1', 'max:500']),
                ],
                handler: [TaskOperations::class, 'checklistAdd'],
                mutating: true,
            ),
            new Operation(
                id: 'task.checklist.toggle',
                section: 'tasks',
                method: 'PATCH',
                uri: 'tasks/{task}/checklist/{item}',
                permission: 'crm-tasks.edit',
                summary: 'Отметить или снять пункт чек-листа',
                description: 'Кто отметил — фиксируется: в командной задаче видно, чья галочка.',
                params: [
                    Param::integer('task', 'Идентификатор задачи', required: true, rules: ['min:1']),
                    Param::integer('item', 'Идентификатор пункта', required: true, rules: ['min:1']),
                    Param::boolean('is_done', 'Состояние пункта', required: true),
                ],
                handler: [TaskOperations::class, 'checklistToggle'],
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
                    .'с партнёром рассыпается на разовые дёрганья.',
                params: [
                    Param::integer('task', 'Идентификатор задачи', required: true, rules: ['min:1']),
                    Param::string('outcome', 'Исход: success — успешно (по умолчанию), problem — с проблемой (comment обязателен)', enum: array_column(TaskOutcome::cases(), 'value')),
                    Param::string('comment', 'Что сделано', rules: ['max:5000'], nullable: true),
                    new Param('follow_up', 'object', 'Следующий шаг: title, description, due_at, priority, assignee_id'),
                ],
                handler: [TaskOperations::class, 'close'],
                mutating: true,
            ),
            new Operation(
                id: 'task.postpone',
                section: 'tasks',
                method: 'POST',
                uri: 'tasks/{task}/postpone',
                permission: 'crm-tasks.edit',
                summary: 'Перенести срок задачи',
                description: 'Перенос — не закрытие: задача остаётся открытой, растёт счётчик переносов, '
                    .'в комментарии задачи фиксируется «с какой даты на какую и почему».',
                params: [
                    Param::integer('task', 'Идентификатор задачи', required: true, rules: ['min:1']),
                    Param::string('due_at', 'Новый срок в формате ГГГГ-ММ-ДД или ГГГГ-ММ-ДД ЧЧ:ММ', required: true, rules: ['date']),
                    Param::string('reason', 'Причина переноса', rules: ['max:1000'], nullable: true),
                ],
                handler: [TaskOperations::class, 'postpone'],
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
                    Param::integer('client_id', 'Звонки по конкретному партнёру', rules: ['min:1']),
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
     * Справочник людей.
     *
     * Ключевая операция — `contact.by_email`: разбирая письмо, агент спрашивает
     * «чей это адрес», и при промахе заводит карточку. Со следующего письма
     * подшивка к человеку идёт сама.
     *
     * @return list<Operation>
     */
    private function contacts(): array
    {
        return [
            new Operation(
                id: 'contact.list',
                section: 'contacts',
                method: 'GET',
                uri: 'contacts',
                permission: 'crm-contacts.view',
                summary: 'Справочник контактных лиц',
                description: 'Поиск по ФИО, телефону, почте и должности. Телефон ищется по цифрам, '
                    .'поэтому «8 912…» и «+7 912…» находят одного и того же человека.',
                params: [
                    Param::string('search', 'ФИО, телефон, почта или должность'),
                    Param::string('role', 'Роль при сущности', enum: ContactRole::values()),
                    Param::integer('client_id', 'Контакты одного партнёра', rules: ['min:1']),
                    Param::string('activity', 'Активность', enum: ['active', 'inactive', 'all']),
                    Param::integer('per_page', 'Записей на странице (до 100)', rules: ['min:1', 'max:100']),
                ],
                handler: [ContactOperations::class, 'list'],
            ),
            new Operation(
                id: 'contact.show',
                section: 'contacts',
                method: 'GET',
                uri: 'contacts/{contact}',
                permission: 'crm-contacts.view',
                summary: 'Карточка человека',
                description: 'Контакт вне скоупа актора даёт 404.',
                params: [
                    Param::integer('contact', 'Идентификатор контакта', required: true, rules: ['min:1']),
                ],
                handler: [ContactOperations::class, 'show'],
            ),
            new Operation(
                id: 'contact.by_email',
                section: 'contacts',
                method: 'GET',
                uri: 'contacts/by-email',
                permission: 'crm-contacts.view',
                summary: 'Чей это адрес',
                description: 'Отдаёт карточку человека и партнёра. Партнёр находится и без карточки — '
                    .'по аккаунту или почте юрлица, поэтому пустой ответ по контакту не значит «клиент неизвестен».',
                params: [
                    Param::string('email', 'Адрес электронной почты', required: true, rules: ['max:191']),
                ],
                handler: [ContactOperations::class, 'byEmail'],
            ),
            new Operation(
                id: 'contact.create',
                section: 'contacts',
                method: 'POST',
                uri: 'contacts',
                permission: 'crm-contacts.create',
                summary: 'Завести человека',
                description: 'Партнёр вне скоупа актора молча не проставляется: приписать человека '
                    .'чужому клиенту нельзя, даже зная его идентификатор.',
                params: [
                    Param::string('full_name', 'ФИО', required: true, rules: ['max:191']),
                    Param::string('greeting_name', 'Как обращаться', rules: ['max:100']),
                    Param::string('position', 'Должность', rules: ['max:191']),
                    Param::string('email', 'Почта', rules: ['email', 'max:191']),
                    Param::string('phone', 'Телефон', rules: ['max:50']),
                    Param::string('telegram', 'Telegram', rules: ['max:100']),
                    Param::integer('client_id', 'Партнёр, к которому относится человек', rules: ['min:1']),
                ],
                handler: [ContactOperations::class, 'create'],
                mutating: true,
            ),
            new Operation(
                id: 'contact.update',
                section: 'contacts',
                method: 'PATCH',
                uri: 'contacts/{contact}',
                permission: 'crm-contacts.edit',
                summary: 'Дополнить карточку',
                description: 'Обычный путь после разговора: узнали новый телефон — дописали.',
                params: [
                    Param::integer('contact', 'Идентификатор контакта', required: true, rules: ['min:1']),
                    Param::string('full_name', 'ФИО', rules: ['max:191']),
                    Param::string('greeting_name', 'Как обращаться', rules: ['max:100']),
                    Param::string('position', 'Должность', rules: ['max:191']),
                    Param::string('email', 'Почта', rules: ['email', 'max:191']),
                    Param::string('phone', 'Телефон', rules: ['max:50']),
                    Param::string('telegram', 'Telegram', rules: ['max:100']),
                ],
                handler: [ContactOperations::class, 'update'],
                mutating: true,
            ),
            new Operation(
                id: 'contact.link',
                section: 'contacts',
                method: 'POST',
                uri: 'contacts/{contact}/links',
                permission: 'crm-contacts.edit',
                summary: 'Привязать человека к сущности с ролью',
                description: 'Привязка к чужой сущности даёт 404.',
                params: [
                    Param::integer('contact', 'Идентификатор контакта', required: true, rules: ['min:1']),
                    Param::string('entity_type', 'Тип сущности', required: true, enum: CrmEntityMap::contactLinkableTypes()),
                    Param::integer('entity_id', 'Идентификатор сущности', required: true, rules: ['min:1']),
                    Param::string('role', 'Роль', required: true, enum: ContactRole::values()),
                ],
                handler: [ContactOperations::class, 'link'],
                mutating: true,
            ),
            new Operation(
                id: 'client.contacts',
                section: 'contacts',
                method: 'GET',
                uri: 'clients/{client}/contacts',
                permission: 'crm-contacts.view',
                summary: 'Адресная книга партнёра',
                description: 'Все люди партнёра, включая привязанных к его юрлицам.',
                params: [
                    Param::integer('client', 'Идентификатор партнёра', required: true, rules: ['min:1']),
                ],
                handler: [ContactOperations::class, 'forClient'],
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
                    Param::integer('client_id', 'Письма по конкретному партнёру', rules: ['min:1']),
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
                description: 'Цели: отдел, менеджер, партнёр. Видны только те, что доступны актору.',
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
                summary: 'Выполнение плана: план, факт, отставание и разбивка по партнёрам',
                description: 'Факт — отгрузки по дате документа в 1С.',
                params: [
                    Param::string('month', 'Месяц в формате ГГГГ-ММ', rules: ['max:7']),
                    Param::string('scope', 'Разрез: department или manager', enum: ['department', 'manager']),
                    Param::integer('scope_id', 'Менеджер для разреза manager', rules: ['min:1']),
                    Param::integer('limit', 'Сколько партнёров вернуть (до 200)', rules: ['min:1', 'max:200']),
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
