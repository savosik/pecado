/**
 * Единый timeline заказа: история статусов + журнал изменений в одном списке,
 * новейшие сверху.
 *
 * Сортируем по `created_at_iso` (машинное поле с секундами), а не по
 * человекочитаемому `created_at` формата `d.m.Y H:i`: у него теряются секунды,
 * и события одной минуты вставали в порядке склейки массивов — сначала все
 * статусы, потом все изменения.
 */

/**
 * Разбирает дату записи в миллисекунды.
 *
 * @param {{created_at_iso?: string, created_at?: string}} entry
 * @returns {number}
 */
function parseTimestamp(entry) {
    if (entry.created_at_iso) {
        const iso = Date.parse(entry.created_at_iso);
        if (!Number.isNaN(iso)) {
            return iso;
        }
    }

    // Fallback для записей без ISO-поля: `d.m.Y H:i` Date.parse не понимает.
    const m = /^(\d{2})\.(\d{2})\.(\d{4})(?:\s+(\d{2}):(\d{2}))?/.exec(entry.created_at || '');
    if (!m) {
        return 0;
    }

    const [, day, month, year, hour = '00', minute = '00'] = m;

    return new Date(Number(year), Number(month) - 1, Number(day), Number(hour), Number(minute)).getTime();
}

/**
 * Объединяет историю статусов и журнал изменений в один отсортированный массив.
 *
 * @param {Array<Object>} statusHistories
 * @param {Array<Object>} changeLogs
 * @returns {Array<{id: string, type: string, created_at: string, created_at_human: string, data: Object}>}
 */
export function buildOrderTimeline(statusHistories = [], changeLogs = []) {
    const entries = [];

    for (const h of statusHistories) {
        entries.push({
            id: `status-${h.id}`,
            type: 'status_changed',
            created_at: h.created_at,
            created_at_iso: h.created_at_iso,
            created_at_human: h.created_at_human,
            source: 'status',
            sourceId: h.id,
            ts: parseTimestamp(h),
            data: h,
        });
    }

    for (const c of changeLogs) {
        entries.push({
            id: `change-${c.id}`,
            type: c.type,
            created_at: c.created_at,
            created_at_iso: c.created_at_iso,
            created_at_human: c.created_at_human,
            source: 'change',
            sourceId: c.id,
            ts: parseTimestamp(c),
            data: c,
        });
    }

    // Новейшие сверху; при совпадении секунды разводим записи одной таблицы по id.
    entries.sort((a, b) => {
        if (b.ts !== a.ts) {
            return b.ts - a.ts;
        }

        return a.source === b.source ? b.sourceId - a.sourceId : 0;
    });

    return entries;
}
