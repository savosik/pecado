/**
 * Форматирование величин отправки.
 *
 * Вес везде хранится и передаётся в граммах — так его требует ApiShip, — а склад
 * читает килограммы. Разводить эти две единицы по компонентам нельзя: один раз
 * перепутанный множитель даёт тариф, который вскроется только в счёте от перевозчика.
 */

/** Граммы → «12,5 кг». Меньше килограмма показываем в граммах. */
export function formatWeight(grams) {
    const value = Number(grams) || 0;

    if (value === 0) {
        return '—';
    }

    if (value < 1000) {
        return `${value} г`;
    }

    return `${(value / 1000).toLocaleString('ru-RU', { maximumFractionDigits: 2 })} кг`;
}

/** Рубли с разделителями разрядов. null/undefined — прочерк, а не «0 ₽». */
export function formatMoney(amount) {
    if (amount === null || amount === undefined || amount === '') {
        return '—';
    }

    return `${Number(amount).toLocaleString('ru-RU', { maximumFractionDigits: 2 })} ₽`;
}

/** «1–3 дня» из пары значений тарифа. */
export function formatDays(min, max) {
    if (!min && !max) {
        return '—';
    }

    if (!max || min === max) {
        return `${min || max} дн.`;
    }

    return `${min}–${max} дн.`;
}
