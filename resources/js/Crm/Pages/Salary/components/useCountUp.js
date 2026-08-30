import { useEffect, useRef, useState } from 'react';

/**
 * Число, которое «доезжает» до нового значения, а не прыгает.
 *
 * Единственная анимация раздела: итог в hero. Уважает prefers-reduced-motion —
 * тогда значение меняется сразу. Первый рендер тоже без анимации: страница
 * должна открыться с готовой цифрой, а не с нулём.
 */
export function useCountUp(target, { duration = 700 } = {}) {
    const [value, setValue] = useState(Number(target ?? 0));
    const frame = useRef(null);
    const from = useRef(Number(target ?? 0));
    const first = useRef(true);

    useEffect(() => {
        const to = Number(target ?? 0);
        const reduce = typeof window !== 'undefined'
            && window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        if (first.current || reduce || duration <= 0) {
            first.current = false;
            from.current = to;
            setValue(to);
            return undefined;
        }

        const start = performance.now();
        const begin = from.current;
        const delta = to - begin;

        if (Math.abs(delta) < 0.005) {
            return undefined;
        }

        const tick = (now) => {
            const t = Math.min(1, (now - start) / duration);
            const eased = 1 - (1 - t) ** 3;
            setValue(begin + delta * eased);

            if (t < 1) {
                frame.current = requestAnimationFrame(tick);
            } else {
                from.current = to;
            }
        };

        frame.current = requestAnimationFrame(tick);

        return () => {
            if (frame.current) cancelAnimationFrame(frame.current);
            from.current = to;
        };
    }, [target, duration]);

    return value;
}
