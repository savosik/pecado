# US-17 Платежи из 1С — доставить на dev и prod

**Приоритет:** высокий
**Исполнитель:** savosik
**Создано:** 2026-08-07

## Описание

Код готов и закоммичен: **`052710d9`** `feat(erp): платежи из 1С с разнесением по реализациям`,
лежит в `origin/dev`. Осталась только доставка.

**Почему не уехало 6 августа:** массовый сбой GitHub Actions (инцидент открыт 15:22 UTC,
компонент `Actions` — `major_outage`). GitHub обрабатывал ~15% вебхуков, и push просто
не создал workflow run. Проверено и исключено: лимит минут (917 из 2000 бесплатных,
к оплате $0), Actions в репозитории enabled, workflow active. Косвенное подтверждение —
после 17:53 UTC не стартовал даже Uptime Monitor по расписанию.

**Пропущенные вебхуки GitHub задним числом не проигрывает** — нужен новый push-триггер.

## Что сделать

1. Убедиться, что Actions поднялись: https://www.githubstatus.com/ (компонент Actions
   должен быть `operational`).
2. Толкнуть триггер dev-деплоя. **Коммит с этой карточкой специально
   оставлен незапушенным** (`git log origin/dev..dev` покажет его) — он и будет триггером, достаточно одной команды:
   ```bash
   git push origin dev
   ```
   Если он уже уехал, а запуск снова не создался (вебхук потерялся) — нужен новый push:
   ```bash
   git commit --allow-empty -m "chore(ci): перезапуск деплоя после сбоя GitHub Actions"
   git push origin dev
   ```
3. Дождаться зелёного `Deploy to Dev` (~8 мин, тесты идут полностью — `[fast]` не ставился):
   ```bash
   gh run list --branch dev --limit 3
   gh run watch <run-id>
   ```
4. Проверить, что выкатилось (сейчас там спека 15.9.0, должна стать 15.11.0):
   ```bash
   curl -s https://dev.pecado.ru/docs/erp/spec.yaml | head -5
   curl -s https://dev.pecado.ru/docs/erp/spec.yaml | grep -c erp_in.payments   # ожидаем > 0
   ```
   Плюс глазами: `/admin/payments`, `/crm/payments`, `/cabinet/payments`,
   `/docs/erp-guide/rules/payments/`.
5. Релиз на прод:
   ```bash
   git push origin dev:main
   ```
6. Дождаться `Deploy to Production` и **обязательно проверить 200 на pecado.ru**: упавший
   прод-деплой оставляет сайт под maintenance до ручного `artisan up`.

## Что произойдёт автоматически

- `migrate --force` — три миграции: `payments`, `payment_allocations`, агрегаты оплаты
  на `shipments`.
- `db:seed --class=RolesAndPermissionsSeeder --force` — права `payments.view/edit/delete`.
- `rabbitmq:setup` — создаст очередь `erp_in.payments` + DLQ с routing key `payment.*`.
- `restart worker` — supervisor поднимет программу `erp-payments-consumer` (`numprocs=1`).
- **Только на prod:** `bi:sync-grants` — выдаст `bi_agent` доступ к `payments`
  и `payment_allocations`, иначе MCP-агент их не увидит. На dev грантов нет by design.

## Проверить после прод-деплоя

```bash
# очередь появилась и слушается
docker exec pecado-app php artisan rabbitmq:status | grep payments
docker exec pecado-worker supervisorctl status | grep payments

# гранты доехали (в логе прод-деплоя должно быть «Права bi_agent синхронизированы со схемой»)
```

## Критерии готовности

- [ ] `Deploy to Dev` зелёный, на dev.pecado.ru спека `15.11.0` с `erp_in.payments`
- [ ] Разделы платежей открываются на dev в админке, CRM и кабинете
- [ ] `Deploy to Production` зелёный, pecado.ru отвечает 200
- [ ] Очередь `erp_in.payments` создана, воркер `erp-payments-consumer` в статусе RUNNING
- [ ] В логе прод-деплоя есть строка про синхронизацию прав `bi_agent`
- [ ] Отозвать временный GitHub-токен: https://github.com/settings/tokens

## Дальше по теме (отдельной задачей)

Отдать 1С-разработчику контракт и раздел «Требуется от 1С» в
[changelog 15.11.0](../../../docs-erp/content/changelog.md). Критичное:

- `direction` (`in`/`out`) и `operation_code` присылать **явно**, не выводить из текста операции;
- `partner_uuid` обязателен на практике — без него платёж не привяжется к пользователю
  сайта и клиент не увидит оплату в кабинете;
- **отсутствие ключа `allocations` ≠ пустой массив**: ключа нет — сайт не трогает
  разнесение, `[]` — очищает его полностью;
- разнесение присылать целиком, а не досылать изменившиеся строки;
- первичную выгрузку платежей делать после реализаций.
