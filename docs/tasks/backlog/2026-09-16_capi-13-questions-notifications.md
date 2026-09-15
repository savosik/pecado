# capi-13: вопрос менеджеру и настройки уведомлений через API

**Приоритет:** средний
**Создано:** 2026-09-16
**Эпик:** capi-00
**Зависимости:** capi-03

## Описание

Две секции, которые делают агента самостоятельным: он может спросить менеджера о том, чего API не
решает, и сам подписаться на статусы заказов (клиентские уведомления по умолчанию выключены —
решение 27.08.2026, поэтому без этой секции агент о смене статуса не узнает).

| Операция | Параметры | Сервис |
|---|---|---|
| `questions.create` POST `questions` (M, I) | `subject`, `body`, `order?` (ссылка на заказ для контекста) | **вынести** `UserQuestionController::store` (~80 строк: `UserQuestion`, уведомления клиенту и сотрудникам по `config('notifications.mail.user_question_recipients')`, `MailStream::captureQuietly(Occasion('system.question_received'))`) → `App\Services\Support\UserQuestionService::create`; `/faq/questions` на него |
| `questions.list` GET `questions` | `cursor` | presenter из `CabinetQuestionsController::index` |
| `questions.get` GET `questions/{question}` | — | `status` (`UserQuestionStatus`), `answer`, `answered_at`; вложение — подписанной ссылкой (`FileLinks`) |
| `notifications.list` GET `notifications` | — | `NotificationMatrix` для клиента: поводы с `client_visible`, `is_enabled`, адресаты, подтипы |
| `notifications.update` PUT `notifications/{occasion_key}` (M) | `is_enabled`, `destinations[]?`, `options?` | `NotificationSettings` с проверкой ключа по `NotificationCatalog` (только `client_visible`); тот же путь, что `/cabinet/notifications` |
| `notifications.marketing` PUT `notifications/marketing` (M) | `enabled` | как `NotificationPreferenceController` |

Вопрос из API помечается источником (`ClientApiSource`) — в админке видно, что писал агент.

## Критерии готовности

- [ ] Вопрос из API виден в `/cabinet/questions` и в админке `UserQuestion`; письма сотрудникам и клиенту ушли по тем же правилам, что из `/faq`; `Occasion('system.question_received')` захвачен.
- [ ] Повтор `questions.create` с тем же ключом не создаёт второй вопрос.
- [ ] `notifications.update` меняет `notification_preferences` ровно так же, как `/cabinet/notifications` (паритет-тест); ключ не из каталога или не `client_visible` → 422.
- [ ] Возврат к умолчанию удаляет строку отклонения (правило матрицы), а не пишет «включено=умолчание».
- [ ] Кабинетные тесты вопросов и уведомлений зелёные; `/faq/questions` работает через сервис.
- [ ] `make lint` зелёный.
