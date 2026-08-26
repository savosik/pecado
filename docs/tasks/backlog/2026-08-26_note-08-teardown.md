# note-08 — снос движка правил

**Эпик:** `note-00`
**Зависит от:** все остальные карточки
**Делается последним намеренно**

## Почему последним

Пока не проверено на боевых данных, что матрица покрывает реальные случаи,
движок правил остаётся рабочим запасным путём. Снос — признание, что запасной
путь больше не нужен.

## Что сносим

**Код:** `MailRuleEngine`, `MailRuleService`, `LetterMatcher`,
`ConditionEvaluator`, `MailFieldCatalog`, `MailRuleController`,
`MailOccasionController`, `StoreMailRuleRequest`.

**Таблицы:** `crm_mail_rules`, `crm_mail_rule_clients`, `crm_mail_rule_hits`.

**Экраны:** `Rules.jsx`, `Occasions.jsx`, `RuleForm.jsx`, `SubscriberPicker.jsx`,
`Suppressions.jsx` (стоп-лист уходит из интерфейса, модель остаётся как
техническая защита от жалоб на спам и bounce).

**Тесты** соответствующих карточек `bus-01`, `bus-02`.

## Что сохраняем

`LegacySenders` (`bus-03`) — уже переехал в роутер.
`MailTracker`, `MailDeliveryLedger` — отслеживание открытий общее.
`MailTagBuilder` — метки остаются как признак письма, не как условие правила.
`NotificationSuppression` — техническая защита.

## Проверка

- `make verify` зелёный после сноса;
- миграции откатываются на чистой базе;
- ни одна ссылка на снесённые маршруты не осталась в меню и шаблонах.
