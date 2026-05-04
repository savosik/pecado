/**
 * Простой стор «включены ли UI-эффекты» (полёт в корзину + конфетти).
 *
 * Хранится в localStorage, по умолчанию выключен.
 * Без observer'ов и глобальных listener'ов: подписка явная через subscribe(cb).
 */
const KEY = 'pecado:fx:enabled';
const EVT = 'pecado-fx:changed';

export function isEffectsEnabled() {
    try {
        return localStorage.getItem(KEY) === '1';
    } catch {
        return false;
    }
}

export function setEffectsEnabled(value) {
    try {
        localStorage.setItem(KEY, value ? '1' : '0');
    } catch { /* noop */ }
    window.dispatchEvent(new CustomEvent(EVT, { detail: { value: !!value } }));
}

/**
 * Подписаться на изменения. Вызовет cb(boolean) при каждом setEffectsEnabled,
 * а также при изменении из другой вкладки. Возвращает функцию отписки.
 */
export function subscribeEffects(cb) {
    const onCustom = (e) => cb(!!e.detail?.value);
    const onStorage = (e) => {
        if (e.key === KEY) cb(e.newValue === '1');
    };
    window.addEventListener(EVT, onCustom);
    window.addEventListener('storage', onStorage);
    return () => {
        window.removeEventListener(EVT, onCustom);
        window.removeEventListener('storage', onStorage);
    };
}
