/**
 * Форматирование цены с разделителем тысяч и знаком валюты.
 *
 * @param {number|string|null|undefined} amount — сумма
 * @param {{ symbol?: string, code?: string }} [currency] — валюта
 * @returns {string} — отформатированная цена, например «12 345,00 ₽»
 */
export function formatPrice(amount, currency) {
    if (amount === null || amount === undefined || amount === '') {
        return '';
    }

    const num = Number(amount);
    if (Number.isNaN(num)) {
        return '';
    }

    const symbol = currency?.symbol ?? '₽';

    const formatted = new Intl.NumberFormat('ru-RU', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(num);

    return `${formatted}\u00A0${symbol}`;
}
