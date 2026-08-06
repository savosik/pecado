import { router } from '@inertiajs/react';

/**
 * Механика фильтров журналов CRM: применение, сброс и выгрузка XLSX.
 *
 * Вынесено из DocumentList, чтобы журнал платежей получил ровно то же поведение
 * URL и выгрузки. У платежей нет ни статусов 1С, ни позиций, ни фильтра по
 * товару — параметризовать сам DocumentList значило бы рисковать двумя
 * работающими журналами ради третьего.
 *
 * @param {string} routeName — 'crm.orders' | 'crm.shipments' | 'crm.payments'
 * @param {object} filters — снимок текущего отбора с сервера
 */
export function useDocumentFilters(routeName, filters) {
    // Пустые значения выкидываем: иначе каждый сброшенный мультивыбор оставлял бы
    // в адресе висячий `?partner_ids=`, и ссылка на отбор переставала читаться.
    const activeParams = (patch = {}) => Object.entries({ ...filters, ...patch }).reduce((acc, [key, value]) => {
        const empty = Array.isArray(value)
            ? value.length === 0
            : value === null || value === undefined || value === '';

        if (!empty) acc[key] = value;

        return acc;
    }, {});

    const apply = (patch) => {
        router.get(route(`${routeName}.index`), activeParams(patch), {
            preserveState: true,
            replace: true,
        });
    };

    // Выгрузка уходит обычным переходом, а не router.visit: Inertia ждёт JSON,
    // а сервер отдаёт файл — ответ она просто не поймёт.
    const exportXlsx = () => {
        const query = new URLSearchParams();

        Object.entries(activeParams()).forEach(([key, value]) => {
            if (Array.isArray(value)) {
                value.forEach((item) => query.append(`${key}[]`, item));
            } else {
                query.append(key, value);
            }
        });

        window.location.href = `${route(`${routeName}.export`)}?${query.toString()}`;
    };

    const reset = () => {
        router.get(route(`${routeName}.index`), { per_page: filters.per_page }, {
            preserveState: false,
            replace: true,
        });
    };

    const selected = (key) => filters[key] ?? [];

    return { activeParams, apply, exportXlsx, reset, selected };
}
