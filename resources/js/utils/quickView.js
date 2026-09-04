/**
 * Открытие QuickView в «уценённом» режиме.
 *
 * В разделе «Уценка» клиент пришёл за партией некондиции, а не за товаром:
 * модалка открывается сразу на вкладке «Уценка» и подсказывает тостом, что
 * выбрать нужно конкретный экземпляр.
 */
export const DEFECT_QUICK_VIEW_TAB = 'defects';
export const DEFECT_QUICK_VIEW_NOTICE = 'Выберите вариант уценённого товара';

export const DEFECT_QUICK_VIEW_OPTIONS = {
    tab: DEFECT_QUICK_VIEW_TAB,
    notice: DEFECT_QUICK_VIEW_NOTICE,
};

/**
 * @param {string} slug slug товара
 */
export function openDefectQuickView(slug) {
    if (!slug) return;

    if (typeof window !== 'undefined' && window.__openProductQuickView) {
        window.__openProductQuickView(slug, DEFECT_QUICK_VIEW_OPTIONS);
        return;
    }

    if (typeof window !== 'undefined') {
        window.location.href = `/products/${encodeURIComponent(slug)}`;
    }
}
