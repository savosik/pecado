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

    return `${formatted} ${symbol}`;
}

/**
 * Форматирование суммы без знака валюты: целые — без копеек («2 395»),
 * дробные — всегда с двумя знаками («4 071,50», а не «4 071,5»).
 *
 * @param {number|string|null|undefined} amount — сумма
 * @returns {string}
 */
export function formatMoney(amount) {
    const num = Number(amount);
    if (Number.isNaN(num)) {
        return '';
    }

    return new Intl.NumberFormat('ru-RU', {
        minimumFractionDigits: Number.isInteger(num) ? 0 : 2,
        maximumFractionDigits: 2,
    }).format(num);
}
