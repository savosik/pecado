/**
 * Возвращает Chakra-пропсы для рамки-индикатора счётчика корзины.
 *
 * Цвет зависит от того, как заказанный объём раскладывается на
 * остаток на складе и предзаказ:
 *   - idle    — qty=0, серая;
 *   - instock — всё помещается в наличный остаток, зелёная;
 *   - preorder — весь объём идёт в предзаказ, оранжевая;
 *   - mixed   — часть со склада, часть в предзаказе, градиент.
 *
 * Применяется к Box с заранее заданными borderWidth + rounded.
 */

const GRADIENT_BG =
    'linear-gradient(var(--chakra-colors-bg), var(--chakra-colors-bg)) padding-box, ' +
    'linear-gradient(90deg, var(--chakra-colors-green-500), var(--chakra-colors-orange-400)) border-box';

export function cartFrameState(instockQty, preorderQty) {
    const total = Number(instockQty || 0) + Number(preorderQty || 0);
    if (total <= 0) return 'idle';
    if ((preorderQty || 0) <= 0) return 'instock';
    if ((instockQty || 0) <= 0) return 'preorder';
    return 'mixed';
}

export function cartFrameProps(instockQty, preorderQty) {
    const state = cartFrameState(instockQty, preorderQty);
    switch (state) {
        case 'instock':
            return { borderColor: 'green.500' };
        case 'preorder':
            return { borderColor: 'orange.400' };
        case 'mixed':
            return { borderColor: 'transparent', style: { background: GRADIENT_BG } };
        case 'idle':
        default:
            // Прозрачный 2px-бордер сохраняет layout, а 1px-рамка рисуется поверх через inset.
            return {
                borderColor: 'transparent',
                boxShadow: 'inset 0 0 0 1px var(--chakra-colors-gray-300)',
            };
    }
}

/**
 * Pропсы для подсветки строки корзины при hover — в цветах состояния counter.
 * По умолчанию строка прозрачная; tint появляется только когда наведён курсор.
 * Для mixed используется CSS-класс с linear-gradient на :hover.
 */
export function cartRowTint(instockQty, preorderQty) {
    const state = cartFrameState(instockQty, preorderQty);
    switch (state) {
        case 'instock':
            return {
                _hover: { bg: 'green.50' },
                _dark: { _hover: { bg: 'green.900/20' } },
            };
        case 'preorder':
            return {
                _hover: { bg: 'orange.50' },
                _dark: { _hover: { bg: 'orange.900/20' } },
            };
        case 'mixed':
            return {
                className: 'cart-row-mixed',
            };
        case 'idle':
        default:
            return {
                _hover: { bg: 'gray.50' },
                _dark: { _hover: { bg: 'gray.700/40' } },
            };
    }
}

export function cartFrameTooltip(instockQty, preorderQty) {
    const inS = Math.max(0, Number(instockQty || 0));
    const pre = Math.max(0, Number(preorderQty || 0));
    if (inS + pre <= 0) return null;
    if (pre <= 0) return `${inS} шт. со склада`;
    if (inS <= 0) return `${pre} шт. в предзаказе`;
    return `${inS} со склада + ${pre} в предзаказе`;
}

export function splitQty(totalQty, stockQuantity) {
    const total = Math.max(0, Number(totalQty || 0));
    const stock = Math.max(0, Number(stockQuantity || 0));
    const instock = Math.min(total, stock);
    const preorder = total - instock;
    return { instock, preorder };
}
