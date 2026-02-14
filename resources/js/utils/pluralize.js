/**
 * Склонение числительных для русского языка.
 *
 * @param {number} count — число
 * @param {string} one — форма единственного числа (1 товар)
 * @param {string} few — форма для 2-4 (2 товара)
 * @param {string} many — форма для 5+ (5 товаров)
 * @returns {string}
 *
 * Пример: pluralize(5, 'товар', 'товара', 'товаров') → 'товаров'
 */
export function pluralize(count, one, few, many) {
    const n = Math.abs(count) % 100;
    const n1 = n % 10;
    if (n > 10 && n < 20) return many;
    if (n1 > 1 && n1 < 5) return few;
    if (n1 === 1) return one;
    return many;
}
