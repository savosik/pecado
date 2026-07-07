<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Проставляет человекочитаемые комментарии (на русском) ко всем таблицам и столбцам БД.
 *
 * Цель — чтобы ИИ-агент, подключившись к базе, через `SHOW FULL COLUMNS` /
 * information_schema мог сразу понять назначение каждой таблицы и поля без чтения кода.
 *
 * Механика:
 *  - точный DDL каждого столбца берётся из `SHOW CREATE TABLE` (тип, NULL, DEFAULT,
 *    charset, AUTO_INCREMENT, ON UPDATE сохраняются как есть) и переиздаётся через
 *    один `ALTER TABLE ... MODIFY COLUMN ... COMMENT` на таблицу;
 *  - на MySQL 8.0 изменение только комментария — метаданная операция (INSTANT/INPLACE),
 *    таблицы не перестраиваются, блокировок на данные нет;
 *  - генерируемые (GENERATED) столбцы пропускаются;
 *  - down() очищает проставленные комментарии.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->applyComments($this->schema(), clear: false);
    }

    public function down(): void
    {
        $this->applyComments($this->schema(), clear: true);
    }

    /**
     * @param  array<string, array<string, string>>  $schema
     */
    private function applyComments(array $schema, bool $clear): void
    {
        // Только MySQL/MariaDB поддерживают COMMENT в COLUMN/TABLE. На SQLite (тесты) — пропускаем.
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach ($schema as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $defs = $this->columnDefinitions($table);
            $clauses = [];

            foreach ($columns as $column => $comment) {
                if ($column === '__table') {
                    continue;
                }
                if (! isset($defs[$column])) {
                    // Столбца нет или он GENERATED — пропускаем.
                    continue;
                }
                $text = $clear ? '' : $comment;
                $clauses[] = 'MODIFY COLUMN '.$defs[$column]." COMMENT '".$this->esc($text)."'";
            }

            if (array_key_exists('__table', $columns)) {
                $text = $clear ? '' : $columns['__table'];
                $clauses[] = "COMMENT = '".$this->esc($text)."'";
            }

            if ($clauses !== []) {
                DB::statement('ALTER TABLE `'.$table.'` '.implode(', ', $clauses));
            }
        }
    }

    /**
     * Возвращает map [столбец => точный DDL-фрагмент] из SHOW CREATE TABLE,
     * с вырезанным существующим COMMENT. GENERATED-столбцы исключаются.
     *
     * @return array<string, string>
     */
    private function columnDefinitions(string $table): array
    {
        $row = (array) DB::selectOne('SHOW CREATE TABLE `'.$table.'`');
        $ddl = $row['Create Table'] ?? ($row['Create View'] ?? '');

        $defs = [];
        foreach (preg_split('/\r?\n/', (string) $ddl) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '`') {
                continue; // строки ключей/ограничений/заголовка
            }
            if (! preg_match('/^`([^`]+)`\s/', $line, $m)) {
                continue;
            }
            $name = $m[1];

            // Убираем завершающую запятую.
            $def = rtrim($line, ',');

            // GENERATED-столбцы не трогаем.
            if (preg_match('/\bGENERATED\s+ALWAYS\s+AS\b/i', $def)) {
                continue;
            }

            // Вырезаем уже существующий COMMENT '...' в конце определения.
            $def = preg_replace("/\\s+COMMENT\\s+'(?:[^'\\\\]|\\\\.|'')*'\\s*$/i", '', $def);

            $defs[$name] = $def;
        }

        return $defs;
    }

    private function esc(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    /**
     * Словарь комментариев. Ключ '__table' — комментарий таблицы, остальные — столбцы.
     * Столбцы id/created_at/updated_at/deleted_at, не перечисленные явно, комментируются
     * из общего набора ниже (см. слияние в schema()).
     *
     * @return array<string, array<string, string>>
     */
    private function schema(): array
    {
        $generic = [
            'id' => 'Первичный ключ (автоинкремент)',
            'created_at' => 'Дата и время создания записи',
            'updated_at' => 'Дата и время последнего изменения записи',
            'deleted_at' => 'Дата и время мягкого удаления (soft delete); NULL — запись активна',
        ];

        $tables = $this->dictionary();

        // Дополняем каждую таблицу общими комментариями для служебных столбцов,
        // если они не заданы явно.
        foreach ($tables as $table => &$columns) {
            foreach ($generic as $col => $text) {
                if (! array_key_exists($col, $columns)) {
                    $columns[$col] = $text;
                }
            }
        }

        return $tables;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function dictionary(): array
    {
        return [
            'api_tokens' => [
                '__table' => 'API-токены клиентов для доступа к client-api (/api/client-api/{token}) — интеграции клиентов',
                'user_id' => 'Владелец токена (users.id)',
                'name' => 'Человекочитаемое название токена',
                'token' => 'Секретное значение токена для аутентификации запросов',
                'is_active' => 'Активен ли токен (можно отозвать без удаления)',
                'last_used_at' => 'Дата и время последнего использования токена',
            ],

            'articles' => [
                '__table' => 'Статьи блога / полезные материалы',
                'title' => 'Заголовок статьи',
                'slug' => 'ЧПУ-идентификатор для URL',
                'short_description' => 'Краткое описание (анонс)',
                'detailed_description' => 'Полный текст статьи (HTML)',
                'is_published' => 'Опубликована ли статья',
                'published_at' => 'Дата и время публикации',
                'meta_title' => 'SEO: тег title',
                'meta_description' => 'SEO: meta description',
            ],

            'attribute_category' => [
                '__table' => 'Связь атрибут ↔ категория: какие характеристики доступны в категории',
                'attribute_id' => 'Атрибут (attributes.id)',
                'category_id' => 'Категория (categories.id)',
            ],

            'attribute_groups' => [
                '__table' => 'Группы атрибутов для группировки характеристик в интерфейсе',
                'name' => 'Название группы',
                'sort_order' => 'Порядок сортировки',
            ],

            'attribute_values' => [
                '__table' => 'Справочник допустимых значений атрибутов (для атрибутов-справочников)',
                'external_id' => 'Внешний идентификатор (UUID) значения из 1С',
                'attribute_id' => 'Атрибут-владелец значения (attributes.id)',
                'value' => 'Текст значения',
                'value_hash' => 'Хеш значения для быстрого поиска дубликатов',
                'sort_order' => 'Порядок сортировки значения',
            ],

            'attributes' => [
                '__table' => 'Характеристики (атрибуты) товаров — справочник и настройки отображения',
                'external_id' => 'Внешний идентификатор (UUID) атрибута из 1С',
                'name' => 'Название характеристики',
                'slug' => 'ЧПУ-идентификатор атрибута',
                'type' => 'Тип атрибута (string/number/boolean/select/datetime и т.п.)',
                'unit' => 'Единица измерения значения (напр. см, г)',
                'is_filterable' => 'Участвует ли в фильтрах каталога',
                'is_active' => 'Активен ли атрибут',
                'show_on_site' => 'Показывать ли на сайте (в карточке товара)',
                'show_in_export' => 'Включать ли в выгрузки товаров',
                'is_variant_forming' => 'Формирует ли вариацию товара (напр. размер/цвет)',
                'is_partner_only' => 'Виден только партнёрам (B2B)',
                'sort_order' => 'Порядок сортировки',
                'attribute_group_id' => 'Группа атрибута (attribute_groups.id)',
            ],

            'banners' => [
                '__table' => 'Рекламные баннеры',
                'title' => 'Заголовок/название баннера',
                'linkable_type' => 'Полиморфная связь: класс целевой сущности перехода',
                'linkable_id' => 'Полиморфная связь: id целевой сущности перехода',
                'link_url' => 'Прямой URL перехода (если не используется полиморфная связь)',
                'is_active' => 'Активен ли баннер',
                'sort_order' => 'Порядок сортировки',
            ],

            'brand_size_chart' => [
                '__table' => 'Связь бренд ↔ размерная сетка',
                'brand_id' => 'Бренд (brands.id)',
                'size_chart_id' => 'Размерная сетка (size_charts.id)',
            ],

            'brand_stories' => [
                '__table' => 'SEO-лендинги (истории) брендов',
                'title' => 'Заголовок истории',
                'slug' => 'ЧПУ-идентификатор для URL',
                'short_description' => 'Краткое описание (анонс)',
                'detailed_description' => 'Полный текст (HTML)',
                'meta_title' => 'SEO: тег title',
                'meta_description' => 'SEO: meta description',
                'is_published' => 'Опубликована ли история',
                'published_at' => 'Дата и время публикации',
                'brand_id' => 'Бренд (brands.id)',
            ],

            'brands' => [
                '__table' => 'Бренды (производители) товаров',
                'external_id' => 'Внешний идентификатор (UUID) бренда из 1С',
                'name' => 'Название бренда',
                'slug' => 'ЧПУ-идентификатор для URL',
                'short_description' => 'Краткое описание бренда',
                'category' => 'Категория/группа бренда (текстовая метка)',
                'is_featured' => 'Рекомендуемый бренд (вывод в подборках)',
                'meta_title' => 'SEO: тег title',
                'meta_description' => 'SEO: meta description',
                'meta_keywords' => 'SEO: meta keywords',
                'parent_id' => 'Родительский бренд для иерархии (brands.id)',
            ],

            'cache' => [
                '__table' => 'Кэш приложения (драйвер database) — служебная таблица Laravel',
                'key' => 'Ключ кэша',
                'value' => 'Сериализованное значение',
                'expiration' => 'Unix-время истечения',
            ],

            'cache_locks' => [
                '__table' => 'Атомарные блокировки кэша — служебная таблица Laravel',
                'key' => 'Ключ блокировки',
                'owner' => 'Идентификатор владельца блокировки',
                'expiration' => 'Unix-время истечения блокировки',
            ],

            'cart_items' => [
                '__table' => 'Позиции корзин',
                'cart_id' => 'Корзина (carts.id)',
                'product_id' => 'Товар (products.id)',
                'quantity' => 'Количество',
                'price' => 'Цена за единицу на момент добавления',
                'item_type' => "Тип позиции: 'instock' — в наличии, 'preorder' — предзаказ",
                'warehouse_id' => 'Склад позиции (warehouses.id)',
            ],

            'carts' => [
                '__table' => 'Корзины пользователей (поддерживаются несколько именованных корзин на пользователя)',
                'user_id' => 'Владелец корзины (users.id)',
                'name' => 'Название корзины',
                'is_active' => 'Является ли корзина текущей активной',
                'description' => 'Описание/заметка к корзине',
            ],

            'categories' => [
                '__table' => 'Дерево категорий каталога (nested set, kalnoy/nestedset)',
                '_lft' => 'Левая граница узла nested set',
                '_rgt' => 'Правая граница узла nested set',
                'parent_id' => 'Родительская категория (categories.id)',
                'external_id' => 'Внешний идентификатор категории из 1С',
                'uuid' => 'UUID из 1С (US-13)',
                'name' => 'Название категории',
                'is_group' => 'Признак узла-группы из 1С (US-13)',
                'is_active' => 'Флаг активности категории. Неактивные категории и их товары не отображаются на сайте.',
                'sort' => 'Порядок сортировки',
                'slug' => 'ЧПУ-идентификатор для URL',
                'short_description' => 'Краткое описание категории',
                'description' => 'Полное описание категории',
                'meta_title' => 'SEO: тег title',
                'meta_description' => 'SEO: meta description',
                'meta_keywords' => 'SEO: meta keywords',
            ],

            'certificates' => [
                '__table' => 'Сертификаты соответствия товаров',
                'external_id' => 'Внешний идентификатор сертификата из 1С',
                'sex_opt_id' => 'Идентификатор сертификата в системе-источнике sex-opt',
                'name' => 'Название/номер сертификата',
                'type' => 'Тип сертификата (декларация, сертификат соответствия и т.п.)',
                'issued_at' => 'Дата выдачи',
                'expires_at' => 'Дата окончания действия',
            ],

            'client_statuses' => [
                '__table' => 'Статусы (уровни) клиента — грейды по обороту',
                'name' => 'Название статуса',
                'color' => 'HEX цвет статуса, напр. #FFD700',
                'description' => 'Описание статуса',
                'amount_from' => 'Сумма от (порог оборота для получения статуса)',
                'external_id' => 'Внешний идентификатор',
            ],

            'companies' => [
                '__table' => 'Компании (юридические лица) клиентов',
                'user_id' => 'Пользователь-владелец компании (users.id)',
                'country' => 'Страна регистрации',
                'name' => 'Отображаемое название компании',
                'legal_name' => 'Полное юридическое наименование',
                'tax_id' => 'ИНН',
                'registration_number' => 'ОГРН/ОГРНИП (регистрационный номер)',
                'tax_code' => 'КПП',
                'okpo_code' => 'Код ОКПО',
                'legal_address' => 'Юридический адрес (строкой)',
                'legal_address_data' => 'Юридический адрес: структурированные геоданные (JSON)',
                'actual_address' => 'Фактический адрес (строкой)',
                'actual_address_data' => 'Фактический адрес: структурированные геоданные (JSON)',
                'phone' => 'Телефон компании',
                'email' => 'E-mail компании',
                'is_default' => 'Компания по умолчанию у пользователя',
                'erp_id' => 'Идентификатор (UUID) компании в 1С',
            ],

            'company_bank_accounts' => [
                '__table' => 'Банковские реквизиты компаний',
                'company_id' => 'Компания (companies.id)',
                'bank_name' => 'Наименование банка',
                'bank_bik' => 'БИК банка',
                'correspondent_account' => 'Корреспондентский счёт',
                'account_number' => 'Расчётный счёт',
                'is_primary' => 'Основной счёт компании',
            ],

            'contractor_balance_overdue_details' => [
                '__table' => 'Детализация просроченной задолженности контрагента (по реализациям)',
                'contractor_balance_id' => 'Баланс контрагента (contractor_balances.id)',
                'shipment_uuid' => 'UUID реализации из 1С',
                'amount' => 'Сумма просрочки',
                'due_date' => 'Дата оплаты (плановая)',
            ],

            'contractor_balances' => [
                '__table' => 'Балансы контрагентов (взаиморасчёты), синхронизируются из 1С',
                'user_id' => 'Пользователь (users.id)',
                'company_id' => 'Компания (companies.id)',
                'contractor_uuid' => 'UUID контрагента из 1С',
                'tax_id' => 'ИНН контрагента',
                'current_balance' => 'Текущий баланс (RUB)',
                'overdue_debt' => 'Просроченная задолженность (RUB)',
                'balance_erp_updated_at' => 'Дата обновления баланса из 1С',
            ],

            'currencies' => [
                '__table' => 'Валюты и курсы',
                'code' => 'Код валюты (ISO 4217, напр. RUB, USD)',
                'name' => 'Название валюты',
                'symbol' => 'Символ валюты (₽, $, …)',
                'is_base' => 'Базовая валюта системы',
                'official_rate' => 'Официальный курс (ЦБ)',
                'rate_coefficient' => 'Коэффициент к курсу (наценка/поправка)',
                'exchange_rate' => 'Итоговый курс пересчёта',
                'exchange_rate_date' => 'Дата актуальности курса',
            ],

            'delivery_addresses' => [
                '__table' => 'Адреса доставки пользователей',
                'user_id' => 'Владелец адреса (users.id)',
                'name' => 'Название адреса (метка)',
                'address' => 'Адрес строкой',
                'address_data' => 'Структурированные геоданные адреса (JSON)',
            ],

            'erp_bus_messages' => [
                '__table' => 'Журнал сообщений шины обмена 1С ↔ Сайт (ErpBusLogger). Ведётся при ERP_BUS_LOGGING_ENABLED.',
                'direction' => 'Направление сообщения (in — входящее из 1С, out — исходящее в 1С)',
                'routing_key' => 'Очередь / routing key',
                'event' => 'Тип события (partner.created, order.updated, ...)',
                'message_id' => 'message_id из payload',
                'payload' => 'Полный JSON payload',
                'status' => 'Статус обработки сообщения',
                'error_message' => 'Текст ошибки обработки (если была)',
            ],

            'erp_processed_messages' => [
                '__table' => 'Идемпотентность обмена: обработанные входящие message_id (защита от повторной обработки)',
                'message_id' => 'Идентификатор обработанного сообщения',
                'event' => 'Тип события',
                'processed_at' => 'Дата и время обработки',
            ],

            'erp_promotion_product' => [
                '__table' => 'Связь акция 1С ↔ товар',
                'erp_promotion_id' => 'Акция из 1С (erp_promotions.id)',
                'product_id' => 'Товар (products.id)',
            ],

            'erp_promotions' => [
                '__table' => 'Акции, полученные из 1С',
                'uuid' => 'UUID акции в 1С',
                'type' => 'Тип акции',
            ],

            'erp_validation_errors' => [
                '__table' => 'Ошибки валидации payload-ов обмена с 1С (по JSON Schema)',
                'event' => 'Тип события',
                'direction' => 'Направление (in/out)',
                'message_id' => 'Идентификатор сообщения',
                'errors' => 'Список ошибок валидации (JSON)',
                'payload' => 'Исходный payload сообщения (JSON)',
            ],

            'failed_jobs' => [
                '__table' => 'Проваленные задания очередей — служебная таблица Laravel',
                'uuid' => 'UUID задания',
                'connection' => 'Соединение очереди',
                'queue' => 'Имя очереди',
                'payload' => 'Сериализованное задание',
                'exception' => 'Текст исключения',
                'failed_at' => 'Дата и время падения задания',
            ],

            'faqs' => [
                '__table' => 'Часто задаваемые вопросы (FAQ)',
                'title' => 'Вопрос',
                'content' => 'Ответ (HTML)',
                'sort_order' => 'Порядок сортировки',
                'is_published' => 'Опубликован ли вопрос',
            ],

            'favorites' => [
                '__table' => 'Избранные товары пользователей (лайки)',
                'user_id' => 'Пользователь (users.id)',
                'product_id' => 'Товар (products.id)',
            ],

            'health_check_result_history_items' => [
                '__table' => 'История результатов health-проверок (spatie/laravel-health)',
                'check_name' => 'Системное имя проверки',
                'check_label' => 'Отображаемое имя проверки',
                'status' => 'Статус результата (ok/warning/failed/…)',
                'notification_message' => 'Текст уведомления',
                'short_summary' => 'Краткая сводка',
                'meta' => 'Дополнительные данные результата (JSON)',
                'ended_at' => 'Время завершения проверки',
                'batch' => 'Идентификатор пакета запуска',
            ],

            'job_batches' => [
                '__table' => 'Пакеты заданий очередей (job batching) — служебная таблица Laravel',
                'name' => 'Название пакета',
                'total_jobs' => 'Всего заданий в пакете',
                'pending_jobs' => 'Осталось выполнить заданий',
                'failed_jobs' => 'Количество проваленных заданий',
                'failed_job_ids' => 'Список id проваленных заданий (JSON)',
                'options' => 'Опции пакета (сериализовано)',
                'cancelled_at' => 'Unix-время отмены пакета',
                'finished_at' => 'Unix-время завершения пакета',
            ],

            'jobs' => [
                '__table' => 'Очередь заданий (драйвер database) — служебная таблица Laravel',
                'queue' => 'Имя очереди',
                'payload' => 'Сериализованное задание',
                'attempts' => 'Число попыток выполнения',
                'reserved_at' => 'Unix-время резервирования воркером',
                'available_at' => 'Unix-время, с которого задание доступно к выполнению',
            ],

            'kanban_comments' => [
                '__table' => 'Комментарии к задачам канбан-доски',
                'kanban_task_id' => 'Задача (kanban_tasks.id)',
                'parent_id' => 'Родительский комментарий для ответов (kanban_comments.id)',
                'content' => 'Текст комментария',
            ],

            'kanban_task_attachments' => [
                '__table' => 'Вложения к задачам канбан-доски',
                'kanban_task_id' => 'Задача (kanban_tasks.id)',
                'original_name' => 'Исходное имя файла',
                'path' => 'Путь к файлу в хранилище',
                'mime_type' => 'MIME-тип файла',
                'size' => 'Размер файла в байтах',
            ],

            'kanban_tasks' => [
                '__table' => 'Задачи канбан-доски (баг-репорты и обратная связь, в т.ч. из UI сайта)',
                'title' => 'Заголовок задачи',
                'description' => 'Описание задачи',
                'status' => 'Статус/колонка (backlog, todo, in-progress, review, done)',
                'order' => 'Порядок внутри колонки',
                'page_url' => 'URL страницы, с которой создана задача',
                'browser' => 'Информация о браузере автора',
                'user_name' => 'Имя автора',
                'scope' => 'Область/раздел задачи',
                'type' => 'Тип задачи (баг, идея, вопрос и т.п.)',
            ],

            'media' => [
                '__table' => 'Медиафайлы (spatie/laravel-medialibrary) — изображения и файлы сущностей',
                'model_type' => 'Класс модели-владельца (полиморфная связь)',
                'model_id' => 'ID модели-владельца',
                'uuid' => 'UUID медиафайла',
                'collection_name' => 'Имя коллекции (напр. images, documents)',
                'name' => 'Отображаемое имя',
                'file_name' => 'Имя файла на диске',
                'mime_type' => 'MIME-тип',
                'disk' => 'Диск хранения оригинала',
                'conversions_disk' => 'Диск для сгенерированных конверсий',
                'size' => 'Размер файла в байтах',
                'manipulations' => 'Манипуляции над изображением (JSON)',
                'custom_properties' => 'Произвольные свойства (JSON)',
                'generated_conversions' => 'Сгенерированные конверсии (JSON)',
                'responsive_images' => 'Данные responsive-изображений (JSON)',
                'order_column' => 'Порядок сортировки в коллекции',
            ],

            'menu_items' => [
                '__table' => 'Пункты меню (навигация сайта и футер)',
                'title' => 'Название пункта',
                'url' => 'Ссылка',
                'icon' => 'Иконка',
                'badge_text' => 'Текст бейджа',
                'badge_color' => 'Цвет бейджа',
                'location' => 'Расположение меню (header, footer и т.п.)',
                'footer_group' => 'Группа/колонка в футере',
                'sort_order' => 'Порядок сортировки',
                'is_published' => 'Опубликован ли пункт',
                'open_in_new_tab' => 'Открывать в новой вкладке',
            ],

            'migrations' => [
                '__table' => 'Журнал выполненных миграций — служебная таблица Laravel',
                'migration' => 'Имя файла миграции',
                'batch' => 'Номер пакета выполнения',
            ],

            'model_has_permissions' => [
                '__table' => 'Прямые права моделей (spatie/laravel-permission)',
                'permission_id' => 'Право (permissions.id)',
                'model_type' => 'Класс модели',
                'model_id' => 'ID модели',
            ],

            'model_has_roles' => [
                '__table' => 'Роли моделей (spatie/laravel-permission)',
                'role_id' => 'Роль (roles.id)',
                'model_type' => 'Класс модели',
                'model_id' => 'ID модели',
            ],

            'news' => [
                '__table' => 'Новости',
                'title' => 'Заголовок новости',
                'slug' => 'ЧПУ-идентификатор для URL',
                'short_description' => 'Краткое описание (анонс)',
                'detailed_description' => 'Полный текст новости (HTML)',
                'is_published' => 'Опубликована ли новость',
                'published_at' => 'Дата и время публикации',
                'meta_title' => 'SEO: тег title',
                'meta_description' => 'SEO: meta description',
            ],

            'order_change_logs' => [
                '__table' => 'Журнал изменений заказов (аудит правок)',
                'order_id' => 'Заказ (orders.id)',
                'type' => 'Тип изменения',
                'summary' => 'Краткое описание изменения',
                'changes' => 'Детали изменений (JSON: было/стало)',
                'source' => 'Источник изменения (site/admin/erp)',
                'user_id' => 'Пользователь, внёсший изменение (users.id)',
                'old_total' => 'Сумма заказа до изменения',
                'new_total' => 'Сумма заказа после изменения',
            ],

            'order_items' => [
                '__table' => 'Позиции заказов',
                'order_id' => 'Заказ (orders.id)',
                'product_id' => 'Товар (products.id)',
                'name' => 'Название товара на момент заказа (snapshot)',
                'brand_name_snapshot' => 'Имя бренда товара на момент создания строки. Используется для fuzzy-поиска без JOIN.',
                'price' => 'Цена за единицу на момент заказа',
                'base_price' => 'Базовая цена за единицу (до скидки)',
                'discount_percent' => 'Процент скидки на позицию',
                'final_price' => 'Итоговая цена за единицу с учётом скидки',
                'quantity' => 'Количество',
                'subtotal' => 'Сумма по позиции (final_price × quantity)',
            ],

            'order_status_histories' => [
                '__table' => 'История смены статусов заказов',
                'order_id' => 'Заказ (orders.id)',
                'old_status' => 'Прежний статус',
                'new_status' => 'Новый статус',
                'user_id' => 'Пользователь, сменивший статус (users.id)',
                'comment' => 'Комментарий к смене статуса',
            ],

            'orders' => [
                '__table' => 'Заказы',
                'uuid' => 'UUID заказа (используется в обмене с 1С)',
                'number' => 'Локальный номер заказа на сайте',
                'erp_number' => 'Номер заказа в 1С',
                'user_id' => 'Покупатель (users.id)',
                'company_id' => 'Компания-плательщик (companies.id)',
                'delivery_address' => 'Адрес доставки (снимок строкой)',
                'cart_id' => 'Корзина-источник заказа (carts.id)',
                'status' => 'Статус заказа (enum OrderStatus)',
                'comment' => 'Комментарий покупателя',
                'manager_comment' => 'Комментарий менеджера',
                'warehouse_comment' => 'Комментарий склада',
                'total_amount' => 'Итоговая сумма заказа',
                'exchange_rate' => 'Курс валюты на момент заказа',
                'rate_coefficient' => 'Коэффициент к курсу на момент заказа',
                'currency_code' => 'Код валюты заказа',
                'parent_id' => 'Родительский заказ при разбивке (orders.id)',
                'type' => "Тип заказа: 'order' — обычный, 'preorder' — предзаказ (enum OrderType)",
                'erp_created_at' => 'Дата создания заказа в 1С',
                'erp_updated_at' => 'Дата изменения заказа в 1С',
            ],

            'pages' => [
                '__table' => 'Статические страницы (О компании, Контакты, Оферта и т.п.)',
                'title' => 'Заголовок страницы',
                'slug' => 'ЧПУ-идентификатор для URL',
                'content' => 'Содержимое страницы (HTML)',
                'meta_title' => 'SEO: тег title',
                'meta_description' => 'SEO: meta description',
                'is_published' => 'Опубликована ли страница',
            ],

            'password_reset_tokens' => [
                '__table' => 'Токены сброса пароля — служебная таблица Laravel',
                'email' => 'E-mail, для которого запрошен сброс',
                'token' => 'Хеш токена сброса',
                'created_at' => 'Дата и время создания токена',
            ],

            'permissions' => [
                '__table' => 'Права доступа (spatie/laravel-permission)',
                'name' => 'Имя права',
                'guard_name' => 'Guard, к которому относится право',
            ],

            'personal_access_tokens' => [
                '__table' => 'Персональные API-токены (Laravel Sanctum)',
                'tokenable_type' => 'Класс модели-владельца токена (полиморфная связь)',
                'tokenable_id' => 'ID модели-владельца токена',
                'name' => 'Название токена',
                'token' => 'Хеш токена',
                'abilities' => 'Разрешённые действия токена (JSON)',
                'last_used_at' => 'Дата последнего использования',
                'expires_at' => 'Дата истечения токена',
            ],

            'personal_managers' => [
                '__table' => 'Персональные менеджеры (закрепляются за клиентами), синхронизируются из 1С',
                'erp_uuid' => 'UUID менеджера в 1С',
                'name' => 'ФИО менеджера',
                'phone' => 'Телефон менеджера',
                'email' => 'E-mail менеджера',
            ],

            'product_attribute_values' => [
                '__table' => 'Значения атрибутов товаров (EAV): связь товар ↔ атрибут ↔ значение',
                'product_id' => 'Товар (products.id)',
                'attribute_id' => 'Атрибут (attributes.id)',
                'attribute_value_id' => 'Значение из справочника (attribute_values.id), если атрибут-справочник',
                'text_value' => 'Строковое значение (для текстовых атрибутов)',
                'number_value' => 'Числовое значение',
                'boolean_value' => 'Логическое значение',
                'datetime_value' => 'Значение даты/времени',
            ],

            'product_barcodes' => [
                '__table' => 'Дополнительные штрихкоды товаров',
                'product_id' => 'Товар (products.id)',
                'barcode' => 'Штрихкод (EAN/UPC)',
            ],

            'product_certificate' => [
                '__table' => 'Связь товар ↔ сертификат',
                'product_id' => 'Товар (products.id)',
                'certificate_id' => 'Сертификат (certificates.id)',
            ],

            'product_export_runs' => [
                '__table' => 'Запуски генерации выгрузок товаров (история прогонов)',
                'product_export_id' => 'Выгрузка (product_exports.id)',
                'status' => 'Статус прогона (queued/running/success/failed)',
                'started_at' => 'Время начала генерации',
                'finished_at' => 'Время завершения генерации',
                'duration_ms' => 'Длительность генерации, мс',
                'queued_for_ms' => 'Время ожидания в очереди, мс',
                'steps_json' => 'Тайминги/детали этапов генерации (JSON)',
                'rows_count' => 'Количество строк в результате',
                'bytes' => 'Размер результата в байтах',
                'error_message' => 'Текст ошибки (если была)',
                'error_count' => 'Количество ошибок',
            ],

            'product_exports' => [
                '__table' => 'Настроенные выгрузки товаров (фиды/прайсы для клиентов и маркетплейсов)',
                'user_id' => 'Администратор-владелец выгрузки (users.id)',
                'client_user_id' => 'Клиент, которому предназначена выгрузка (users.id)',
                'name' => 'Название выгрузки',
                'hash' => 'Секретный хеш для публичного URL выгрузки',
                'format' => 'Формат файла (csv, xml, json, yml и т.п.)',
                'preset' => 'Preset type key (yml, shopify, woocommerce, etc.) — null for custom exports',
                'filters' => 'Фильтры отбора товаров (JSON)',
                'filters_text' => 'Денормализованные имена брендов/категорий/складов/сертификаторов из filters JSON для LIKE-поиска',
                'fields' => 'Список выгружаемых полей (JSON)',
                'is_active' => 'Активна ли выгрузка',
                'last_downloaded_at' => 'Дата последнего скачивания',
                'cached_at' => 'When the cached export file was last generated',
                'data_version_at' => 'Метка версии данных каталога для инвалидации кэша',
                'estimated_rows' => 'Оценочное число строк выгрузки',
                'status' => 'Текущий статус выгрузки',
                'last_run_id' => 'Последний прогон (product_export_runs.id)',
            ],

            'product_models' => [
                '__table' => 'Модели товаров (объединяют вариации одного товара)',
                'external_id' => 'Внешний идентификатор модели из 1С',
                'code' => 'Код модели',
                'name' => 'Название модели',
            ],

            'product_product_selection' => [
                '__table' => 'Связь товар ↔ подборка',
                'product_id' => 'Товар (products.id)',
                'product_selection_id' => 'Подборка (product_selections.id)',
                'featured' => 'Выделенный товар внутри подборки',
            ],

            'product_promotion' => [
                '__table' => 'Связь товар ↔ акция (сайтовые акции promotions)',
                'product_id' => 'Товар (products.id)',
                'promotion_id' => 'Акция (promotions.id)',
            ],

            'product_selections' => [
                '__table' => 'Кураторские подборки товаров',
                'name' => 'Название подборки',
                'slug' => 'ЧПУ-идентификатор для URL',
                'short_description' => 'Краткое описание',
                'description' => 'Полное описание',
                'meta_title' => 'SEO: тег title',
                'meta_description' => 'SEO: meta description',
                'is_active' => 'Активна ли подборка',
                'show_on_home' => 'Показывать на главной странице',
                'sort_order' => 'Порядок сортировки',
            ],

            'product_warehouse' => [
                '__table' => 'Остатки товаров по складам',
                'product_id' => 'Товар (products.id)',
                'warehouse_id' => 'Склад (warehouses.id)',
                'quantity' => 'Остаток (количество) на складе',
            ],

            'products' => [
                '__table' => 'Товары каталога',
                'name' => 'Название товара',
                'base_price' => 'Базовая цена (без индивидуальных скидок клиента)',
                'external_id' => 'UUID товара в 1С',
                'sex_opt_id' => 'Идентификатор товара в системе-источнике sex-opt (каталог/медиа)',
                'is_new' => 'Признак «Новинка»',
                'is_bestseller' => 'Признак «Хит продаж»',
                'code' => 'Код/артикул товара из 1С',
                'sku' => 'SKU (складская единица)',
                'variant_name' => 'Название вариации (напр. размер/цвет)',
                'slug' => 'ЧПУ-идентификатор для URL',
                'url' => 'Внешний/каноничный URL товара',
                'barcode' => 'Основной штрихкод',
                'tnved' => 'Код ТН ВЭД',
                'weight_gross' => 'Вес брутто, кг',
                'weight_net' => 'Вес нетто, кг',
                'width' => 'Ширина, см',
                'height' => 'Высота, см',
                'depth' => 'Глубина/длина, см',
                'hs_code' => 'Код HS/ТН ВЭД для экспортных выгрузок',
                'abc_xyz' => 'Класс ABC/XYZ (аналитика оборачиваемости)',
                'turnover' => 'Показатель оборачиваемости',
                'is_marked' => 'Подлежит обязательной маркировке («Честный знак»)',
                'is_liquidation' => 'Товар в ликвидации/распродаже',
                'for_marketplaces' => 'Разрешён к выгрузке на маркетплейсы',
                'description' => 'Описание товара (текст)',
                'description_html' => 'Описание товара (HTML)',
                'rich_content' => 'Расширенный контент карточки (JSON, редактор блоков)',
                'rich_content_generated_at' => 'Когда сгенерирован rich-контент (ИИ)',
                'rich_content_generation_failed_at' => 'Когда последняя генерация rich-контента завершилась ошибкой',
                'rich_content_generation_attempts' => 'Число попыток генерации rich-контента',
                'short_description' => 'Краткое описание',
                'meta_title' => 'SEO: тег title',
                'meta_description' => 'SEO: meta description',
                'meta_keywords' => 'SEO: meta keywords',
                'category_id' => 'Категория (categories.id)',
                'brand_id' => 'Бренд (brands.id)',
                'model_id' => 'Модель товара (product_models.id)',
                'hidden' => 'v10: Скрыть в интернете — товар не отображается на сайте',
                'size_chart_id' => 'Размерная сетка (size_charts.id)',
                'erp_created_at' => 'Дата создания товара в 1С',
                'erp_updated_at' => 'Дата изменения товара в 1С',
            ],

            'promotions' => [
                '__table' => 'Акции сайта (промо-лендинги)',
                'name' => 'Название акции',
                'slug' => 'ЧПУ-идентификатор для URL',
                'meta_title' => 'SEO: тег title',
                'meta_description' => 'SEO: meta description',
                'description' => 'Описание акции (HTML)',
            ],

            'pulse_aggregates' => [
                '__table' => 'Агрегаты метрик Laravel Pulse — служебная таблица мониторинга',
                'bucket' => 'Временной бакет (unix-время)',
                'period' => 'Период агрегации, сек',
                'type' => 'Тип метрики',
                'key' => 'Ключ метрики',
                'aggregate' => 'Тип агрегата (count/max/avg/sum)',
                'value' => 'Значение агрегата',
                'count' => 'Число наблюдений',
            ],

            'pulse_entries' => [
                '__table' => 'Записи метрик Laravel Pulse — служебная таблица мониторинга',
                'timestamp' => 'Unix-время записи',
                'type' => 'Тип метрики',
                'key' => 'Ключ метрики',
                'value' => 'Значение',
            ],

            'pulse_values' => [
                '__table' => 'Текущие значения метрик Laravel Pulse — служебная таблица мониторинга',
                'timestamp' => 'Unix-время значения',
                'type' => 'Тип метрики',
                'key' => 'Ключ метрики',
                'value' => 'Значение',
            ],

            'region_warehouse' => [
                '__table' => 'Связь регион ↔ склад с типом привязки',
                'region_id' => 'Регион (regions.id)',
                'warehouse_id' => 'Склад (warehouses.id)',
                'type' => "Тип привязки склада к региону: 'primary' — основной (в наличии), 'preorder' — предзаказный",
            ],

            'regionables' => [
                '__table' => 'Полиморфная привязка сущностей к регионам',
                'region_id' => 'Регион (regions.id)',
                'regionable_type' => 'Класс привязанной сущности',
                'regionable_id' => 'ID привязанной сущности',
            ],

            'regions' => [
                '__table' => 'Регионы (с привязкой валюты)',
                'name' => 'Название региона',
                'currency_id' => 'Валюта региона (currencies.id)',
            ],

            'return_items' => [
                '__table' => 'Позиции возвратов',
                'return_id' => 'Возврат (returns.id)',
                'shipment_item_id' => 'Строка реализации, по которой возврат (shipment_items.id)',
                'shipment_id' => 'Реализация (shipments.id)',
                'product_id' => 'Товар (products.id)',
                'product_name_snapshot' => 'Имя товара на момент создания возврата. Сохраняется при удалении товара из каталога.',
                'brand_name_snapshot' => 'Имя бренда товара на момент создания возврата.',
                'quantity' => 'Количество к возврату',
                'reason' => 'Причина возврата (код/тип)',
                'reason_comment' => 'Комментарий к причине возврата',
                'price' => 'Цена за единицу',
                'subtotal' => 'Сумма по позиции',
            ],

            'returns' => [
                '__table' => 'Возвраты товаров',
                'uuid' => 'UUID возврата (обмен с 1С)',
                'erp_number' => 'Номер возврата в 1С',
                'user_id' => 'Пользователь-инициатор (users.id)',
                'status' => 'Статус возврата',
                'comment' => 'Комментарий клиента',
                'admin_comment' => 'Комментарий администратора',
                'total_amount' => 'Итоговая сумма возврата',
            ],

            'role_has_permissions' => [
                '__table' => 'Права ролей (spatie/laravel-permission)',
                'permission_id' => 'Право (permissions.id)',
                'role_id' => 'Роль (roles.id)',
            ],

            'roles' => [
                '__table' => 'Роли пользователей (spatie/laravel-permission)',
                'name' => 'Имя роли',
                'guard_name' => 'Guard, к которому относится роль',
            ],

            'search_histories' => [
                '__table' => 'История поисковых запросов пользователей',
                'user_id' => 'Пользователь (users.id), NULL — гость',
                'query' => 'Текст поискового запроса',
                'results_count' => 'Количество найденных результатов',
                'ip_address' => 'IP-адрес запроса',
            ],

            'sessions' => [
                '__table' => 'Сессии пользователей (драйвер database) — служебная таблица Laravel',
                'user_id' => 'Пользователь сессии (users.id), NULL — гость',
                'ip_address' => 'IP-адрес',
                'user_agent' => 'User-Agent браузера',
                'payload' => 'Сериализованные данные сессии',
                'last_activity' => 'Unix-время последней активности',
            ],

            'settings' => [
                '__table' => 'Настройки приложения (хранилище ключ-значение)',
                'key' => 'Ключ настройки',
                'value' => 'Значение настройки',
                'type' => 'Тип значения (string/bool/int/json и т.п.)',
                'group' => 'Группа настроек',
                'description' => 'Описание назначения настройки',
            ],

            'shipment_items' => [
                '__table' => 'Позиции реализаций (отгрузок)',
                'shipment_id' => 'Реализация (shipments.id)',
                'product_id' => 'Товар (products.id)',
                'product_name_snapshot' => 'Имя товара на момент создания строки реализации.',
                'brand_name_snapshot' => 'Имя бренда товара на момент создания строки реализации.',
                'order_uuid' => 'UUID заказа-источника позиции',
                'quantity' => 'Количество',
                'price' => 'Цена за единицу',
                'auto_discount_percent' => 'Автоматическая скидка, %',
                'manual_discount_percent' => 'Ручная скидка, %',
                'total' => 'Сумма по позиции без учёта скидок',
                'subtotal' => 'Итоговая сумма по позиции с учётом скидок',
                'vat_rate' => 'Ставка НДС, %',
            ],

            'shipments' => [
                '__table' => 'Реализации (отгрузки) из 1С',
                'uuid' => 'UUID реализации в 1С',
                'number' => 'Локальный или комбинированный номер (если нужен)',
                'erp_number' => 'Номер реализации в 1С',
                'user_id' => 'Клиент (users.id)',
                'company_id' => 'Компания-плательщик (companies.id)',
                'tax_id' => 'ИНН контрагента',
                'date' => 'Дата реализации',
                'status' => 'Статус реализации',
                'currency_code' => 'Код валюты',
                'total_amount' => 'Итоговая сумма реализации',
                'erp_created_at' => 'Дата создания реализации в 1С',
                'erp_updated_at' => 'Дата изменения реализации в 1С',
            ],

            'size_charts' => [
                '__table' => 'Размерные сетки',
                'uuid' => 'UUID размерной сетки (обмен с 1С)',
                'name' => 'Название сетки',
                'values' => 'Данные размерной сетки (JSON)',
            ],

            'social_accounts' => [
                '__table' => 'Привязанные аккаунты соцсетей (OAuth, Laravel Socialite)',
                'user_id' => 'Пользователь (users.id)',
                'provider' => 'Провайдер (google, vk, yandex и т.п.)',
                'provider_id' => 'Идентификатор пользователя у провайдера',
                'provider_token' => 'Access-токен провайдера',
                'provider_refresh_token' => 'Refresh-токен провайдера',
                'provider_avatar' => 'URL аватара из соцсети',
                'provider_name' => 'Имя пользователя из соцсети',
                'provider_email' => 'E-mail из соцсети',
            ],

            'stories' => [
                '__table' => 'Сторис (истории) на витрине',
                'name' => 'Название истории',
                'slug' => 'ЧПУ-идентификатор',
                'is_active' => 'Активна ли история',
                'is_published' => 'Опубликована ли история',
                'show_name' => 'Показывать ли подпись (название) истории',
                'sort_order' => 'Порядок сортировки',
            ],

            'story_slides' => [
                '__table' => 'Слайды сторис',
                'story_id' => 'История (stories.id)',
                'title' => 'Заголовок слайда',
                'content' => 'Содержимое слайда',
                'button_text' => 'Текст кнопки',
                'button_url' => 'Ссылка кнопки',
                'linkable_type' => 'Полиморфная связь: класс целевой сущности',
                'linkable_id' => 'Полиморфная связь: id целевой сущности',
                'duration' => 'Длительность показа слайда, сек',
                'sort_order' => 'Порядок сортировки',
            ],

            'taggables' => [
                '__table' => 'Полиморфная привязка тегов к сущностям (spatie/laravel-tags)',
                'tag_id' => 'Тег (tags.id)',
                'taggable_type' => 'Класс помеченной сущности',
                'taggable_id' => 'ID помеченной сущности',
            ],

            'tags' => [
                '__table' => 'Теги (spatie/laravel-tags)',
                'name' => 'Название тега (JSON с переводами)',
                'slug' => 'ЧПУ-идентификатор тега (JSON с переводами)',
                'type' => 'Тип/группа тега',
                'order_column' => 'Порядок сортировки',
            ],

            'telescope_entries' => [
                '__table' => 'Записи Laravel Telescope (dev-мониторинг запросов/событий)',
                'sequence' => 'Порядковый номер записи',
                'uuid' => 'UUID записи',
                'batch_id' => 'UUID пакета запроса',
                'family_hash' => 'Хеш для группировки однотипных записей',
                'should_display_on_index' => 'Показывать ли в списке',
                'type' => 'Тип записи (request, query, job и т.п.)',
                'content' => 'Данные записи (JSON)',
            ],

            'telescope_entries_tags' => [
                '__table' => 'Теги записей Laravel Telescope',
                'entry_uuid' => 'Запись (telescope_entries.uuid)',
                'tag' => 'Тег',
            ],

            'telescope_monitoring' => [
                '__table' => 'Отслеживаемые теги Laravel Telescope',
                'tag' => 'Тег под мониторингом',
            ],

            'user_questionnaires' => [
                '__table' => 'Анкеты пользователей (B2B-онбординг)',
                'user_id' => 'Пользователь (users.id)',
                'business_type' => 'Тип бизнеса',
                'business_name' => 'Название бизнеса',
                'website_url' => 'Сайт компании',
                'years_in_business' => 'Лет на рынке',
                'monthly_order_volume' => 'Ожидаемый месячный объём заказов',
                'has_physical_store' => 'Есть ли розничная точка',
                'store_count' => 'Число торговых точек',
                'product_categories' => 'Интересующие категории товаров (JSON)',
                'how_found_us' => 'Как узнали о компании',
                'additional_info' => 'Дополнительная информация',
                'completed_at' => 'Дата заполнения анкеты',
            ],

            'user_questions' => [
                '__table' => 'Вопросы пользователей (форма обратной связи)',
                'user_id' => 'Автор вопроса (users.id), NULL — гость',
                'name' => 'Имя автора',
                'email' => 'E-mail автора',
                'subject' => 'Тема вопроса',
                'body' => 'Текст вопроса',
                'status' => 'Статус обработки (new/answered/rejected)',
                'answer' => 'Текст ответа',
                'answered_at' => 'Дата ответа',
                'answered_by_user_id' => 'Кто ответил (users.id)',
                'rejected_reason' => 'Причина отклонения',
                'ip' => 'IP-адрес отправителя',
                'user_agent' => 'User-Agent отправителя',
            ],

            'user_search_presets' => [
                '__table' => 'Сохранённые пресеты фильтров/поиска пользователя',
                'user_id' => 'Пользователь (users.id)',
                'section' => 'Раздел, для которого сохранён пресет',
                'name' => 'Название пресета',
                'filters' => 'Параметры фильтров (JSON)',
            ],

            'users' => [
                '__table' => 'Пользователи (клиенты B2B и сотрудники)',
                'name' => 'Имя пользователя',
                'email' => 'E-mail (логин)',
                'email_verified_at' => 'Дата подтверждения e-mail',
                'password' => 'Хеш пароля',
                'must_change_password' => 'Требуется смена пароля при следующем входе',
                'remember_token' => 'Токен «Запомнить меня»',
                'phone' => 'Телефон',
                'country' => 'Страна',
                'city' => 'Город',
                'is_subscribed' => 'Подписан на рассылку',
                'terms_accepted' => 'Принял условия/оферту',
                'comment' => 'Служебный комментарий к пользователю',
                'status' => 'Статус пользователя (активен/заблокирован и т.п.)',
                'erp_id' => 'UUID контрагента в 1С',
                'view_token' => 'Токен предпросмотра карточки клиента (маршрут user.preview)',
                'region_id' => 'Регион пользователя (regions.id)',
                'currency_id' => 'Валюта пользователя (currencies.id)',
                'client_status_id' => 'Статус клиента / грейд (client_statuses.id)',
                'personal_manager_id' => 'Персональный менеджер (personal_managers.id)',
            ],

            'warehouses' => [
                '__table' => 'Склады',
                'name' => 'Название склада',
                'external_id' => 'UUID склада в 1С',
            ],

            'wishlist_items' => [
                '__table' => 'Список желаний пользователей',
                'user_id' => 'Пользователь (users.id)',
                'product_id' => 'Товар (products.id)',
            ],
        ];
    }
};
