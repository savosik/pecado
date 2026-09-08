# Приложение. Профиль данных

## Назначение

Свод фактических измерений боевой базы, на которых построено всё ТЗ. Каждое число сопровождается
запросом, которым получено, чтобы любую цифру можно было перепроверить, а не принимать на веру.

## Область

Входит: объёмы и распределения, определяющие устройство экранов и оценку показателей.
Не входит: сами решения — они в соответствующих разделах ТЗ.

## Основания

Замеры сделаны 08.09.2026 **на боевой базе** (`ssh pecado.ru` → `docker exec pecado-app php artisan
tinker`). Проверка на актуальность — повторный прогон приведённых запросов. Расхождение более чем
на 10 % означает, что раздел ТЗ, опирающийся на число, требует пересмотра.

Первая редакция приложения делалась по локальной копии базы, отстававшей на неделю: в ней
отсутствовали запись об отпуске и часть августовских отгрузок. Значения, приведённые ниже,
взяты с прода.

---

## 1. Периметр

Активных менеджеров, участвующих в расчёте оплаты труда, — два.

| id | Менеджер | `is_active` | `payroll_enabled` |
|---|---|---|---|
| 1 | Сухов Иван | да | да |
| 2 | Курочкина Елена Валерьевна | да | да |
| 6 | Трипуть Вячеслав (руководитель) | да | **нет** |

Остальные шесть карточек `personal_managers` неактивны и закреплённых партнёров практически не имеют.
Карточка руководителя используется как временный держатель ничейных партнёров и в расчёте не участвует.

```sql
SELECT id, name, is_active, payroll_enabled FROM personal_managers ORDER BY id;
```

Глубина истории отгрузок — **с 12.01.2026**, 8 387 документов.

```sql
SELECT MIN(erp_created_at), MAX(erp_created_at), COUNT(*) FROM shipments;
```

Это ограничение определяет три раздела ТЗ: признание нового партнёра, расчёт медианы для плана
и трекер квартальной премии.

---

## 2. Закреплённая база

| Показатель | Сухов | Курочкина |
|---|---|---|
| Закреплено партнёров | 113 | 103 |
| Из них когда-либо отгружались | 55 | 53 |
| Отгружались за последние 3 месяца | 46 | 40 |
| Отгружались в августе | 33 | 22 |

```sql
SELECT u.personal_manager_id AS pm,
       COUNT(*) AS zakrepleno,
       SUM(EXISTS(SELECT 1 FROM shipments s
                  WHERE s.user_id = u.id AND s.deleted_at IS NULL)) AS kogda_libo,
       SUM(EXISTS(SELECT 1 FROM shipments s
                  WHERE s.user_id = u.id AND s.deleted_at IS NULL
                    AND s.erp_created_at >= '2026-06-01')) AS za_3_mesyaca
FROM users u
WHERE u.personal_manager_id IN (1, 2)
GROUP BY pm;
```

**Половина закреплённой базы никогда ничего не покупала.** Список «Моя база» не может быть одним
плоским перечнем: 58 и 50 строк соответственно — это партнёры без единой отгрузки, и показывать их
вперемешку с работающими бессмысленно.

Пул ничейных партнёров — 618 записей на карточке руководителя, из них отгружались **4**,
оформляли заказ — 8, имеют ИНН — 535, заведены в 2026 году — все.

```sql
SELECT COUNT(*) AS pool,
       SUM(EXISTS(SELECT 1 FROM shipments s WHERE s.user_id = u.id AND s.deleted_at IS NULL)) AS s_otgruzkami,
       SUM(EXISTS(SELECT 1 FROM orders o WHERE o.user_id = u.id)) AS s_zakazami,
       SUM(EXISTS(SELECT 1 FROM companies c WHERE c.user_id = u.id AND c.tax_id IS NOT NULL AND c.tax_id <> '')) AS s_inn
FROM users u
WHERE u.personal_manager_id = 6;
```

---

## 3. Отгрузки и план

| Месяц | Менеджер | Документов | Партнёров | Вал, тыс ₽ |
|---|---|---|---|---|
| Апрель | Сухов | 798 | 29 | 7 070 |
| Апрель | Курочкина | 394 | 33 | 4 295 |
| Май | Сухов | 621 | 27 | 3 867 |
| Май | Курочкина | 275 | 18 | 3 251 |
| Июнь | Сухов | 880 | 30 | 7 311 |
| Июнь | Курочкина | 305 | 30 | 3 291 |
| Июль | Сухов | 957 | 33 | 7 276 |
| Июль | Курочкина | 333 | 28 | 3 649 |
| Август | Сухов | 570 | 33 | 7 794 |
| Август | Курочкина | 202 | 22 | 3 673 |

```sql
SELECT DATE_FORMAT(s.erp_created_at, '%Y-%m') AS m,
       u.personal_manager_id AS pm,
       COUNT(*) AS docs,
       COUNT(DISTINCT s.user_id) AS clients,
       ROUND(SUM(s.total_amount) / 1000) AS tys
FROM shipments s
JOIN users u ON u.id = s.user_id
WHERE s.erp_created_at >= '2026-04-01' AND s.deleted_at IS NULL
GROUP BY m, pm
ORDER BY m DESC, tys DESC;
```

Отдел за август: 11 165 тыс ₽, 55 покупавших партнёров, 770 документов.

### Концентрация

Крупнейший партнёр августа: у Сухова 1 555 тыс ₽ (21 % его вала), у Курочкиной 2 089 тыс ₽
(**57 %** её вала). Показатель «доля крупнейшего партнёра» на экране «Здоровье базы» срабатывает
сразу и по существу.

```sql
SELECT u.personal_manager_id AS pm, u.name, ROUND(SUM(s.total_amount) / 1000) AS tys, COUNT(*) AS docs
FROM shipments s
JOIN users u ON u.id = s.user_id
WHERE s.deleted_at IS NULL
  AND s.erp_created_at >= '2026-08-01' AND s.erp_created_at < '2026-09-01'
  AND u.personal_manager_id IN (1, 2)
GROUP BY s.user_id
ORDER BY pm, tys DESC;
```

### Действующие планы

| Месяц | Отдел | Сухов | Курочкина |
|---|---|---|---|
| Июнь | 10 009 | 5 030 | 3 754 |
| Июль | 13 661 | 7 772 | 4 284 |
| Август | 11 780 | 6 678 | 4 960 |
| Сентябрь | 14 839 | 8 731 | 4 363 |
| Октябрь | 16 973 | 10 254 | 4 799 |
| Ноябрь | 19 221 | 11 830 | 5 279 |
| Декабрь | 21 593 | 13 463 | 5 807 |

Значения в тыс ₽.

```sql
SELECT period_month, target_type, target_id, ROUND(amount / 1000) AS tys
FROM crm_sales_plans
WHERE target_type <> 'client' AND period_month >= '2026-06-01'
ORDER BY period_month, target_type, target_id;
```

План Сухова удваивается за пять месяцев. Это плановый рост компании, распределённый сверху вниз,
а не производная от истории продаж. Всего в таблице 1 027 планов, из них 984 — на партнёра
(`target_type='client'`), 89 из них на август.

### Медиана продаж за рабочий день

За шесть месяцев (март–август): Сухов 231 тыс ₽, Курочкина 114 тыс ₽ при среднем 299 и 184 тыс ₽ —
разрыв между средним и медианой показывает, насколько распределение перекошено крупными днями
(максимум дня: 2 327 и 1 183 тыс ₽).

```sql
WITH d AS (
    SELECT u.personal_manager_id AS pm, DATE(s.erp_created_at) AS dd, SUM(s.total_amount) AS v
    FROM shipments s
    JOIN users u ON u.id = s.user_id
    WHERE s.deleted_at IS NULL
      AND s.erp_created_at >= '2026-03-01' AND s.erp_created_at < '2026-09-01'
      AND u.personal_manager_id IN (1, 2)
    GROUP BY pm, dd
), r AS (
    SELECT pm, v,
           ROW_NUMBER() OVER (PARTITION BY pm ORDER BY v) AS rn,
           COUNT(*) OVER (PARTITION BY pm) AS n
    FROM d
)
SELECT pm, ROUND(AVG(v) / 1000) AS mediana_tys
FROM r
WHERE rn IN (FLOOR((n + 1) / 2), CEIL((n + 1) / 2))
GROUP BY pm;
```

При 21 рабочем дне это даёт базу плана 4 857 и 2 391 тыс ₽ против действующих 6 678 и 4 960 тыс ₽.
**У Курочкиной формульная база вдвое ниже текущего плана.**

Дней с продажами за шесть месяцев: 135 и 130 — то есть отгрузки идут практически каждый рабочий
день, и медиана считается на полном ряде, а не на разреженном.

---

## 4. Новые партнёры

Первая в истории отгрузка по месяцам:

| Месяц | Партнёров | Средняя первая покупка, ₽ |
|---|---|---|
| Январь | 49 | 1 532 606 |
| Февраль | 25 | 216 315 |
| Март | 11 | 415 426 |
| Апрель | 13 | 140 410 |
| Май | 3 | 156 617 |
| Июнь | 8 | 452 266 |
| Июль | 3 | 96 897 |
| Август | 1 | 49 800 |

```sql
SELECT f.m, COUNT(*) AS novyh, ROUND(AVG(f.first_sum)) AS sr_pervaya
FROM (
    SELECT s.user_id,
           DATE_FORMAT(MIN(s.erp_created_at), '%Y-%m') AS m,
           SUM(s.total_amount) AS first_sum
    FROM shipments s
    WHERE s.deleted_at IS NULL
    GROUP BY s.user_id
) f
GROUP BY f.m ORDER BY f.m;
```

Январские 49 — артефакт начала истории, а не приток: это все партнёры, чья первая отгрузка попала
в первый месяц выгрузки. Реальный темп виден с мая: **1–8 новых партнёров в месяц на весь отдел**.

### Квалификация для квартальной премии

За квартал июнь–август первую отгрузку совершили 12 партнёров. Из них набрали за квартал:

| Порог | Квалифицированных |
|---|---|
| ≥ 100 000 ₽ | **4** |
| ≥ 300 000 ₽ | **1** |

```sql
SELECT COUNT(*) AS novyh_za_kvartal,
       SUM(kvartal_summa >= 100000) AS kval_100k,
       SUM(kvartal_summa >= 300000) AS kval_300k
FROM (
    SELECT f.user_id, SUM(s.total_amount) AS kvartal_summa
    FROM (
        SELECT user_id, MIN(erp_created_at) AS first_d
        FROM shipments WHERE deleted_at IS NULL
        GROUP BY user_id
        HAVING first_d >= '2026-06-01' AND first_d < '2026-09-01'
    ) f
    JOIN shipments s ON s.user_id = f.user_id AND s.deleted_at IS NULL
                    AND s.erp_created_at >= '2026-06-01' AND s.erp_created_at < '2026-09-01'
    GROUP BY f.user_id
) x;
```

Первая ступень премии требует 8 квалифицированных партнёров, верхняя — 22.

### Спящие партнёры

Среди закреплённых за двумя менеджерами:

| Давность последней отгрузки | Партнёров |
|---|---|
| Больше года | **0** |
| 6–12 месяцев | 9 |
| 3–6 месяцев | 19 |
| До 3 месяцев | 80 |

```sql
SELECT SUM(dni > 365) AS ne_pokupali_god,
       SUM(dni BETWEEN 181 AND 365) AS ot_6_do_12,
       SUM(dni BETWEEN 91 AND 180) AS ot_3_do_6,
       SUM(dni <= 90) AS svezhie
FROM (
    SELECT u.id, DATEDIFF(CURDATE(), MAX(s.erp_created_at)) AS dni
    FROM users u
    JOIN shipments s ON s.user_id = u.id AND s.deleted_at IS NULL
    WHERE u.personal_manager_id IN (1, 2)
    GROUP BY u.id
) x;
```

Определение нового партнёра через двенадцать месяцев отсутствия закупок на этих данных
**не выполняется ни для кого**. При окне в шесть месяцев кандидатов девять на весь отдел.

---

## 5. Просроченная задолженность

| Показатель | Сухов | Курочкина |
|---|---|---|
| Партнёров с просрочкой | 16 | 14 |
| Просроченных накладных | 198 | 70 |
| Сумма просрочки | 2 976 тыс ₽ | 1 344 тыс ₽ |
| Из неё старше 90 дней | 1 464 тыс ₽ | 60 тыс ₽ |
| Максимальная давность | 192 дня | 166 дней |

```sql
SELECT u.personal_manager_id AS pm,
       COUNT(DISTINCT s.user_id) AS klientov,
       COUNT(*) AS nakladnyh,
       ROUND(SUM(s.total_amount - s.paid_amount) / 1000) AS dolg_tys,
       ROUND(SUM(CASE WHEN DATEDIFF(CURDATE(), s.payment_due_date) > 90
                      THEN s.total_amount - s.paid_amount ELSE 0 END) / 1000) AS starshe_90dn,
       MAX(DATEDIFF(CURDATE(), s.payment_due_date)) AS max_dney
FROM shipments s
JOIN users u ON u.id = s.user_id
WHERE s.deleted_at IS NULL
  AND s.payment_due_date < CURDATE()
  AND (s.total_amount - s.paid_amount) > 1
  AND u.personal_manager_id IN (1, 2)
GROUP BY pm;
```

При ставке 0,05 % за календарный день понижающий показатель за полный месяц составляет
**44 640 ₽** у Сухова и **20 160 ₽** у Курочкиной. У Сухова половина базы начисления — долги
старше девяноста дней.

Мост накладных `payroll_invoice_settlements` за три месяца: 2 423 записи у Сухова (196 требуют
ручной разметки) и 832 у Курочкиной (44 на разметку).

```sql
SELECT personal_manager_id AS pm, COUNT(*) AS vsego,
       SUM(needs_review) AS na_razmetku,
       SUM(settled_on IS NOT NULL) AS s_datoy,
       SUM(delay_working_days >= 3) AS prosrocheno_3plus
FROM payroll_invoice_settlements
WHERE shipped_on >= '2026-06-01'
GROUP BY pm;
```

---

## 6. Фокус-товары

Признака фокусности в данных нет. Состав собственных марок назван заказчиком: **Pecado**
(`brands.id = 56`, 277 товаров), **OTOUCH** (`id = 90`, 25 товаров), **Sosuчки** (`id = 230`,
42 товара). Дочерних брендов ни у одной нет.

| Месяц | Pecado | OTOUCH | Sosuчки | Итого |
|---|---|---|---|---|
| Июнь | 84 423 ₽ | 10 310 ₽ | — | 94 733 ₽ |
| Июль | 141 893 ₽ | 16 058 ₽ | — | 157 951 ₽ |
| Август | 172 070 ₽ | 68 479 ₽ | — | 240 549 ₽ |

```sql
SELECT DATE_FORMAT(s.erp_created_at, '%Y-%m') AS m,
       si.brand_name_snapshot AS brand,
       ROUND(SUM(si.total)) AS rub,
       COUNT(DISTINCT s.user_id) AS klientov
FROM shipment_items si
JOIN shipments s ON s.id = si.shipment_id AND s.deleted_at IS NULL
WHERE si.brand_name_snapshot IN ('Pecado', 'OTOUCH', 'Sosuчки')
  AND s.erp_created_at >= '2026-06-01'
GROUP BY m, brand ORDER BY m, rub DESC;
```

**Sosuчки не продавались ни разу за всю историю отгрузок** — ноль строк в `shipment_items`
при 42 позициях в каталоге, из которых 29 видимы на витрине. Это новая марка, которую предстоит
вывести на полку партнёров, и стартовое значение показателя по ней — ноль.

Отгрузки перечня растут: 95 → 158 → 241 тыс ₽ за три месяца, в 2,5 раза.

Два способа определить бренд — по снимку в строке отгрузки (`brand_name_snapshot`) и по
`products.brand_id` — дают **одинаковый** результат, что снимает вопрос, какой из них первичен.

```sql
SELECT DATE_FORMAT(s.erp_created_at, '%Y-%m') AS m,
       ROUND(SUM(CASE WHEN si.brand_name_snapshot = 'Pecado' THEN si.total ELSE 0 END) / 1000) AS po_snimku,
       ROUND(SUM(CASE WHEN b.name = 'Pecado' THEN si.total ELSE 0 END) / 1000) AS po_brand_id
FROM shipment_items si
JOIN shipments s ON s.id = si.shipment_id AND s.deleted_at IS NULL
LEFT JOIN products p ON p.id = si.product_id
LEFT JOIN brands b ON b.id = p.brand_id
WHERE s.erp_created_at >= '2026-06-01'
GROUP BY m ORDER BY m;
```

В августе по всему перечню: Сухов 193 293 ₽ (17 партнёров), Курочкина 47 183 ₽ (9 партнёров).
При ставке 1 % это **1 933 и 472 ₽** за месяц.

Потенциал охвата: из партнёров, отгружавшихся за последние три месяца, позиции перечня берут
23 из 46 у Сухова и 11 из 40 у Курочкиной — то есть список «кому предложить» содержит 23 и 29 строк.

```sql
SELECT u.personal_manager_id AS pm,
       COUNT(DISTINCT s.user_id) AS aktivnyh_3mes,
       COUNT(DISTINCT CASE WHEN si.brand_name_snapshot IN ('Pecado','OTOUCH','Sosuчки')
                           THEN s.user_id END) AS berut_focus
FROM shipments s
JOIN users u ON u.id = s.user_id
JOIN shipment_items si ON si.shipment_id = s.id
WHERE s.deleted_at IS NULL AND s.erp_created_at >= '2026-06-01'
  AND u.personal_manager_id IN (1, 2)
GROUP BY pm;
```

Всего брендов в отгрузках — 106, товаров в каталоге — 7 240, строк отгрузок — 45 946.

---

## 7. Возвраты

Возвраты в валовых показателях практически отсутствуют: заявок в `returns` — 4, в регистре
взаиморасчётов документов `goods_return` за три месяца — 5 на 11 тыс ₽. Отрицательных сумм в
отгрузках нет.

```sql
SELECT document_kind, nature, COUNT(*) AS cnt, ROUND(SUM(amount_rub) / 1000) AS tys
FROM settlement_entries
WHERE date >= '2026-06-01'
GROUP BY document_kind, nature ORDER BY cnt DESC;
```

Практического влияния на показатели возвраты не оказывают; вопрос их учёта — нормативный,
а не расчётный.

---

## 8. Ценовая дисциплина

Ручные скидки применяются регулярно: за три месяца 835 строк в 245 документах у 34 партнёров,
максимальная скидка — 98 %.

```sql
SELECT COUNT(*) AS strok, COUNT(DISTINCT s.id) AS dokumentov,
       COUNT(DISTINCT s.user_id) AS klientov,
       ROUND(MAX(si.manual_discount_percent), 1) AS max_pct
FROM shipment_items si
JOIN shipments s ON s.id = si.shipment_id AND s.deleted_at IS NULL
WHERE si.manual_discount_percent > 0 AND s.erp_created_at >= '2026-06-01';
```

При этом скидка приходит из 1С уже в составе отгрузки: в заказах сайта поля ручной скидки нет,
таблиц согласования нет. Контроль возможен только постфактум.

---

## 9. Состояние расчётов

| Менеджер | Месяц | Статус | Итог |
|---|---|---|---|
| Сухов | Июль | draft | 120 325 ₽ |
| Курочкина | Июль | draft | 116 577 ₽ |
| Сухов | Август | **paid** | 135 968 ₽ |
| Курочкина | Август | **paid** | 114 826 ₽ |
| Сухов | Сентябрь | draft | 70 000 ₽ |
| Курочкина | Сентябрь | draft | 70 000 ₽ |

```sql
SELECT personal_manager_id, period_month, status, ROUND(total) AS itogo
FROM payroll_calculations ORDER BY period_month, personal_manager_id;
```

Август закрыт и выплачен: переход на новую схему обязан сохранять читаемость замороженных снимков.

### Отсутствия

В `manager_absences` **одна запись**:

| Работник | Тип | Период | Замещающий |
|---|---|---|---|
| Курочкина | отпуск | 17–30 августа 2026 | Сухов |

Это 10 рабочих дней из 21 в августе. Табель заведён при запуске раздела отсутствий; за более
ранние месяцы записей нет, и расчёт считает их полностью отработанными.

---

## Открытые вопросы

Все следствия из этого приложения собраны в [10-otkrytye-voprosy.md](10-otkrytye-voprosy.md).

## История редакций

| Дата | Изменение |
|---|---|
| 08.09.2026 | Первая редакция: замеры по копии боевой базы |
