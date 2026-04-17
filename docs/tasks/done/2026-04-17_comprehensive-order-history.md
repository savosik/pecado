# Расширенная история изменений заказа (Backend + UI)

**Приоритет:** высокий
**Исполнитель:** Claude
**Создано:** 2026-04-15
**Выполнено:** 2026-04-17

## Описание

В данный момент история заказа в основном отображает смену статусов. История изменений состава заказа (табличной части), сумм, скидок и других атрибутов логируется только при обновлении из ERP.

Необходимо реализовать полноценное логирование всех значимых изменений заказа (как из ERP, так и из админ-панели) и отобразить эту историю прозрачно для пользователя (в личном кабинете) и для администратора.

## Критерии готовности

- [x] **Backend (логирование):**
    - Доработать логику сохранения изменений в `OrderChangeLog` при редактировании заказа в админ-панели.
    - Логировать изменение атрибутов: Компания, Адрес доставки, Комментарий, Суммы, Коэффициенты.
    - Логировать изменения в составе (OrderItem): добавление, удаление, изменение количества или цены.
- [x] **UI Админ-панель:**
    - На странице просмотра заказа в админке добавить блок «История изменений» (аналогичный тому, что есть в ЛК, но, возможно, с более детальной технической информацией).
- [x] **UI Личный кабинет:**
    - Убедиться, что все новые типы логов корректно отображаются в Timeline на странице `resources/js/Pages/User/Cabinet/Orders/Show.jsx`.
    - Сделать отображение максимально понятным для клиента («Цена изменена с 100 на 120», «Добавлена позиция А» и т.д.).
- [x] **Прозрачность:** Каждая запись должна содержать источник (ERP, Админ, Система) и, по возможности, ФИО ответственного, если это было ручное изменение.

## Реализация

**Backend:**
- `app/Services/Order/OrderChangeLogger.php` — новый сервис: снимки атрибутов и состава, diff, summary, запись лога.
- `database/migrations/2026_04_17_120000_add_user_id_to_order_change_logs_table.php` — колонка `user_id` в `order_change_logs`.
- `app/Models/OrderChangeLog.php` — добавлена связь `user()` и `user_id` в `fillable`.
- `app/Services/Erp/Handlers/HandleOrderUpdated.php` — рефакторинг на использование `OrderChangeLogger`; дополнительно логирует атрибутные изменения (адрес, комментарий).
- `app/Http/Controllers/Admin/OrderController.php` — метод `update` делает снимок до/после и пишет логи с `source='admin'` и `user_id=auth()->id()`; метод `show` отдаёт `change_logs` с `user_name`.
- `app/Http/Controllers/User/OrderController.php` — `change_logs` теперь содержит `user_name`, связь `changeLogs.user` прогружается.

**Новый тип лога:** `attributes_updated` — структура `changes = ['attributes' => {field => {label, old, new, old_label?, new_label?}}]`.

**UI:**
- `resources/js/Admin/Pages/Orders/Components/OrderHistoryTimeline.jsx` — новый компонент единого таймлайна (статусы + изменения атрибутов/состава).
- `resources/js/Admin/Pages/Orders/Show.jsx` — блок `StatusHistoryTimeline` заменён на `OrderHistoryTimeline`.
- `resources/js/Pages/User/Cabinet/Orders/Show.jsx` — добавлены `SourceBadge` (1С/Админ/Система + ФИО), `AttributesChangedEntry`, расширен индикатор таймлайна для `attributes_updated`.

**Тесты:**
- `tests/Unit/Services/Order/OrderChangeLoggerTest.php` — 6 тестов на diff/summary/source/user.
- `tests/Feature/AdminOrderChangeLogTest.php` — 4 теста: запись лога атрибутов, запись лога состава, отсутствие лога без изменений, отображение в Inertia props.
- Обновлён `tests/Unit/Services/Erp/Handlers/HandleOrderUpdatedTest.php` — теперь использует `app(HandleOrderUpdated::class)` для DI.
