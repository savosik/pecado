---
date: 2026-04-17
status: done
---

# partner.created (Сайт → 1С): триггер регистрации, is_active, превью-страница

## Задача

Переработать исходящее событие `partner.created` (Сайт → 1С):

1. Изменить триггер с `UserUpdated` (активация) на `UserCreated` (регистрация)
2. Добавить поле `is_active` (boolean) — всегда `false` при регистрации (статус PROCESSING)
3. Добавить поле `comment` (string|null) — секретная ссылка на превью-страницу пользователя
4. Создать публичную превью-страницу `/preview/user/{token}` для менеджеров
5. При регистрации автоматически назначать первый регион (по id)
6. Пользователи в статусе PROCESSING видят каталог как гости (без цен / корзины)
7. Пользователи в статусе BLOCKED немедленно разлогиниваются с уведомлением

## Решение

- `PublishUserToErp` listener переписан: обрабатывает только `UserCreated`
- `User::$fillable` + `boot()` → автогенерация `view_token` (48 символов) при создании
- Миграция `2026_04_17_130000_add_view_token_to_users_table.php`
- `UserPreviewController` + роут `GET /preview/user/{token}` + страница `UserPreview.jsx`
- Регион по умолчанию назначается в `AuthController::register()`
- `useProductHelpers.js`, `ProductInfo.jsx`, `CartQuantityControl.jsx` — `user = null` при статусе не `active`
- `EnsureUserIsNotBlocked` middleware в web-стеке
- Схема `partner.created.to_erp.json` обновлена
- AsyncAPI v12.8.0, MkDocs, тест-канбан обновлены

## Файлы

- `app/Listeners/PublishUserToErp.php`
- `app/Providers/AppServiceProvider.php`
- `app/Models/User.php`
- `app/Http/Controllers/UserPreviewController.php` (new)
- `app/Http/Controllers/Auth/AuthController.php`
- `app/Http/Middleware/EnsureUserIsNotBlocked.php` (new)
- `app/Services/Erp/Schemas/partner.created.to_erp.json`
- `database/migrations/2026_04_17_130000_add_view_token_to_users_table.php` (new)
- `routes/web.php`
- `resources/js/Pages/UserPreview.jsx` (new)
- `resources/js/hooks/useProductHelpers.js`
- `resources/js/components/product/ProductInfo.jsx`
- `resources/js/components/product/CartQuantityControl.jsx`
- `bootstrap/app.php`
- `docs/asyncapi/pecado-erp-integration.yaml` (v12.8.0)
- `docs-erp/content/rules/partners.md`
- `docs-erp/content/changelog.md`
- `docs-erp/tests-kanban/backlog/2.1-partner-to-erp.md`
- `tests/Feature/Listeners/PublishUserToErpTest.php`
