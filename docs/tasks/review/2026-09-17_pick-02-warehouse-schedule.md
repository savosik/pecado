# pick-02 · Расписание склада — WarehouseSchedule

**Приоритет:** высокий
**Создано:** 2026-09-17
**Эпик:** [pick-00](../in-progress/2026-09-17_pick-00-epic.md)
**Зависимости:** —
**Волна:** 1

## Описание

Часов работы склада в коде нет вообще; единственный след — константа 17:00 по будням в сводке
недоборов. График становится публичным обещанием, значит, у него должен быть один источник.

- `config/warehouse.php`: `timezone` (Europe/Moscow), `week` (пн–сб 09:00–21:00, вс выходной),
  `cutoff_minutes_before_close` = 60, `sla_minutes` = 40, `pickup_address`, `pickup_how_to_find`,
  `closed_dates` и `special_hours` для ручных исключений. Праздники — из
  `config/production_calendar.php`, но **суббота для склада рабочая** (календарь офисный,
  поэтому берём из него только праздничные даты, не выходные).
- `app/Services/Warehouse/WarehouseSchedule.php`:
  `isOpen(at)`, `closesAt(date)`, `cutoffAt(date)`, `nextOpening(at)`,
  `promisedReadyAt(at)` — «отправлен до отсечки → at + SLA, но не раньше открытия; после —
  следующее открытие + SLA», `pickupDeadline(at)`, `addWorkingDays(at, n)` (для срока пропуска),
  `describe(at)` — готовая русская фраза: «Соберём к 14:40», «Склад откроется в пн в 9:00,
  соберём к 9:40».
- Сервис чистый, без БД; время — через `CarbonImmutable`, «сейчас» приходит аргументом.

## Критерии готовности

- [x] Тесты: будний день до и после 20:00, суббота, воскресенье, праздник, канун праздника,
      ровно 20:00 и 21:00, переход через выходной. Даты в тестах — от `today()`, без календарных бомб.
- [x] Отсечка и SLA меняются конфигом без правки кода.

## Ход работ

- **17.09.2026** — `config/warehouse.php`, `config/pickup.php` (рубильник и настройки эпика),
  `App\Services\Warehouse\WarehouseSchedule`: `hoursFor`, `isOpen`, `cutoffAt`, `nextOpening`,
  `pickingStartsAt`, `promisedReadyAt`, `endOfWorkingDay` (срок пропуска), `describe` (фраза клиенту),
  `today` (сводка для интерфейса), `weekText`. Праздники — из производственного календаря, суббота
  рабочая; исключения — `closed_dates`, `open_dates`, `special_hours`.
  Тесты `tests/Unit/Warehouse/WarehouseScheduleTest.php` — 10 тестов, 39 проверок.
