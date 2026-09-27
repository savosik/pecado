# capi-10: печатные документы и договоры через API

**Приоритет:** средний
**Создано:** 2026-09-16
**Эпик:** capi-00
**Зависимости:** capi-01

## Описание

Секции `documents` (гейт DOCUMENTS — `config('documents.enabled')`, сегодня `false` на проде) и
`contracts` (гейт CONTRACTS — `config('contracts.cabinet_enabled')`). Файлы отдаются двумя способами:
бинарником по Bearer и **временной подписанной ссылкой**, чтобы агент мог передать документ человеку.

| Операция | Параметры | Сервис |
|---|---|---|
| `documents.list` GET `documents` | `type[]` (`PrintedDocumentType`), `company_id`, `organization_id`, `date_from/to`, `number`, `cursor` | `PrintedDocument::visibleTo($user)`; **вынести** `PrintedDocumentController::buildIndexQuery` (~100 строк) → `App\Services\Documents\ClientDocumentQuery`; контроллер на него |
| `documents.download` GET `documents/{document}/file` | — | как `PrintedDocumentController::download` (`file_status = FILE_STORED`, `Storage::disk`) |
| `documents.link` GET `documents/{document}/link` | `ttl` ≤ 60 мин | **новый** `App\Services\Client\Api\FileLinks` → `URL::temporarySignedRoute` на `ClientFileController` |
| `contracts.list` GET `contracts` | `status`, `company_id`, `cursor` | `Contract::visibleTo($user)`; presenter вынести из `ContractController::index` |
| `contracts.get` GET `contracts/{contract}` | — | то же + вложения (`is_visible_in_cabinet`) |
| `contracts.link` GET `contracts/{contract}/files/{media}/link` | — | `FileLinks` |

`App\Http\Controllers\Api\Client\ClientFileController` — маршрут под middleware `signed`, без Bearer,
проверяет `visibleTo` по `user_id` из подписи. Обратного потока «запросить акт сверки у 1С» нет
(вне скоупа doc-00).

## Критерии готовности

- [ ] При `DOCUMENTS_ENABLED=false` → 403 `documents_disabled` на всех операциях секции и `allowed=false` в `/me`; при включённом — список/файл/ссылка.
- [ ] Чужой документ / договор / вложение → 404.
- [ ] Подписанная ссылка открывает файл без Bearer, протухает по `ttl`, подделка подписи → 403.
- [ ] Список документов совпадает с кабинетом на общем фикстурном наборе (фильтр по компании пересекается со своими юрлицами).
- [ ] Кабинетные тесты документов и договоров зелёные; `db:comments:audit --strict` не нужен (миграций нет).
- [ ] `make lint` зелёный.
