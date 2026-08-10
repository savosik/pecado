import { useEffect, useState } from 'react';
import axios from 'axios';

/**
 * Справочники диалога задачи: исполнители, статусы, приоритеты.
 *
 * Кэш на уровне модуля, а не состояния компонента: диалог открывается из списка задач,
 * из карточки партнёра и из карточек админки — без кэша каждый его показ дёргал бы
 * один и тот же неизменный справочник заново.
 */
let cache = null;
let inflight = null;

export function primeTaskOptions(options) {
    if (options) {
        cache = options;
    }
}

export function useTaskOptions(enabled = true) {
    const [options, setOptions] = useState(cache);

    useEffect(() => {
        if (!enabled || options) {
            return;
        }

        let alive = true;
        inflight = inflight || axios.get('/crm/tasks/options').then((res) => {
            cache = res.data;
            inflight = null;
            return res.data;
        }).catch(() => {
            inflight = null;
            return null;
        });

        inflight.then((data) => {
            if (alive && data) {
                setOptions(data);
            }
        });

        return () => { alive = false; };
    }, [enabled, options]);

    return options;
}
