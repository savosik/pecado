/**
 * Запускает анимацию полёта мини-превью товара к иконке корзины.
 *
 * Цель находим по `aria-label="Корзина"` (есть и на десктопной иконке,
 * и на пункте мобильной нав-бары). Берём первую *видимую* — на разной
 * вёрстке активна то одна, то другая.
 *
 * Без observer'ов, без data-атрибутов, без window-событий.
 *
 * @param {HTMLElement} sourceEl - откуда летит
 * @param {string|null} imageUrl - URL картинки превью; иначе бордовый кружок
 */
export function flyToCart(sourceEl, imageUrl = null) {
    if (typeof document === 'undefined' || !sourceEl) return;

    const candidates = document.querySelectorAll('[aria-label="Корзина"]');
    let target = null;
    for (const el of candidates) {
        if (el.offsetParent !== null) { target = el; break; }
    }
    if (!target) return;

    const sRect = sourceEl.getBoundingClientRect();
    const tRect = target.getBoundingClientRect();
    if (!sRect.width || !tRect.width) return;

    const SIZE = 56;
    const startX = sRect.left + sRect.width / 2 - SIZE / 2;
    const startY = sRect.top + sRect.height / 2 - SIZE / 2;
    const endX = tRect.left + tRect.width / 2 - SIZE / 2;
    const endY = tRect.top + tRect.height / 2 - SIZE / 2;

    const ghost = document.createElement('div');
    ghost.style.cssText = `
        position: fixed;
        left: ${startX}px;
        top: ${startY}px;
        width: ${SIZE}px;
        height: ${SIZE}px;
        border-radius: 12px;
        overflow: hidden;
        z-index: 9999;
        pointer-events: none;
        background: #e5e7eb;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
    `;

    if (imageUrl) {
        ghost.style.backgroundImage = `url("${imageUrl}")`;
        ghost.style.backgroundSize = 'cover';
        ghost.style.backgroundPosition = 'center';
    }

    document.body.appendChild(ghost);

    const dx = endX - startX;
    const dy = endY - startY;
    const arc = -Math.min(140, Math.abs(dy) * 0.5 + 40);

    const anim = ghost.animate(
        [
            { transform: 'translate(0, 0) scale(1)', opacity: 1 },
            { transform: `translate(${dx * 0.5}px, ${dy * 0.5 + arc}px) scale(0.85)`, opacity: 0.95, offset: 0.6 },
            { transform: `translate(${dx}px, ${dy}px) scale(0.25)`, opacity: 0 },
        ],
        { duration: 650, easing: 'cubic-bezier(0.55, 0, 0.55, 1)' }
    );

    const cleanup = () => ghost.remove();
    anim.onfinish = cleanup;
    anim.oncancel = cleanup;
}
