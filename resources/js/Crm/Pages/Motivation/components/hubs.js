/**
 * Разделы «Мотивации» после упрощения (решение заказчика 11.09.2026):
 * у работника три пункта меню, у руководителя четыре, внутри — вкладки.
 * Маршруты страниц не менялись: вкладка — это ссылка на прежний экран.
 */
export const HUBS = {
    clients: {
        title: 'Мои клиенты',
        tabs: [
            { key: 'base', label: 'База', path: '/crm/motivation/base' },
            { key: 'rhythm', label: 'Выпали из ритма', path: '/crm/motivation/rhythm' },
            { key: 'debts', label: 'Долги', path: '/crm/motivation/debts' },
            { key: 'wake', label: 'Разбудить', path: '/crm/motivation/wake' },
            { key: 'new', label: 'Новые', path: '/crm/motivation/new-partners' },
            { key: 'pool', label: 'Свободные', path: '/crm/motivation/pool' },
            { key: 'focus', label: 'Фокус-товары', path: '/crm/motivation/focus' },
        ],
    },
    payslip: {
        title: 'Расчётный лист',
        tabs: [
            { key: 'payslip', label: 'Лист', path: '/crm/motivation/payslip' },
            { key: 'plan', label: 'Откуда план', path: '/crm/motivation/plan' },
            { key: 'quarter', label: 'Премия отдела', path: '/crm/motivation/quarter' },
            { key: 'rates', label: 'Ставки', path: '/crm/motivation/settings', permission: 'view-only' },
        ],
    },
    rules: {
        title: 'Параметры и планы',
        tabs: [
            { key: 'settings', label: 'Параметры', path: '/crm/motivation/settings' },
            { key: 'plans', label: 'Планы на квартал', path: '/crm/motivation/plans' },
        ],
    },
    ledger: {
        title: 'Ведомость',
        tabs: [
            { key: 'team', label: 'Сводка', path: '/crm/motivation/team' },
            { key: 'approval', label: 'К утверждению', path: '/crm/motivation/approval' },
            { key: 'quarter', label: 'Квартальная премия', path: '/crm/motivation/quarter/admin' },
            { key: 'forecast', label: 'Прогноз фонда', path: '/crm/motivation/forecast' },
        ],
    },
    department: {
        title: 'Клиенты отдела',
        tabs: [
            { key: 'pool', label: 'Пул и раздача', path: '/crm/motivation/pool/admin' },
            { key: 'focus', label: 'Фокус-перечень', path: '/crm/motivation/focus-list' },
            { key: 'health', label: 'Здоровье базы', path: '/crm/motivation/health' },
        ],
    },
    debts: {
        title: 'Долги',
        tabs: [
            { key: 'exclusions', label: 'Исключения', path: '/crm/motivation/debt-exclusions' },
            { key: 'invoices', label: 'Очередь разметки', path: '/crm/motivation/invoices' },
            { key: 'discounts', label: 'Журнал скидок', path: '/crm/motivation/discounts' },
        ],
    },
};

/** Хлебные крошки раздела: «Мотивация / <раздел> / <вкладка>». */
export function hubBreadcrumbs(hubKey, tabKey) {
    const hub = HUBS[hubKey];
    const tab = hub.tabs.find((t) => t.key === tabKey);
    return [
        { label: 'Мотивация', href: '/crm/motivation' },
        { label: hub.title, href: hub.tabs[0].path },
        ...(tab ? [{ label: tab.label }] : []),
    ];
}
