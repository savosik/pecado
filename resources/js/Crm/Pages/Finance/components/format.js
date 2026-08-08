/**
 * Форматирование денег раздела «Финансы».
 *
 * Все суммы раздела уже сведены в рубли на сервере, поэтому валюта здесь одна:
 * исходная валюта документа приезжает отдельным полем строки и показывается
 * только в детализации.
 */
export const formatRub = (value) => `${Number(value || 0).toLocaleString('ru-RU', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
})} ₽`;

/**
 * Компактная сумма для плиток и осей графика: 1 240 000 ₽ читается хуже, чем 1,24 млн ₽.
 */
export const formatCompact = (value) => {
    const amount = Number(value || 0);
    const abs = Math.abs(amount);

    if (abs >= 1_000_000) return `${(amount / 1_000_000).toLocaleString('ru-RU', { maximumFractionDigits: 2 })} млн ₽`;
    if (abs >= 1_000) return `${(amount / 1_000).toLocaleString('ru-RU', { maximumFractionDigits: 0 })} тыс ₽`;

    return formatRub(amount);
};

/**
 * «через 5 дн.» / «просрочено на 12 дн.» — одной строкой, чтобы в таблице
 * не заводить две колонки под взаимоисключающие значения.
 */
export const dueHint = (row) => {
    if (row.days_overdue > 0) return `просрочено на ${row.days_overdue} дн.`;
    if (row.days_left === null || row.days_left === undefined) return 'срок не определён';
    if (row.days_left === 0) return 'сегодня';

    return `через ${row.days_left} дн.`;
};
