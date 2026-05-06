import { useEffect, useRef } from 'react';
import axios from 'axios';

/**
 * Поллинг статуса генерации одного пресета.
 *
 * Запускается, когда `enabled === true`, и опрашивает сервер каждые ~2 сек
 * (с jitter ±300мс), пока не придёт terminal-статус (`ready` или `failed`).
 * При сетевых ошибках — экспоненциальный backoff до 30 сек.
 *
 * @param {string} presetKey
 * @param {boolean} enabled
 * @param {(payload: object) => void} onUpdate — вызывается после каждого успешного ответа
 */
export default function useExportStatus(presetKey, enabled, onUpdate) {
    const timerRef = useRef(null);
    const failuresRef = useRef(0);
    const cancelledRef = useRef(false);

    useEffect(() => {
        if (!enabled || !presetKey) {
            return;
        }

        cancelledRef.current = false;
        failuresRef.current = 0;

        const tick = async () => {
            if (cancelledRef.current) return;

            try {
                const res = await axios.get(`/cabinet/export-presets/${presetKey}/status`);
                failuresRef.current = 0;

                if (cancelledRef.current) return;

                onUpdate?.(res.data);

                const terminal = res.data?.status === 'ready' || res.data?.status === 'failed';
                if (!terminal) {
                    schedule(2000);
                }
            } catch {
                failuresRef.current += 1;
                const delay = Math.min(30000, 2000 * 2 ** Math.min(failuresRef.current - 1, 4));
                schedule(delay);
            }
        };

        const schedule = (base) => {
            if (cancelledRef.current) return;
            const jitter = Math.floor(Math.random() * 600) - 300;
            timerRef.current = setTimeout(tick, Math.max(500, base + jitter));
        };

        schedule(0);

        return () => {
            cancelledRef.current = true;
            if (timerRef.current) {
                clearTimeout(timerRef.current);
                timerRef.current = null;
            }
        };
    }, [presetKey, enabled, onUpdate]);
}
