import { useCallback, useEffect, useRef } from 'react';
import { router } from '@inertiajs/react';

const STORAGE_PREFIX = 'crm.scope.';
const MINE = 'mine';
const DEPARTMENT = 'department';

/**
 * Липкий разрез «только мои / весь отдел», запоминаемый отдельно по разделам.
 *
 * Три слоя, и порядок между ними важен:
 *   сервер — единственная граница корректности (отсутствие параметра = «мои»,
 *            `department` без права схлопывается молча);
 *   URL    — источник истины для текущей страницы: ссылка шарится, «назад» работает;
 *   localStorage — липкость, то есть просто память о последнем выборе.
 *
 * Память не может расширить видимость: она лишь подставляет параметр, который
 * сервер всё равно проверит. Поэтому ключ безопасно чистить и безопасно игнорировать.
 *
 * @param {string} section     ключ раздела (`clients`, `tasks`, `documents`…)
 * @param {string} serverScope разрез, который вернул сервер после всех проверок
 * @param {boolean} available  доступен ли расфокус (есть ли право видеть отдел)
 */
export function useCrmScope(section, serverScope, available) {
    const storageKey = `${STORAGE_PREFIX}${section}`;
    // Подставлять запомненный разрез можно ровно один раз за монтирование:
    // иначе ответ сервера, схлопнувший scope, тут же вызвал бы новый запрос.
    const restored = useRef(false);

    const isMine = serverScope !== DEPARTMENT;

    const apply = useCallback((next) => {
        try {
            window.localStorage.setItem(storageKey, next);
        } catch {
            // Приватный режим и переполненное хранилище не должны ломать переключатель:
            // без памяти он просто перестаёт быть липким.
        }

        const url = new URL(window.location.href);
        url.searchParams.set('scope', next);

        router.get(url.pathname + url.search, {}, { preserveState: true, replace: true });
    }, [storageKey]);

    useEffect(() => {
        if (restored.current || ! available) {
            return;
        }

        restored.current = true;

        // Явный параметр в адресе всегда сильнее памяти: пришли по ссылке —
        // видим то, что в ссылке, а не то, что смотрели в прошлый раз.
        if (new URL(window.location.href).searchParams.has('scope')) {
            return;
        }

        let remembered = null;

        try {
            remembered = window.localStorage.getItem(storageKey);
        } catch {
            remembered = null;
        }

        if (remembered === DEPARTMENT && serverScope !== DEPARTMENT) {
            apply(DEPARTMENT);
        }
    }, [apply, available, serverScope, storageKey]);

    // Право могли отобрать между сессиями — тогда память о расфокусе врёт.
    useEffect(() => {
        if (available) {
            return;
        }

        try {
            window.localStorage.removeItem(storageKey);
        } catch {
            // см. выше
        }
    }, [available, storageKey]);

    return {
        isMine,
        available,
        toggle: useCallback(() => apply(isMine ? DEPARTMENT : MINE), [apply, isMine]),
    };
}

export default useCrmScope;
