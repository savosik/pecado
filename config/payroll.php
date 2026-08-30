<?php

use App\Services\Payroll\Components\ExtraIncomeComponent;
use App\Services\Payroll\Components\Kpi\ActiveClientsMultiplier;
use App\Services\Payroll\Components\Kpi\DisciplinePenaltyFactor;
use App\Services\Payroll\Components\Kpi\RevenueFactor;
use App\Services\Payroll\Components\KpiBonusComponent;
use App\Services\Payroll\Components\ManualCorrectionComponent;
use App\Services\Payroll\Components\NewClientsBonusComponent;
use App\Services\Payroll\Components\SalaryComponent;

/*
 * Зарплата менеджеров отдела продаж (эпик sal-00).
 *
 * Каталог компонентов дохода и умолчания схемы. Правило домена: всё, что в
 * Excel РОПа было числом в ячейке, — данные (схема в БД, отклонения по
 * менеджеру и месяцу); всё, что было формулой, — класс-компонент отсюда.
 * Новая «морковка» = новый класс + строка в схеме; редактора формул нет намеренно.
 */
return [
    /*
     * Компоненты верхнего уровня: каждый даёт рубли в итог (kind = amount).
     * Ключ, которого здесь нет, схема применить не может.
     */
    'components' => [
        'salary' => SalaryComponent::class,
        'kpi_bonus' => KpiBonusComponent::class,
        'extra_income' => ExtraIncomeComponent::class,
        'new_clients_bonus' => NewClientsBonusComponent::class,
        'manual_correction' => ManualCorrectionComponent::class,
    ],

    /*
     * Факторы KPI-премии в порядке применения: выручка → штраф → множитель.
     * Их параметры живут внутри параметров `kpi_bonus` под своим ключом.
     */
    'kpi_factors' => [
        'revenue' => RevenueFactor::class,
        'discipline_penalty' => DisciplinePenaltyFactor::class,
        'active_clients' => ActiveClientsMultiplier::class,
    ],

    /*
     * Схема v1 — материализуется в `payroll_schemes` при первом обращении.
     * Цифры — из действующего Excel РОПа (август 2026).
     */
    'default_scheme' => [
        'code' => 'sales',
        'title' => 'Отдел продаж — 2026',
        'effective_from' => '2026-01-01',
        'components' => [
            ['key' => 'salary', 'enabled' => true, 'defaults' => ['amount' => 70000]],
            ['key' => 'kpi_bonus', 'enabled' => true, 'defaults' => [
                'base' => 85000,
                'cap' => 2.0,
                'discipline_penalty' => ['tiers' => [
                    ['from_days' => 3, 'to_days' => 7, 'coefficient' => 1.5],
                    ['from_days' => 8, 'to_days' => null, 'coefficient' => 3.0],
                ]],
                'active_clients' => ['ladder' => [
                    ['from_share' => 0, 'multiplier' => 0.8],
                    ['from_share' => 0.8, 'multiplier' => 0.9],
                    ['from_share' => 0.9, 'multiplier' => 1.0],
                    ['from_share' => 1.1, 'multiplier' => 1.05],
                    ['from_share' => 1.25, 'multiplier' => 1.1],
                ]],
            ]],
            ['key' => 'extra_income', 'enabled' => true, 'defaults' => []],
            // Выключен: цифр заказчик не назвал, включает РОП новой версией схемы.
            ['key' => 'new_clients_bonus', 'enabled' => false, 'defaults' => [
                'bonus' => 2000,
                'min_first_amount' => 10000,
                'repeat_within_days' => 60,
                'monthly_cap' => 20000,
                'returned_weight' => 0.5,
                'returned_after_days' => 90,
            ]],
            ['key' => 'manual_correction', 'enabled' => true, 'defaults' => []],
        ],
    ],

    /*
     * «Постоянное» отклонение менеджера хранится под этой датой, а не под NULL:
     * MySQL считает NULL-ы в unique-индексе различными (прецедент — target_id = 0
     * у плана отдела в crm_sales_plans).
     */
    'permanent_month' => '1970-01-01',

    'invoices' => [
        // Сколько месяцев реализаций пересобирать ночным ребилдом моста
        // «накладная → дата закрытия» (InvoiceNumberNormalizer знает формат номера сам).
        'rebuild_months' => (int) env('PAYROLL_INVOICES_REBUILD_MONTHS', 6),
        // Задержка оплаты до этого числа рабочих дней включительно — не просрочка.
        'grace_working_days' => 2,
    ],

    'forecast' => [
        /*
         * Горизонт риска: насколько давно просроченная накладная ещё считается
         * той, что может закрыться в этом месяце.
         *
         * Штраф начисляется в месяц прихода денег, поэтому «худший исход» для
         * премии — когда долги приходят разом. Но зависшая дебиторка (у Сухова
         * 30 накладных на 1,05 млн со сроком от марта) в этом месяце не придёт,
         * а её штраф ×3 обнулял премию и делал нижнюю границу прогноза
         * бессмысленно страшной. Такие долги в сценарии не подставляем.
         */
        'risk_overdue_days' => (int) env('PAYROLL_FORECAST_RISK_DAYS', 30),
    ],

    'recalculate' => [
        // Окно склейки событий: несколько отгрузок подряд — один пересчёт.
        'debounce_seconds' => (int) env('PAYROLL_DEBOUNCE_SECONDS', 60),
        // Черновик старше этого — пересчитывается страховочным расписанием.
        'stale_after_minutes' => 10,
    ],

    // Как часто страница «Моя зарплата» опрашивает сервер, секунд.
    'poll_seconds' => (int) env('PAYROLL_POLL_SECONDS', 60),
];
