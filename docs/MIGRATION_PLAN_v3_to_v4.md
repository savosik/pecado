# План перехода с ACCEPTANCE_CRITERIA v3 → v4

> **Дата:** 21 марта 2026
> **Контекст:** По итогам анализа текущей кодовой базы и различий между `ACCEPTANCE_CRITERIA_v3.md` и `ACCEPTANCE_CRITERIA_v4.md`

---

## Сводка изменений v4

| Область | Что изменилось | Затронутые US |
|---|---|---|
| Партнёры (выгрузка из 1С) | Новый входящий поток `partner.created` с паролем | US-01 |
| Контрагенты (выгрузка из 1С) | Новое событие `contractor.created` | US-06 |
| Каталог: `brand` | Из строки → объект `{uuid, name}` | US-13 |
| Каталог: `attributes` в `product.updated` | Из полной замены → мерж по `property_uuid` | US-13 |
| Первоначальная выгрузка | Новый раздел Initial Data Load | Все |
| Настройки обмена | `Константы.НастройкиОбменаPecado` | Сторона 1С |

---

## 1. US-01: Входящий `partner.created` (1С → Сайт)

### Текущее состояние
- `HandlePartnerCreated` существует, но только **активирует** существующего пользователя (ищет по login/email → ставит `erp_id` и статус `ACTIVE`)
- В `ErpIncomingJob::EVENT_HANDLERS` нет записи `'partner.created'` для входящих
- В таблице `users` нет поля `must_change_password`

### Что нужно сделать

#### 1.1 Миграция БД
- [ ] Добавить поле `must_change_password` (boolean, default `false`) в миграцию таблицы `users`

#### 1.2 Модель User
- [ ] Добавить `must_change_password` в `$fillable` модели `User`
- [ ] Добавить `must_change_password` в `$casts` (boolean)

#### 1.3 Обработчик `HandlePartnerCreated`
- [ ] Переписать `HandlePartnerCreated`, чтобы он обрабатывал **два сценария**:
  - **Пользователь существует** (найден по email) → обновляет `erp_id`, статус `ACTIVE` (текущая логика)
  - **Пользователь НЕ существует** → создаёт нового пользователя:
    - `email` = `login` из payload
    - `name` = `name` из payload
    - `phone` = `phone` из payload
    - `password` = `Hash::make($payload['password'])` — пароль из 1С
    - `erp_id` = `uuid` из payload
    - `status` = `ACTIVE`
    - `must_change_password` = `true`
- [ ] Валидация: пропускать сообщения без `email`/`login` (партнёры без email не выгружаются)

#### 1.4 Роутинг событий
- [ ] Добавить `'partner.created' => HandlePartnerCreated::class` в `ErpIncomingJob::EVENT_HANDLERS`
- [ ] ⚠️ **ВАЖНО:** Убедиться, что входящий `partner.created` (из `erp_in.partners`) не конфликтует с исходящим `partner.created` (из `erp_out.partners`). Это разные очереди — конфликта нет, но проверить, что `HandlePartnerCreated` правильно обрабатывает оба контекста.

#### 1.5 Middleware принудительной смены пароля
- [ ] Создать middleware `EnsurePasswordChanged` (или аналог), который:
  - Проверяет `auth()->user()->must_change_password`
  - Если `true` — редиректит на страницу смены пароля
- [ ] Применить middleware к защищённым маршрутам (кабинет, заказы и т.п.)
- [ ] Создать (или обновить) страницу смены пароля
- [ ] После успешной смены пароля сбрасывать `must_change_password = false`

#### 1.6 Шаблон RMQ
- [ ] Создать файл `docs/rmq-templates/erp-to-site/partner.created.json`

#### 1.7 Тесты
- [ ] Тест: `partner.created` с полем `password` → создаёт нового пользователя
- [ ] Тест: `partner.created` для существующего email → обновляет `erp_id`, активирует
- [ ] Тест: `partner.created` без email → пропускается
- [ ] Тест: middleware блокирует доступ при `must_change_password = true`
- [ ] Тест: после смены пароля `must_change_password` сбрасывается

---

## 2. US-06: Входящий `contractor.created` (1С → Сайт)

### Текущее состояние
- Контрагенты создаются только через UI кабинета (модель `Company`)
- В RabbitMQ нет routing key `contractor.*`
- Нет обработчика `HandleContractorCreated`

### Что нужно сделать

#### 2.1 RabbitMQ-топология
- [ ] Добавить routing key `'contractor.*'` в очередь `erp_in.partners` в `SetupRabbitMQTopology::INCOMING_QUEUES`
- [ ] Перезапустить `php artisan rabbitmq:setup` для применения

#### 2.2 Обработчик `HandleContractorCreated`
- [ ] Создать `App\Services\Erp\Handlers\HandleContractorCreated`
- [ ] Логика:
  - Найти пользователя по `partner_uuid` (`User::where('erp_id', $partnerUuid)`)
  - Если пользователь не найден — логировать warning, но **не** пропускать (создать контрагента без привязки к пользователю, или пропустить — решить)
  - `Company::updateOrCreate` по UUID (`external_id`):
    - `user_id` = найденный пользователь
    - `country`, `name`, `legal_name`, `tax_id`, `registration_number`, `legal_address`, `actual_address`, `phone`, `email` — из payload
- [ ] Идемпотентность: повторная обработка обновляет, а не дублирует

#### 2.3 Роутинг событий
- [ ] Добавить `'contractor.created' => HandleContractorCreated::class` в `ErpIncomingJob::EVENT_HANDLERS`

#### 2.4 Модель Company
- [ ] Убедиться, что в модели `Company` есть поле `external_id` (uuid контрагента из 1С)
- [ ] Если нет — добавить `external_id` в миграцию таблицы `companies`
- [ ] Добавить `external_id` в `$fillable`

#### 2.5 Шаблон RMQ
- [ ] Создать файл `docs/rmq-templates/erp-to-site/contractor.created.json`

#### 2.6 Критерии приёмки (обновление)
- [ ] Обновить критерий US-06: убрать «~150 партнёров предзаполняются вручную», заменить на автоматическую выгрузку

#### 2.7 Тесты
- [ ] Тест: `contractor.created` → создаёт компанию с привязкой к пользователю
- [ ] Тест: `contractor.created` с неизвестным `partner_uuid` → корректная обработка
- [ ] Тест: повторный `contractor.created` → обновление, не дублирование
- [ ] Тест: все поля контрагента корректно маппятся

---

## 3. US-13: `brand` → объект `{uuid, name}`

### Текущее состояние
- `HandleProductCreated` читает `$payload['brand']` как **строку** → ищет/создаёт бренд по имени
- `HandleProductUpdated` — аналогично, по строке
- Модель `Brand` имеет поле `external_id` (uuid), но оно **не используется** в обработчиках
- В миграции `brands` поле `external_id` уже есть (nullable uuid)

### Что нужно сделать

#### 3.1 `HandleProductCreated`
- [ ] Изменить парсинг `brand`:
  ```php
  // Было (v3):
  $brandName = $payload['brand'] ?? null; // строка

  // Стало (v4):
  $brandData = $payload['brand'] ?? null; // объект {uuid, name} | null
  ```
- [ ] Обновить логику поиска/создания бренда:
  - Если `$brandData` — это объект (массив с `uuid` и `name`):
    - `Brand::updateOrCreate(['external_id' => $brandData['uuid']], ['name' => $brandData['name'], 'slug' => ...])`
  - Если `$brandData` — строка (обратная совместимость): обрабатывать как раньше
  - Если `null` → `brand_id = null`

#### 3.2 `HandleProductUpdated`
- [ ] Аналогичные изменения: парсинг `brand` как объекта `{uuid, name}`
- [ ] Обновить поиск/создание бренда по `external_id` (uuid)

#### 3.3 Тесты
- [ ] Тест: `product.created` с `brand: {uuid, name}` → создаёт бренд с `external_id`
- [ ] Тест: `product.created` с `brand: null` → `brand_id = null`
- [ ] Тест: `product.updated` с `brand: {uuid, name}` → обновляет привязку
- [ ] Тест: повторный `product.created` с тем же `brand.uuid` → не дублирует бренд

---

## 4. US-13: Мерж атрибутов в `product.updated` (вместо полной замены)

### Текущее состояние
- `HandleProductUpdated` при наличии `attributes` делает `$product->attributeValues()->delete()` → потом создаёт заново (**полная замена**)
- v4 требует **мерж по `property_uuid`**: обновляет существующие, добавляет новые, не трогает остальные

### Что нужно сделать

#### 4.1 `HandleProductUpdated`
- [ ] Убрать строку `$product->attributeValues()->delete()` из блока обработки атрибутов
- [ ] Заменить на логику мержа:
  ```php
  // Для каждого атрибута из payload:
  // 1. Найти или создать Attribute по external_id (property_uuid)
  // 2. Найти или создать AttributeValue по external_id (value_uuid)  
  // 3. ProductAttributeValue::updateOrCreate по [product_id, attribute_id]
  //    (это уже делается — нужно просто убрать delete() перед циклом)
  ```
- [ ] Фактически: **просто удалить строку `$product->attributeValues()->delete()`** — остальная логика `updateOrCreate` уже обеспечивает мерж

#### 4.2 Тесты
- [ ] Тест: `product.updated` с одним атрибутом → не удаляет другие существующие атрибуты
- [ ] Тест: `product.updated` с обновлённым значением атрибута → значение обновлено
- [ ] Тест: `product.updated` с новым атрибутом → добавлен к существующим
- [ ] Тест: `product.updated` без поля `attributes` → атрибуты не затронуты

---

## 5. Первоначальная выгрузка (Initial Data Load) — на стороне 1С

> [!NOTE]
> Этот раздел описывает **работу на стороне 1С**. Сторона сайта должна быть готова к приёму всех сообщений в правильном порядке.

### Что нужно проверить на стороне сайта

#### 5.1 Готовность обработчиков
- [ ] Все обработчики идемпотентны (повторная обработка безопасна)
- [ ] `HandlePartnerCreated` поддерживает создание пользователей (п.1)
- [ ] `HandleContractorCreated` создан и зарегистрирован (п.2)
- [ ] `HandleProductCreated` поддерживает `brand` как объект (п.3)
- [ ] `HandleProductUpdated` делает мерж атрибутов (п.4)

#### 5.2 Порядок зависимостей (проверить, что сайт корректно обрабатывает)
- [ ] Категории → Товары (категория должна существовать до товара)
- [ ] Товары → Цены (товар должен существовать до установки цены)
- [ ] Товары → Остатки (товар должен существовать до установки остатков)
- [ ] Партнёры → Контрагенты (пользователь должен существовать до контрагента)
- [ ] Сегменты → Скидки (сегменты должны существовать до привязок скидок)

#### 5.3 Нагрузочное тестирование
- [ ] Оценить объём данных для выгрузки (количество товаров, партнёров, категорий)
- [ ] Убедиться, что воркеры справляются с объёмом
- [ ] При необходимости — временно увеличить количество воркеров

---

## 6. Инфраструктура и документация

### 6.1 RabbitMQ-топология
- [ ] Добавить `contractor.*` в routing keys для `erp_in.partners`
- [ ] Выполнить `php artisan rabbitmq:setup` после обновления

### 6.2 JSON-шаблоны
- [ ] Создать `docs/rmq-templates/erp-to-site/partner.created.json`
- [ ] Создать `docs/rmq-templates/erp-to-site/contractor.created.json`

### 6.3 Документация
- [ ] Обновить `docs/RABBITMQ_1C_INTEGRATION.md` (если есть) — отразить новые события
- [ ] Обновить таблицу EVENT_HANDLERS в комментариях `ErpIncomingJob`

---

## 7. Сводный чеклист (порядок выполнения)

### Фаза 1: БД и модели
- [ ] Миграция: `must_change_password` в `users`
- [ ] Миграция: `external_id` в `companies` (если нет)
- [ ] Обновить модели `User`, `Company`

### Фаза 2: Обработчики ERP
- [ ] Переписать `HandlePartnerCreated` (создание + активация)
- [ ] Создать `HandleContractorCreated`
- [ ] Обновить `HandleProductCreated` (`brand` → объект)
- [ ] Обновить `HandleProductUpdated` (`brand` → объект, атрибуты → мерж)

### Фаза 3: Роутинг и инфраструктура
- [ ] Обновить `ErpIncomingJob::EVENT_HANDLERS`
- [ ] Обновить `SetupRabbitMQTopology::INCOMING_QUEUES`
- [ ] Запустить `rabbitmq:setup`

### Фаза 4: Middleware и UI
- [ ] Middleware `EnsurePasswordChanged`
- [ ] Страница принудительной смены пароля

### Фаза 5: Тесты
- [ ] Unit-тесты для всех обновлённых/новых обработчиков
- [ ] Feature-тест для middleware смены пароля
- [ ] Интеграционный тест: полный цикл выгрузки

### Фаза 6: Документация и шаблоны
- [ ] JSON-шаблоны для новых событий
- [ ] Обновление документации

---

## 8. Предотвращение петель событий (Event Loop Prevention)

> [!CAUTION]
> **Критически важный раздел.** Без этих мер возможна бесконечная петля сообщений между Сайтом и 1С.

### Выявленные риски

#### Риск 1: `partner.created` → `UserUpdated` → `partner.created` (∞ loop)

**Цепочка:**
```
1С → partner.created → HandlePartnerCreated
  → User::update(['status' => ACTIVE, 'erp_id' => ...])
    → Модель User стреляет событие UserUpdated
      → PublishUserToErp listener ловит UserUpdated
        → Проверяет: статус изменился на ACTIVE? ДА
          → PublishUserToErpJob → partner.created → erp_out.partners → 1С
            → 1С может отправить partner.created обратно → LOOP
```

**Текущее состояние:** Этот риск уже существует в v3 `HandlePartnerCreated`, но не проявляется, потому что `partner.created` не зарегистрирован как входящее событие в `ErpIncomingJob`. При добавлении в v4 — **петля активируется**.

#### Риск 2: `contractor.created` → события модели Company

**Цепочка (потенциальная):**
```
1С → contractor.created → HandleContractorCreated
  → Company::create(...)
    → Если на модели Company есть наблюдатели/события
      → Могут публиковать данные в 1С → LOOP
```

**Примечание:** `HandleOrderCreated` уже защищён от этого — используется `Company::withoutEvents()` и `Order::withoutEvents()`.

### Решение: `withoutEvents()` в ERP-обработчиках

Все ERP-обработчики, которые создают/обновляют модели с внешними слушателями, **обязаны** оборачивать операции в `Model::withoutEvents()`. Этот паттерн уже применяется в `HandleOrderCreated`.

### Чеклист

#### 8.1 `HandlePartnerCreated`
- [ ] Обернуть `User::update()` / `User::create()` в `User::withoutEvents(fn() => ...)`:
  ```php
  // ❌ Опасно — стреляет UserUpdated → PublishUserToErp → LOOP
  $user->update(['erp_id' => $uuid, 'status' => UserStatus::ACTIVE]);

  // ✅ Безопасно — события модели подавлены
  User::withoutEvents(function () use ($user, $uuid) {
      $user->update(['erp_id' => $uuid, 'status' => UserStatus::ACTIVE]);
  });
  ```
- [ ] То же самое для создания нового пользователя (v4):
  ```php
  User::withoutEvents(function () use ($payload) {
      return User::create([...]);
  });
  ```

#### 8.2 `HandleContractorCreated`
- [ ] Обернуть `Company::create()` / `Company::updateOrCreate()` в `Company::withoutEvents(fn() => ...)`

#### 8.3 Аудит существующих обработчиков
- [ ] Проверить все обработчики в `App\Services\Erp\Handlers\*`:
  - `HandlePartnerCreated` — **ТРЕБУЕТ ИСПРАВЛЕНИЯ** (нет `withoutEvents`)
  - `HandlePartnerDeleted` — проверить, нет ли слушателей на деактивацию
  - `HandleOrderCreated` — ✅ уже использует `withoutEvents()`
  - `HandleOrderUpdated` — проверить
  - Остальные обработчики — проверить на наличие моделей с внешними listener'ами

#### 8.4 Дополнительная защита: флаг `source` в payload
- [ ] Рассмотреть добавление проверки в `PublishUserToErp`:
  ```php
  // Если пользователь уже пришёл из ERP — не публиковать обратно
  if ($user->erp_id && !$user->wasRecentlyCreated) {
      // Пользователь изначально из ERP, пропускаем обратную публикацию
      return;
  }
  ```
  > Это **дополнительный** слой защиты, а не замена `withoutEvents()`.

#### 8.5 Тесты на отсутствие петель
- [ ] Тест: входящий `partner.created` из 1С → **НЕ** публикует `partner.created` обратно в `erp_out.partners`
- [ ] Тест: входящий `contractor.created` → **НЕ** триггерит внешние события Company
- [ ] Тест: входящий `order.created` → **НЕ** публикует `order.created` обратно (уже есть `withoutEvents`, но добавить тест)

### Общее правило

> [!IMPORTANT]
> **Все ERP-обработчики** (`Handle*` в `App\Services\Erp\Handlers\`) при создании или обновлении моделей с зарегистрированными слушателями **ОБЯЗАНЫ** использовать `Model::withoutEvents()`. Это предотвращает обратную публикацию событий в 1С и образование петель.

---

## Открытые вопросы (из v4)

| # | Вопрос | Влияние на сайт |
|---|---|---|
| 1 | Группы атрибутов — нужны ли? | Возможно потребуется новая таблица `attribute_groups` |
| 2 | Количество воркеров для `price.updated` / `stock.updated` | Настройка supervisor |
| 3 | `bank_accounts` в `contractor.created` — нужны ли? | Если да — добавить обработку в `HandleContractorCreated` |
