/**
 * Склонение слова «товар» по числу.
 *
 * @param {number} n
 * @returns {string}
 */
export function pluralGoods(n) {
    const abs = Math.abs(n) % 100;
    const lastDigit = abs % 10;
    if (abs > 10 && abs < 20) return 'товаров';
    if (lastDigit > 1 && lastDigit < 5) return 'товара';
    if (lastDigit === 1) return 'товар';
    return 'товаров';
}
