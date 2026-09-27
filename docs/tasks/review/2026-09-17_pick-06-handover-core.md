# pick-06 · Таблицы выдачи, HandoverService, права склада

**Приоритет:** высокий
**Создано:** 2026-09-17
**Эпик:** [pick-00](../in-progress/2026-09-17_pick-00-epic.md)
**Зависимости:** —
**Волна:** 1

## Описание

В 1С статуса «выдан» нет и не будет. Выдача — документ сайта вне шины, по образцу
`DeliveryShipment` (`created_by`, история, 1С о нём не знает).

- Миграции (новые, с комментариями таблиц и столбцов на русском):
  - `pickup_handovers`: `goods_issue_id`, `pass_id` (nullable), `issued_by`, `issued_at`,
    `method` (`qr` | `code` | `barcode` | `manual` | `backfill`), `recipient_name`,
    `packages_count`, `comment`, `cancelled_at`, `cancelled_by`, `cancel_reason`,
    `needs_review`, `review_note`; генерируемая `active_key` (= `goods_issue_id`, пока
    `cancelled_at` пуст) с уникальным индексом — защита от двойной выдачи на уровне БД.
  - `pickup_scan_misses`: `raw`, `symbology`, `user_id`, `context`, `created_at`.
- `app/Models/Pickup/{PickupHandover,PickupScanMiss}.php`; связь `GoodsIssue::handover()`.
- `app/Services/Pickup/HandoverService.php`:
  - `issue(GoodsIssue, User $by, method, ?pass, …)` — транзакция + `lockForUpdate` на РО;
    повтор отвечает `HandoverException::alreadyIssued` с текстом «уже выдал Иванов в 14:02»;
    выдавать можно только `shipped`, иначе «ордер ещё собирается / отменён».
  - `cancel(handover, User $by, reason)` — окно `pickup.cancel_window_hours` (24), причина обязательна.
  - `closeWithoutHandover(GoodsIssue, by)` — метод `backfill` для хвостов до запуска.
  - Слушатель смены статуса РО: откат из `shipped` при существующей выдаче → `needs_review = true`
    (выдачу не удаляем).
- Права `wms-pickups.view / issue / cancel` — **три реестра**: `RolesAndPermissionsSeeder`
  (`$resources`, `$resourceLabels`, роли: `warehouse-head` всё, `storekeeper` без `cancel`),
  `Admin\RoleController` (группа «Склад (WMS)»), пункт меню. Страж — `PermissionNamingTest`.
- После миграций: `db:comments:audit`, `bi:sync-grants`.

## Ход работ

- **17.09.2026** — миграция `2026_09_17_100000_create_pickup_tables` (четыре таблицы `pickup_*`, комментарии на русском,
  генерируемая `active_key` с уникальным индексом), модели `App\Models\Pickup\*`, `HandoverService` (`issue`, `cancel`,
  `closeWithoutHandover`, `flagRollback`, `resolveReview`), `HandoverException` с кодами, события `GoodsIssueHandedOver` и
  `GoodsIssueReadyChanged`, наблюдатель `PickupGoodsIssueStatusObserver` (слушает журнал статусов РО, код ERP не тронут).
  Права `wms-pickups.view/issue/cancel` в трёх реестрах: начальнику склада всё, кладовщику без `cancel`.
  Тесты `HandoverServiceTest` (8), `PermissionNamingTest` зелёный.
- Проверено на локальном MySQL: миграция проходит, вторая активная выдача отбивается ошибкой 1062, после отмены выдать
  снова можно; `db:comments:audit` по таблицам `pickup_*` без пробелов.
- После выкладки: `bi:sync-grants` (таблицы `pickup_*` — решить, открывать ли их BI-агенту).

## Критерии готовности

- [x] Тесты: выдача, повторная выдача, гонка двух запросов, выдача не-`shipped`, отмена в окне и
      вне окна, отмена без причины, откат выданного РО → `needs_review`.
- [x] `PermissionNamingTest` зелёный.
- [x] `db:comments:audit --strict` без пробелов по новым таблицам.
