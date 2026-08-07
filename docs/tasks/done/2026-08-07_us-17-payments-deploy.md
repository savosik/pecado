# US-17 Платежи из 1С — доставлено на dev и prod

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

- [x] `Deploy to Dev` зелёный, на dev.pecado.ru спека `15.11.0` с `erp_in.payments`
- [x] Разделы платежей открываются на dev в админке, CRM и кабинете
- [x] `Deploy to Production` зелёный, pecado.ru отвечает 200
- [x] Очередь `erp_in.payments` создана, воркер `erp-payments-consumer` в статусе RUNNING
- [x] В логе прод-деплоя есть строка про синхронизацию прав `bi_agent`
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

---

## Результат

**Выкачено 2026-08-07.** Deploy to Dev — `31135141477` (success), Deploy to Production —
`31135918808` (success), включая pre-deploy backup БД и Health Check.

Из лога прод-деплоя:

```
2026_08_06_140000_create_payments_table ........................ 1 сек. DONE
2026_08_06_140100_create_payment_allocations_table ........... 619.11ms DONE
2026_08_06_140200_add_payment_aggregates_to_shipments_table .. 876.18ms DONE
==> [4.1/9] Права read-only пользователя BI/ИИ-агента...
Права bi_agent синхронизированы со схемой
Создание очереди: erp_in.payments
  Binding: payment.* → erp_in.payments
Создание DLQ: erp_dlq.payments
==> [9/9] Maintenance mode OFF...
```

Проверено снаружи на обоих контурах: спека `15.11.0`, три JSON Schema и
`rules/payments` отдают 200, разделы `/admin/payments`, `/crm/payments`,
`/cabinet/payments` отвечают 302 (редирект на логин — маршруты на месте).
pecado.ru — 200, maintenance снят.

**Задержка на сутки** была вызвана массовым сбоем GitHub Actions (major_outage
15:22–~23:00 UTC 6 августа): push доходил, но workflow run не создавался, потому что
GitHub обрабатывал ~15% вебхуков. Лечится повторным push после восстановления.

Осталось: передать контракт 1С-разработчику (см. блок ниже) и отозвать временный токен.
