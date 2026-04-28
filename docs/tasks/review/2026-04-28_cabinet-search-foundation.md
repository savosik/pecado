# ЛК / Поиск: Фундамент (хелперы и общие компоненты)

**Приоритет:** высокий (блокирует волны 2–6)
**Создано:** 2026-04-28
**Roadmap:** [docs/cabinet-search-roadmap.md → Волна 1](../../cabinet-search-roadmap.md)
**Источник:** [docs/cabinet-search-scenarios.md](../../cabinet-search-scenarios.md) — Приложение А (A-1, A-4) + § «Сквозные принципы»

## Контекст

Все остальные карточки инициативы «Поиск в личном кабинете» используют общие куски: нормализацию номеров документов, классификатор типа запроса, единый компонент чипов фильтров и историю последних запросов. Чтобы не дублировать их в каждом разделе, фундамент выделен в первую волну.

Эта карточка — **зависимость** для:
- [cabinet-search-orders](2026-04-28_cabinet-search-orders.md) (Волна 2)
- [cabinet-search-returns](2026-04-28_cabinet-search-returns.md) (Волна 2)
- [cabinet-search-shipments](2026-04-28_cabinet-search-shipments.md) (Волна 2)
- [cabinet-search-shipments-picker](2026-04-28_cabinet-search-shipments-picker.md) (Волна 2)
- [cabinet-search-cart-products](2026-04-28_cabinet-search-cart-products.md) (Волна 3)
- [cabinet-search-favorites](2026-04-28_cabinet-search-favorites.md) (Волна 3)
- [cabinet-search-carts-list](2026-04-28_cabinet-search-carts-list.md) (Волна 6)
- [cabinet-search-product-exports](2026-04-28_cabinet-search-product-exports.md) (Волна 6)

## План реализации

### PR 1.1 — PHP-хелперы

#### `App\Support\Search\DocumentNumber`

Файл: `app/Support/Search/DocumentNumber.php` (новый — каталога `app/Support/Search/` ещё нет, создать).

```php
final class DocumentNumber
{
    public static function normalize(string $value): string
    {
        return preg_replace('/[\s\-]+/u', '', mb_strtolower(trim($value)));
    }

    public static function isLikelyBarcode(string $value): bool
    {
        return preg_match('/^\d{8,14}$/', $value) === 1;
    }

    public static function isLikelyTaxId(string $value): bool
    {
        return preg_match('/^\d{10}$|^\d{12}$/', $value) === 1;
    }
}
```

**Контракт** (из [§«Сквозные принципы» п.2](../../cabinet-search-scenarios.md)):
- `29УТ-003413` ≡ `29УТ003413` ≡ `003413` (по нормализованной форме).
- Регистронезависимо.
- 8/12/13/14 цифр → штрихкод (приоритет EAN13 — но это уже логика контроллера).
- 10/12 цифр → ИНН.

#### `App\Support\Search\QueryRouter`

Файл: `app/Support/Search/QueryRouter.php`.

Классификатор пользовательского запроса:
```php
final class QueryRouter
{
    public const TYPE_TEXT = 'text';
    public const TYPE_BARCODE = 'barcode';
    public const TYPE_TAX_ID = 'tax_id';
    public const TYPE_UUID = 'uuid';
    public const TYPE_DOCUMENT_NUMBER = 'document_number';
    public const TYPE_SKU_LIKE = 'sku_like';

    public static function classify(string $value): string { /* ... */ }
}
```

Эвристики (приоритет сверху вниз):
1. UUID (regex `/^[0-9a-f-]{8,36}$/i` — фрагмент UUID разрешён).
2. Штрихкод (`DocumentNumber::isLikelyBarcode`).
3. ИНН (`DocumentNumber::isLikelyTaxId`).
4. Номер документа (содержит дефис + буквы+цифры — например `29УТ-003413`).
5. SKU-like (`/^[a-z0-9-]+$/i` без пробелов, с латиницей).
6. Текст (всё остальное).

**Тесты:** `tests/Unit/Support/Search/DocumentNumberTest.php`, `tests/Unit/Support/Search/QueryRouterTest.php` — кейсы из § «Сквозные принципы» и сценариев C-1.1, C-1.6, C-1.9.

### PR 1.2 — Frontend

#### Общий `<SelectedFilters />`

Файл: `resources/js/components/cabinet/SelectedFilters.jsx` — копия [Pages/User/Products/SelectedFilters.jsx](../../../resources/js/Pages/User/Products/SelectedFilters.jsx) с конфигом полей через props:

```jsx
<SelectedFilters
  filters={filters}
  fields={[
    { key: 'search', label: 'Поиск', formatter: v => v },
    { key: 'company_id', label: 'Контрагент', formatter: id => companies.find(c => c.id === id)?.name },
    // ...
  ]}
  onRemove={(key) => router.get(url, { ...filters, [key]: null }, { preserveState: true })}
/>
```

**Не удалять** оригинал `Pages/User/Products/SelectedFilters.jsx` — он используется каталогом, мигрируем после волн 2-3.

#### Хук `useSearchHistory(section, limit = 10)`

Файл: `resources/js/hooks/useSearchHistory.js`.

```js
export function useSearchHistory(section, limit = 10) {
    const key = `cabinet.search.${section}.history`;
    // get/push/clear на localStorage
    return { history, push, clear };
}
```

**Без серверной синхронизации** — серверная версия через `search_histories` (как в каталоге) — отдельная задача за пределами фундамента.

## Критерии готовности

### PR 1.1
- [x] `App\Support\Search\DocumentNumber` создан с тремя статическими методами
- [x] `App\Support\Search\QueryRouter` создан с классификацией 6 типов
- [x] Unit-тесты покрывают все примеры из § «Сквозные принципы» и кейсы C-1.1 / C-1.6 / C-1.9 (16 тестов / 52 ассерта)
- [x] `composer test` — новые тесты зелёные. На прогоне всего сюита остаются 38 pre-existing падений (`DiskCannotBeAccessed` в MediaLibrary-тестах), к скоупу PR отношения не имеют.
- [x] Pint + PHPStan чистые на файлах PR (`vendor/bin/pint --test app/Support/Search/ tests/Unit/Support/Search/` — PASS; `vendor/bin/phpstan analyse app/Support/Search/ tests/Unit/Support/Search/` — OK).

### PR 1.2
- [x] `resources/js/components/cabinet/SelectedFilters.jsx` создан и принимает `fields` через props (`{ key, label?, formatter?, valueKey? }`), поддерживает скаляр и массив значений, опциональный `onResetAll`/`resetLabel`.
- [x] `resources/js/hooks/useSearchHistory.js` создан: `localStorage` `cabinet.search.{section}.history`, дедупликация, лимит, минимум 2 символа (§ «Сквозные принципы» п.3).
- [x] Оригинальный `Pages/User/Products/SelectedFilters.jsx` не тронут (каталог продолжает работать; миграция отложена до закрытия волн 2-3).
- [x] `npm run build` зелёный — никаких изменений в существующих страницах кабинета.

## Технические заметки

- Без миграций, без фича-флагов — чисто аддитивный код.
- Откат — `git revert` обоих PR. Никаких побочных эффектов на данных.
- После закрытия волны 2 (когда все потребители используют общий `<SelectedFilters />` и каталог тоже мигрировал) — отдельная задача на удаление дубликата `Pages/User/Products/SelectedFilters.jsx`.

## Зависимости

- Не блокируется ничем.
- Блокирует Волны 2-3 и волну 6 (см. список в начале карточки).
