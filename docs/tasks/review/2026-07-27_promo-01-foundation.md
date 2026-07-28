# Промо 01 — Фундамент: модель правила акции, миграции, схема

**Приоритет:** высокий (блокирует всю инициативу)
**Создано:** 2026-07-27
**Roadmap:** [docs/promo-constructor-roadmap.md → Волна 1](../../promo-constructor-roadmap.md)

## Контекст

Сейчас `Promotion` ([app/Models/Promotion.php](../../../app/Models/Promotion.php)) — чисто
контентная сущность: name, slug, description, картинки, регионы через трейт `HasRegions`,
привязка товаров через pivot `product_promotion`. Логики срабатывания нет.

Не путать с `ErpPromotion` ([app/Models/ErpPromotion.php](../../../app/Models/ErpPromotion.php)) —
это группы товаров из 1С с типами `new/bestseller/liquidation`, которые разворачиваются в
флаги `products.is_new/is_bestseller/is_liquidation`. `ErpPromotion` мы не трогаем, но
используем как один из селекторов товаров в условиях («все ликвидационные»).

Эта карточка добавляет рядом с `Promotion` сущность **правила** и всё хранилище. Движок,
интерфейс и выдача — в следующих карточках.

## Модель данных

### `promotion_rules` (новая таблица)

| Поле | Тип | Комментарий БД |
|---|---|---|
| `id` | id | Первичный ключ |
| `promotion_id` | foreignId nullable | Акция-лендинг (promotions.id); NULL — служебное правило без страницы |
| `name` | string | Название правила для админки |
| `is_active` | boolean, default false | Правило включено |
| `mode` | string | Режим: 'info' — только показываем, 'issue' — выдаём промо-позиции |
| `starts_at` / `ends_at` | timestamp nullable | Период действия; NULL — без ограничения |
| `priority` | unsignedSmallInteger, default 0 | Приоритет при конфликте правил, больше — важнее |
| `stackable` | boolean, default true | Можно ли применять вместе с другими правилами |
| `conditions` | json | Условия срабатывания |
| `rewards` | json | Награды (промо-позиции) |
| `audience` | json nullable | Ограничения по аудитории |
| `limits` | json nullable | Лимиты выдачи |
| `timestamps` | | |
| `softDeletes` | | Правило может быть в архиве |

Индексы: `is_active` + `starts_at` + `ends_at` (составной, для выборки активных),
`promotion_id`.

**`mode` — важное поле.** Волна 1 запускается целиком в `info`: правила настраиваются,
показываются в интерфейсе, но промо-позиции не выдаются. Переключение в `issue`
становится осмысленным только после волны 2. Значение по умолчанию — `info`.

### `promotion_rule_product` (новая таблица)

Материализованный список товаров-участников: `promotion_rule_id`, `product_id`,
`role` (`condition` — участвует в условии, `reward` — выдаётся как награда).
Первичный ключ — composite из трёх полей, отдельный индекс на `product_id`.

Нужна, чтобы каталог мог дёшево показать бейдж «участвует в акции» и отфильтровать по
нему без раскрытия JSON-условий на каждый товар. Пересчитывается джобой — см. ниже.

### `cart_promotion_selections` (новая таблица)

Хранит **только то, что нельзя вычислить**: `cart_id`, `promotion_rule_id`,
`reward_index` (порядковый номер награды в массиве `rewards`), `product_id` (выбранная
из нескольких), `is_declined` (клиент отказался от отклоняемой платной позиции),
timestamps. Уникальный ключ по `cart_id + promotion_rule_id + reward_index`.

Строки самих промо-позиций **не хранятся** — см. принципы в roadmap.

## Схема `conditions`

```json
{
  "mode": "all",
  "items": [
    {
      "selector": {
        "products":       [123, 456],
        "categories":     [12],
        "with_descendants": true,
        "brands":         [7],
        "tags":           ["lovense"],
        "erp_promotions": ["liquidation"],
        "whole_cart":     false
      },
      "aggregate":   "amount",
      "price_basis": "client_final",
      "operator":    ">=",
      "value":       150000
    }
  ]
}
```

- `mode` — `all` | `any`.
- Поля селектора объединяются по **ИЛИ** (товар подходит, если попал хоть в один список).
  Пустой селектор с `whole_cart: true` — вся корзина.
- `categories` + `with_descendants: true` — раскрывать через `kalnoy/nestedset`.
- `aggregate` — `quantity` (штуки) | `amount` (сумма).
- `price_basis` — пока единственное значение `client_final`: сумма считается по финальной
  цене клиента, то есть по индивидуальным ценам со скидкой. Поле заведено на будущее,
  валидатор обязан отвергать любое другое значение.

## Схема `rewards`

```json
[
  {
    "type":        "fixed",
    "product_id":  789,
    "choices":     null,
    "quantity":    1,
    "price":       0,
    "promo_kind":  "accountable",
    "warehouse_id": 3,
    "multiply":    "once",
    "per_value":   null,
    "max_multiplier": 1,
    "optional":    false
  }
]
```

- `type` — `fixed` (конкретный товар) | `choice` (клиент выбирает из `choices`).
- `price` — промо-цена в рублях, decimal(12,2), **произвольная**: 0, 0.01, 10, 40.
- `promo_kind` — `accountable` | `sample`. Определяет тип будущего заказа.
- `warehouse_id` — склад-источник. Для `sample` это «Москва реклама» (заводится в волне 3;
  до тех пор такие награды сохранять можно, но правило с ними не активируется — валидация).
- `multiply` — `once` | `per_threshold`. При `per_threshold` обязательны `per_value`
  (на каждые N штук / X рублей) и `max_multiplier` ≥ 1.
- `optional` — клиент может отказаться. Для `price = 0` значение игнорируется
  (бесплатную позицию не отклоняем), для `price > 0` по умолчанию `true`.

## Схема `audience` и `limits`

```json
{
  "region_ids":  [1, 2],
  "user_ids":    [],
  "manager_ids": [],
  "channels":    ["site", "api"]
}
```

```json
{
  "per_client_total": 1,
  "total": null
}
```

`channels` — где правило работает: `site` (корзина и чекаут), `api` (клиентское API).
Пустой массив или отсутствие поля = везде.

## План реализации

### 1. Миграции

Три новые таблицы + `softDeletes`. По правилу проекта:
- миграции **только новые**, старые не редактируем;
- **обязательны русские `->comment()`** на таблице и каждом столбце
  (см. `.claude/rules/db-comments.md`), для FK — со ссылкой вида `'Акция (promotions.id)'`,
  для строк-перечислений — с перечнем значений;
- после `php artisan migrate` — **`php artisan bi:sync-grants`**, иначе аналитический
  MCP не увидит новые таблицы.

Проверка: `docker exec pecado-app php artisan db:comments:audit --strict`.

### 2. Модель `App\Models\PromotionRule`

- `casts`: `conditions/rewards/audience/limits` → `array`, даты → `datetime`,
  `is_active`/`stackable` → `boolean`.
- Отношения: `promotion()` (belongsTo, nullable), `products()` (belongsToMany через
  `promotion_rule_product` с pivot `role`).
- Скоуп `active()` — `is_active = true` **и** попадание `now()` в период
  (`starts_at` ≤ now или NULL, `ends_at` ≥ now или NULL).
- Скоуп `forMode(string $mode)`.
- Хелпер `appliesToChannel(string $channel): bool` по `audience.channels`.

В `Promotion` добавить `rules(): HasMany`.

### 3. Валидация структуры

`App\Services\Promotion\PromotionRuleSchemaValidator` — валидация `conditions`/`rewards`/
`audience`/`limits` перед сохранением. Использовать тот же подход, что в
[ErpMessageValidator](../../../app/Services/Erp/ErpMessageValidator.php): JSON Schema-файлы
рядом с кодом, в `app/Services/Promotion/Schemas/`.

Обязательные проверки (каждая — с русским сообщением об ошибке):
- `aggregate` ∈ {quantity, amount}, `price_basis` = `client_final`;
- селектор не пустой (иначе правило матчит всю корзину незаметно для маркетолога) —
  либо явно `whole_cart: true`;
- `price` ≥ 0, не более двух знаков после запятой;
- при `multiply: per_threshold` — `per_value` > 0 и `max_multiplier` ≥ 1
  (**без потолка нельзя**: одна крупная закупка выметет весь остаток склада);
- `type: choice` → `choices` содержит ≥ 2 товара;
- `warehouse_id` существует и соответствует `promo_kind`
  (для `sample` — склад с флагом `is_promo_sample`, флаг появится в волне 3);
- ~~товар награды не входит в селектор условия того же правила~~ — **проверка снята
  2026-07-28**, см. «Исправления после обкатки» ниже.

### 4. Материализация участников

`App\Jobs\RecalculatePromotionRuleProductsJob` — раскрывает селекторы правила в
`promotion_rule_product`. Образец — синхронный
[RecalculateProductPromoFlags](../../../app/Services/Erp/Handlers/RecalculateProductPromoFlags.php).

Триггеры пересчёта:
- сохранение/удаление `PromotionRule` (обсервер);
- изменение состава категории или тегов товара — **не по каждому товару**, а батчем:
  ночная команда `promo:rebuild-rule-products` + ручной запуск из админки.

Джоба идемпотентна: полный пересчёт списка для правила в транзакции (delete + insert),
не инкрементальные правки.

### 5. Фабрики и сидер

`PromotionRuleFactory` со стейтами `amountThreshold()`, `quantityThreshold()`,
`freeGift()`, `paidPromoItem()`, `sampleReward()` — их будут использовать тесты всех
последующих карточек.

## Нюансы

- **Права.** Существующие права `promotions.*` покрывают лендинги. Для правил завести
  отдельные `promotion-rules.{view,create,edit,delete}` — редактирование механики выдачи
  товаров это не то же самое, что правка текста акции. Роль контент-менеджера получает
  только `view`.
- **Мультивалютность.** Порог `amount` задаётся в рублях. У заказов есть `currency_code`,
  `exchange_rate`, `rate_coefficient`; сравнение всегда ведём в рублях, конвертацию
  выполняет движок (карточка 02), в хранилище — только рубли. Зафиксировать это
  комментарием в схеме и в подсказке поля в админке.
- **Soft delete.** Удалённое правило не должно исчезать из истории: `order_items` будут
  ссылаться на `promotion_rule_id` (волна 2). FK — `nullOnDelete`, но модель под
  `SoftDeletes`, так что при обычном удалении ссылка сохраняется.

## Критерии готовности

- [x] Три миграции применяются и откатываются; все таблицы и столбцы прокомментированы
      по-русски, `db:comments:audit --strict` проходит.
- [x] `bi:sync-grants` выполнен, таблицы видны аналитическому агенту.
- [x] Модель `PromotionRule` со скоупами `active()`, `forMode()` и связями покрыта
      unit-тестами (включая границы периода: правило, стартующее сегодня в 23:59).
- [x] Валидатор отвергает все перечисленные некорректные конфигурации с русскими
      сообщениями; на каждый пункт списка — тест.
- [x] `RecalculatePromotionRuleProductsJob` раскрывает категории с потомками, бренды,
      теги и `ErpPromotion`; повторный запуск не плодит дубли.
- [x] Права `promotion-rules.*` заведены в сидере ролей.
- [x] `composer lint` и `composer analyse` чистые на изменённых файлах.

## Что сделано (2026-07-27)

**Миграции**

- `2026_07_27_100000_create_promotion_rules_table` — правила с `mode` по умолчанию `info`,
  soft delete, составной индекс `is_active + starts_at + ends_at`.
- `2026_07_27_100100_create_promotion_rule_product_table` — composite PK из трёх полей.
- `2026_07_27_100200_create_cart_promotion_selections_table` — уникальный ключ
  `cart_id + promotion_rule_id + reward_index`.
- `2026_07_27_100300_grant_promotion_rule_permissions` — `promotion-rules.*`,
  контент-менеджеру только `view`.
- `2026_07_27_100400_add_missing_column_comments` — попутно закрыты 7 столбцов без
  комментариев в `analytics_tokens` и `crm_analytics_filter_presets`, иначе
  `db:comments:audit --strict` не проходил из-за старого долга.

**Код**

- `App\Models\PromotionRule` (скоупы `active()`/`forMode()`, `appliesToChannel()`,
  `isActiveAt()`, константы ролей/типов), `App\Models\CartPromotionSelection`,
  связи `Promotion::rules()` и `Cart::promotionSelections()`.
- Перечисления `App\Enums\PromotionRuleMode`, `App\Enums\PromoKind`.
- `App\Services\Promotion\PromotionRuleSchemaValidator` + JSON Schema в
  `app/Services/Promotion/Schemas/` (сообщения об ошибках — прямо в схемах через `$error`).
- `App\Services\Promotion\PromotionRuleProductResolver` — общий раскрыватель селекторов
  для джобы и валидатора.
- `App\Jobs\RecalculatePromotionRuleProductsJob`, `App\Observers\PromotionRuleObserver`,
  команда `promo:rebuild-rule-products` (в расписании — ежедневно в 02:40).
- `PromotionRuleFactory` со стейтами `amountThreshold`, `quantityThreshold`, `freeGift`,
  `paidPromoItem`, `sampleReward`, `perThreshold`, `active`, `issuing`.

**Тесты** — 49 зелёных: `tests/Unit/Models/PromotionRuleTest.php`,
`tests/Feature/Promotion/PromotionRuleSchemaValidatorTest.php`,
`tests/Feature/Promotion/RecalculatePromotionRuleProductsJobTest.php`.

### Решения, принятые по ходу

- **Селектор «вся корзина» не считается пересечением с наградой.** Из `whole_cart` товар
  награды не убрать, а промо-строки движок в агрегаты и так не берёт — запрет здесь
  сделал бы конфигурацию невыполнимой.
- **Материализация не разворачивает `whole_cart`** — весь каталог в
  `promotion_rule_product` не пишем.
- **Скрытые товары (`hidden`) в участники попадают**: правило настраивается заранее,
  видимость проверяет движок.
- **Сидер ролей научился частичным правам** (`'promotion-rules' => ['view']`): иначе
  `syncPermissions` затирал бы `view`, выданный контент-менеджеру миграцией.
- `RoleController` получил ресурс в группе «Маркетинг» — этого требует
  `Tests\Feature\Crm\PermissionNamingTest`.

## Исправления после обкатки (2026-07-28)

**Снят запрет «товар награды не должен входить в условие».** На ручном тестировании
всплыла реальная акция отдела продаж: «за каждые 5 штук LE-39 — шестой LE-39 в подарок».
Товар награды здесь обязан совпадать с товаром условия, а валидатор такую конфигурацию
не пропускал.

Обоснование запрета было умозрительным: «промо-позиция начнёт влиять на собственное
условие». В действительности движок промо-строки в агрегаты не берёт
(`PromoContext::countableLines()` отбрасывает `isPromo`), поэтому подарок собственное
условие не подкручивает — это архитектурный принцип из дорожной карты, заложенный
с самого начала.

Что сделано: убраны `validateRewardNotInConditions()` и параметр `$conditionsUsable`
из `validateRewardsMeaning()`; два теста, закреплявших запрет, переписаны на
разрешение; в `PromotionEngineTest` добавлены сценарии «5 + 1 в подарок» (включая
проверку, что 12 штук дают два подарка, а не три) и «подаренная строка не кормит
собственное условие»; в форме награды появилась подсказка, как собрать такую механику.

### Не входило в эту карточку

Обмена с 1С здесь нет (волна 1 не трогает шину), поэтому AsyncAPI и MkDocs не менялись.
Админка правил, движок и выдача — карточки 02–05.
