<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Все ресурсы админки и их доступные действия.
     */
    protected array $resources = [
        // Каталог
        'products' => ['view', 'create', 'edit', 'delete'],
        // Себестоимость — отдельное право: карточку товара ведут одни, а во сколько
        // товар обошёлся, видят другие. Только просмотр: значение пишет 1С.
        'product-costs' => ['view'],
        'categories' => ['view', 'create', 'edit', 'delete'],
        'brands' => ['view', 'create', 'edit', 'delete'],
        'product-models' => ['view', 'create', 'edit', 'delete'],
        'attributes' => ['view', 'create', 'edit', 'delete'],
        'attribute-groups' => ['view', 'create', 'edit', 'delete'],
        'size-charts' => ['view', 'create', 'edit', 'delete'],
        'product-barcodes' => ['view', 'create', 'edit', 'delete'],
        'certificates' => ['view', 'create', 'edit', 'delete'],
        'product-exports' => ['view', 'create', 'edit', 'delete'],

        // Склады
        'warehouses' => ['view', 'create', 'edit', 'delete'],
        'regions' => ['view', 'create', 'edit', 'delete'],

        // Продажи
        'orders' => ['view', 'create', 'edit', 'delete'],
        'carts' => ['view', 'create', 'edit', 'delete'],
        'returns' => ['view', 'create', 'edit', 'delete'],
        'shipments' => ['view', 'delete'],
        'favorites' => ['view', 'create', 'edit', 'delete'],
        'wishlist' => ['view', 'create', 'edit', 'delete'],

        // Маркетинг
        'promotions' => ['view', 'create', 'edit', 'delete'],
        // Механика акций (конструктор промо) — отдельно от контентных лендингов:
        // правка выдачи товаров это не то же самое, что правка текста акции.
        'promotion-rules' => ['view', 'create', 'edit', 'delete'],
        'product-selections' => ['view', 'create', 'edit', 'delete'],

        // Пользователи
        'users' => ['view', 'create', 'edit', 'delete'],
        'user-questionnaires' => ['view', 'create', 'edit', 'delete'],
        'client-statuses' => ['view', 'create', 'edit', 'delete'],
        'personal-managers' => ['view', 'create', 'edit', 'delete'],
        'companies' => ['view', 'create', 'edit', 'delete'],
        'company-bank-accounts' => ['view', 'create', 'edit', 'delete'],
        'delivery-addresses' => ['view', 'create', 'edit', 'delete'],

        // Финансы
        // Организации — наши юрлица, от имени которых 1С проводит документы.
        // Справочник ведёт админ вручную (1С его не присылает), поэтому права
        // намеренно не выданы менеджерам: они видят организацию в самих документах.
        'organizations' => ['view', 'create', 'edit', 'delete'],
        'currencies' => ['view', 'create', 'edit', 'delete'],
        'contractor-balances' => ['view', 'create', 'edit', 'delete'],
        // Платежи из 1С: реквизиты только для чтения, мастер — учётная система.
        // edit — локальные поля (комментарий, вложения), delete — мягкое удаление
        // и восстановление.
        'payments' => ['view', 'edit', 'delete'],
        'individual-prices' => ['view', 'create', 'edit', 'delete'],

        // Контент
        'articles' => ['view', 'create', 'edit', 'delete'],
        'brand-stories' => ['view', 'create', 'edit', 'delete'],
        'news' => ['view', 'create', 'edit', 'delete'],
        'faqs' => ['view', 'create', 'edit', 'delete'],
        'banners' => ['view', 'create', 'edit', 'delete'],
        'pages' => ['view', 'create', 'edit', 'delete'],
        'stories' => ['view', 'create', 'edit', 'delete'],
        'menu-items' => ['view', 'create', 'edit', 'delete'],
        'user-questions' => ['view', 'edit', 'delete'],

        // Теги
        'tags' => ['view', 'create', 'edit', 'delete'],

        // CRM — права домена /crm/. Префикс `crm-` значим: по нему
        // User::hasAdminAccess() отличает CRM-only сотрудника от админского.
        'crm-dashboard' => ['view'],
        // Партнёры (в коде и правах исторически «clients» — переименование
        // затронуло бы выданные роли на проде, поэтому имя оставлено).
        'crm-clients' => ['view', 'edit'],
        // Контрагенты — юрлица партнёров. Только view: реквизиты ведёт 1С,
        // в CRM карточка нужна для переписки, задач и файлов.
        'crm-contractors' => ['view'],
        // edit — управление составом клиентской базы отдела: пометить аккаунт
        // как не-клиента (сотрудник, служебный). Менеджеру недоступно намеренно:
        // убрав неудобного покупателя из базы, он убрал бы его и из своего плана.
        //
        // view — РОПовское: разрез выручки по менеджерам, план отдела и планы
        // менеджеров, фильтр по менеджерам. НЕ путать с `crm-department.view`:
        // «вижу карточку партнёра коллеги» и «вижу, сколько коллега продал» —
        // разные полномочия, и второе рядовому менеджеру не выдаётся.
        'crm-clients-all' => ['view', 'edit'],
        // Взаимозаменяемость менеджеров: view — вижу записи всего отдела
        // (партнёров, задачи, звонки, письма, документы), edit — действую
        // с чужими записями. Раньше и то и другое ехало вместе с
        // `crm-clients-all.view`, из-за чего видимость отдела нельзя было выдать,
        // не отдав заодно право менять план отдела и удалять чужие файлы.
        'crm-department' => ['view', 'edit'],
        // edit — скрыть нерабочую карточку менеджера из списков и выборов.
        'crm-team' => ['view', 'edit'],
        // Отсутствия и замещения: view — у всего отдела (кто кого замещает —
        // рабочая информация), edit — создание и завершение, только руководитель.
        'crm-absences' => ['view', 'edit'],
        // Табель отдела: только view и только руководитель — прогулы коллег
        // не для всеобщего обозрения. Данные табеля производны от отсутствий.
        'crm-timesheet' => ['view'],
        'crm-analytics' => ['view'],
        // Профиль клиента: view — читать заметки и ЛПР, edit — править их
        // и менять жизненный статус (журнал смен живёт в том же профиле).
        'crm-profile' => ['view', 'edit'],
        'crm-comments' => ['view', 'create', 'edit', 'delete'],
        // Задачи: edit — правка и смена статуса (в т.ч. закрытие исполнителем),
        // delete — снятие задачи автором или РОПом.
        'crm-tasks' => ['view', 'create', 'edit', 'delete'],
        'crm-calls' => ['view', 'create', 'edit', 'delete'],
        // Письма: create — составить и отправить, edit — править черновик,
        // delete — удалить неотправленное (отправленное остаётся в журнале навсегда).
        'crm-emails' => ['view', 'create', 'edit', 'delete'],
        // Планы продаж: право есть у всего отдела, но границы задаёт скоуп —
        // менеджер расписывает только своих клиентов, план отдела и планы
        // менеджеров ставит тот, кто видит отдел целиком (crm-clients-all.view).
        'crm-plans' => ['view', 'create', 'edit', 'delete'],
        // Возможности: только view — список ничего не меняет, он предлагает,
        // а действия по строке идут через права задач, писем и звонков.
        'crm-opportunities' => ['view'],
        // Грядки: только view — витрина поверх планов и сигналов, ничего не меняет.
        'crm-beds' => ['view'],
        // Финансы: только view — раздел читает деньги из 1С и ничего не меняет,
        // а действия по строке идут через права задач. Границы задаёт скоуп клиентов.
        'crm-finance' => ['view'],
        // Недоборы: view — очередь и карточка, edit — кандидаты, письмо и исходы.
        // Границы задаёт скоуп клиентов, как в остальных разделах.
        'crm-shortages' => ['view', 'edit'],
        // Вложения: edit нет — заменить файл это удалить и загрузить заново.
        'crm-attachments' => ['view', 'create', 'delete'],
        // Лиды: потенциальные клиенты до появления партнёра в 1С.
        // delete есть: в отличие от партнёра, лида завёл менеджер, и удалить
        // случайный дубль должен он же, а не администратор базы.
        'crm-leads' => ['view', 'create', 'edit', 'delete'],
        // Стадии воронки настраивает руководитель: менеджер двигает лида,
        // но не переписывает воронку отдела.
        'crm-lead-stages' => ['view', 'create', 'edit', 'delete'],
        // Токены ИИ-агентов: выдача и отзыв. Только РОП — токен даёт запись
        // в CRM от имени сотрудника, и раздавать их самим сотрудникам нельзя.
        'crm-agent-tokens' => ['view', 'create', 'delete'],
        // Просмотр сайта от имени клиента. Действие одно и не раскладывается
        // на view/edit: это не раздел, а переключатель сессии. Границы задаёт
        // скоуп клиентов — менеджер входит только под своими.
        'crm-impersonate' => ['use'],

        // WMS — права домена /wms/ (кабинет склада). Префикс `wms-` значим:
        // он в User::PANEL_PERMISSION_PREFIXES, поэтому не даёт входа в /admin.
        'wms-dashboard' => ['view'],
        'wms-defects' => ['view', 'create', 'edit', 'delete'],
        // Справочник дефектов глазами склада: формулировки ведёт начальник склада,
        // кладовщику они приезжают чипами (право не выдаётся).
        'wms-defect-types' => ['view', 'create', 'edit', 'delete'],
        // Расходные ордера из 1С: журнал только на чтение — статусами управляет 1С,
        // поэтому действий create/edit/delete у ресурса нет принципиально.
        'wms-goods-issues' => ['view', 'export'],
        // Отправки транспортными компаниями (ApiShip). `submit` отдельно от `edit`:
        // собрать груз и передать заявку перевозчику — разные по цене ошибки решения.
        'wms-deliveries' => ['view', 'create', 'edit', 'submit', 'cancel'],
        // Настройки ApiShip: токен боевого API и адрес отправителя. Только начальнику
        // склада — сломать ими можно весь раздел разом.
        'wms-delivery-settings' => ['view', 'edit'],
        // Страховой запас (эпик buf-00): рисковые SKU с занижением показа
        // и ручные пометки склада «придержи N шт».
        'wms-stock-buffers' => ['view', 'edit'],

        // Уценка глазами закупщика — админский ресурс (без `wms-` префикса):
        // цену и публикацию задаёт buyer-manager в /admin, а не кладовщик.
        'defects' => ['view', 'price', 'publish'],
        // Справочник типовых дефектов (быстрый выбор для кладовщика).
        'defect-types' => ['view', 'create', 'edit', 'delete'],

        // Предзаказы, отправленные поставщику (Customer API sex-opt.ru)
        'supplier-preorders' => ['view', 'send'],

        // Система
        'erp-bus' => ['view'],
        // Диалоги ИИ-агентов (сайт ↔ 1С): view — читать топики и треды,
        // create — заводить топики и получать ссылки сторон,
        // edit — модераторские действия (сообщение, передача хода, закрытие).
        'agent-topics' => ['view', 'create', 'edit'],
        'media' => ['view', 'delete'],
        'settings' => ['view', 'edit'],
        'roles' => ['view', 'create', 'edit', 'delete'],
    ];

    /**
     * Русские названия ресурсов для отображения.
     */
    protected array $resourceLabels = [
        'products' => 'Товары',
        'product-costs' => 'Себестоимость товаров',
        'categories' => 'Категории',
        'brands' => 'Бренды',
        'product-models' => 'Модели товаров',
        'attributes' => 'Атрибуты',
        'attribute-groups' => 'Группы атрибутов',
        'size-charts' => 'Размерные сетки',
        'product-barcodes' => 'Штрихкоды',
        'certificates' => 'Сертификаты',
        'product-exports' => 'Выгрузки',
        'warehouses' => 'Склады',
        'regions' => 'Регионы',
        'orders' => 'Заказы',
        'carts' => 'Корзины',
        'returns' => 'Возвраты',
        'shipments' => 'Реализации',
        'favorites' => 'Избранное',
        'wishlist' => 'Список желаний',
        'promotions' => 'Акции',
        'promotion-rules' => 'Правила акций',
        'product-selections' => 'Подборки',
        'users' => 'Пользователи',
        'user-questionnaires' => 'Анкеты',
        'client-statuses' => 'Статусы клиентов',
        'personal-managers' => 'Персональные менеджеры',
        'companies' => 'Компании',
        'company-bank-accounts' => 'Банковские счета',
        'delivery-addresses' => 'Адреса доставки',
        'organizations' => 'Организации (наши юрлица)',
        'currencies' => 'Валюты',
        'contractor-balances' => 'Балансы контрагентов',
        'payments' => 'Платежи (из 1С)',
        'individual-prices' => 'Инд. цены',
        'articles' => 'Статьи',
        'brand-stories' => 'О брендах',
        'news' => 'Новости',
        'faqs' => 'FAQ',
        'banners' => 'Баннеры',
        'pages' => 'Страницы',
        'stories' => 'Истории',
        'menu-items' => 'Меню',
        'user-questions' => 'Вопросы пользователей',
        'tags' => 'Теги',
        'crm-dashboard' => 'CRM: Рабочий стол',
        'crm-clients' => 'CRM: Мои партнёры',
        'crm-clients-all' => 'CRM: Партнёры всего отдела',
        'crm-contractors' => 'CRM: Контрагенты',
        'crm-team' => 'CRM: Команда',
        'crm-absences' => 'CRM: Отсутствия и замещения',
        'crm-timesheet' => 'CRM: Табель отдела',
        'crm-analytics' => 'CRM: Отчёты продаж',
        'crm-profile' => 'CRM: Профиль партнёра',
        'crm-comments' => 'CRM: Комментарии',
        'crm-tasks' => 'CRM: Задачи',
        'crm-calls' => 'CRM: Звонки',
        'crm-emails' => 'CRM: Письма',
        'crm-plans' => 'CRM: Планы продаж',
        'crm-opportunities' => 'CRM: Возможности',
        'crm-beds' => 'CRM: Грядки',
        'crm-shortages' => 'CRM: Недоборы',
        'crm-attachments' => 'CRM: Вложения',
        'crm-agent-tokens' => 'CRM: Токены ИИ-агентов',
        'crm-impersonate' => 'CRM: Вход под партнёром',
        'wms-dashboard' => 'Склад: Рабочий стол',
        'wms-defects' => 'Склад: Некондиция',
        'wms-defect-types' => 'Склад: Справочник дефектов',
        'wms-goods-issues' => 'Склад: Расходные ордера',
        'wms-stock-buffers' => 'Склад: Страховой запас',
        'defects' => 'Уценка (цены и публикация)',
        'defect-types' => 'Справочник дефектов',
        'supplier-preorders' => 'Предзаказы поставщику',
        'erp-bus' => 'Шина ERP',
        'agent-topics' => 'Диалоги ИИ-агентов',
        'media' => 'Медиа',
        'settings' => 'Настройки',
        'roles' => 'Роли',
    ];

    /**
     * Предустановленные роли и их ресурсы.
     */
    protected array $presetRoles = [
        'super-admin' => [
            'label' => 'Супер-админ',
            'resources' => '*', // Все ресурсы
        ],
        'content-manager' => [
            'label' => 'Контент-менеджер',
            'resources' => [
                'articles', 'brand-stories', 'news', 'faqs',
                'banners', 'pages', 'stories', 'tags', 'media',
                'menu-items', 'user-questions',
                // Механику акций контент-менеджер только смотрит
                'promotion-rules' => ['view'],
            ],
        ],
        'sales-manager' => [
            'label' => 'Менеджер продаж',
            'resources' => [
                'orders', 'carts', 'returns', 'shipments',
                'favorites', 'wishlist', 'supplier-preorders',
                // Финансовые документы менеджер только смотрит
                'payments' => ['view'],
                // CRM: партнёры отдела. Менеджеры взаимозаменяемы — экран открывается
                // сфокусированным на своих, расфокус остаётся осознанным действием.
                'crm-dashboard', 'crm-clients', 'crm-department', 'crm-contractors', 'crm-profile', 'crm-analytics', 'crm-comments', 'crm-attachments', 'crm-tasks', 'crm-calls', 'crm-emails', 'crm-plans', 'crm-opportunities', 'crm-beds', 'crm-finance', 'crm-shortages', 'crm-leads', 'crm-lead-stages' => ['view'], 'crm-absences' => ['view'], 'crm-impersonate',
            ],
        ],
        'sales-manager-crm' => [
            'label' => 'Менеджер продаж (только CRM)',
            'resources' => [
                // Только CRM: в /admin роль намеренно не пускает.
                // Для менеджеров, которым нужны партнёры отдела, но не нужна админка.
                'crm-dashboard', 'crm-clients', 'crm-department', 'crm-contractors', 'crm-profile', 'crm-analytics', 'crm-comments', 'crm-attachments', 'crm-tasks', 'crm-calls', 'crm-emails', 'crm-plans', 'crm-opportunities', 'crm-beds', 'crm-finance', 'crm-shortages', 'crm-leads', 'crm-lead-stages' => ['view'], 'crm-absences' => ['view'], 'crm-impersonate',
            ],
        ],
        'sales-head' => [
            'label' => 'Руководитель отдела продаж',
            'resources' => [
                // Только CRM: в /admin роль намеренно не пускает.
                'crm-dashboard', 'crm-clients', 'crm-clients-all', 'crm-department', 'crm-leads', 'crm-lead-stages', 'crm-contractors', 'crm-team', 'crm-absences', 'crm-timesheet', 'crm-profile', 'crm-analytics', 'crm-comments', 'crm-attachments', 'crm-tasks', 'crm-calls', 'crm-emails', 'crm-plans', 'crm-opportunities', 'crm-beds', 'crm-finance', 'crm-shortages', 'crm-agent-tokens', 'crm-impersonate',
                // Себестоимость руководителю отдела появится вместе с отчётом по марже
                // и только под `crm-`-префиксом: `product-costs` — админский ресурс,
                // и выдача его этой роли открыла бы ей вход в /admin (PermissionNamingTest).
            ],
        ],
        'catalogist' => [
            'label' => 'Каталоговед',
            'resources' => [
                'products', 'categories', 'brands', 'product-models',
                'attributes', 'attribute-groups', 'size-charts',
                'product-barcodes', 'certificates', 'product-exports',
            ],
        ],
        // Склад. Разграничение ролей пока точечное: справочник дефектов и отмена
        // заявки в ТК — за начальником, остальное общее.
        'warehouse-head' => [
            'label' => 'Начальник склада',
            'resources' => [
                // Только WMS: в /admin роль намеренно не пускает.
                // Справочник дефектов ведёт начальник склада — у кладовщика его нет.
                'wms-dashboard', 'wms-defects', 'wms-defect-types', 'wms-goods-issues',
                'wms-deliveries', 'wms-delivery-settings', 'wms-stock-buffers',
            ],
        ],
        'storekeeper' => [
            'label' => 'Кладовщик',
            'resources' => [
                // Только WMS: в /admin роль намеренно не пускает.
                'wms-dashboard', 'wms-defects', 'wms-goods-issues', 'wms-stock-buffers',
                // Отправки собирает и передаёт в ТК кладовщик, но отменить
                // уже принятую перевозчиком заявку может только начальник склада.
                'wms-deliveries' => ['view', 'create', 'edit', 'submit'],
            ],
        ],
        // Роль buyer-manager (закупщик) намеренно не описана здесь: она заведена
        // вручную на prod со своим набором админских прав, а syncPermissions выше
        // этот набор бы перезаписал. Права defects.* ей доназначает миграция
        // 2026_07_20_100400_grant_defect_permissions.
    ];

    public function run(): void
    {
        // Сбрасываем кеш прав
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // 1. Создаём все permissions
        $allPermissions = [];
        foreach ($this->resources as $resource => $actions) {
            foreach ($actions as $action) {
                $permissionName = "{$resource}.{$action}";
                $permission = Permission::firstOrCreate([
                    'name' => $permissionName,
                    'guard_name' => 'web',
                ]);
                $allPermissions[$permissionName] = $permission;
            }
        }

        $this->command->info('Создано '.count($allPermissions).' прав.');

        // 2. Создаём роли и назначаем permissions
        foreach ($this->presetRoles as $roleName => $config) {
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
            ]);

            if ($config['resources'] === '*') {
                // Super-admin получает все permissions
                $role->syncPermissions(array_values($allPermissions));
                $this->command->info("Роль «{$config['label']}» — все права ({$role->permissions()->count()}).");
            } else {
                // Собираем permissions для конкретных ресурсов.
                // Элемент списка — либо название ресурса (все его действия),
                // либо пара «ресурс => [действия]» для частичного доступа.
                $rolePermissions = [];
                foreach ($config['resources'] as $key => $value) {
                    $resource = is_int($key) ? $value : $key;

                    if (! isset($this->resources[$resource])) {
                        continue;
                    }

                    $actions = is_int($key)
                        ? $this->resources[$resource]
                        : array_intersect((array) $value, $this->resources[$resource]);

                    foreach ($actions as $action) {
                        $permissionKey = "{$resource}.{$action}";
                        if (isset($allPermissions[$permissionKey])) {
                            $rolePermissions[] = $allPermissions[$permissionKey];
                        }
                    }
                }
                $role->syncPermissions($rolePermissions);
                $this->command->info("Роль «{$config['label']}» — {$role->permissions()->count()} прав.");
            }
        }

        // 3. Назначаем super-admin всем текущим пользователям с is_admin = true (если колонка ещё существует)
        if (\Illuminate\Support\Facades\Schema::hasColumn('users', 'is_admin')) {
            $adminUsers = User::where('is_admin', true)->get();
            $superAdminRole = Role::findByName('super-admin', 'web');

            foreach ($adminUsers as $user) {
                if (! $user->hasRole('super-admin')) {
                    $user->assignRole($superAdminRole);
                }
            }

            $this->command->info("Роль «Супер-админ» назначена {$adminUsers->count()} пользователям с is_admin=true.");
        } else {
            $this->command->info('Колонка is_admin удалена — миграция пользователей пропущена.');
        }
    }
}
