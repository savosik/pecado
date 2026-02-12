/**
 * Хук для определения направления скролла.
 * Используется для авто-скрытия хедера при скролле вниз.
 *
 * Использование:
 *   const { scrollDirection, isFixed } = useScrollDirection();
 *   // scrollDirection: 'up' | 'down'
 *   // isFixed: true когда страница прокручена достаточно далеко
 */

import { useState, useEffect, useRef } from 'react';

const SHOW_THRESHOLD = 120;  // px — после этого хедер становится fixed
const HIDE_THRESHOLD = 80;   // px — если вернулись выше, хедер снова static
const DIRECTION_DELTA = 4;   // px — минимальная разница для смены направления

export default function useScrollDirection() {
    const [scrollDirection, setScrollDirection] = useState('up');
    const [isFixed, setIsFixed] = useState(false);

    const prevScrollYRef = useRef(0);
    const isFixedRef = useRef(false);
    const tickingRef = useRef(false);

    useEffect(() => {
        const onScroll = () => {
            const lastKnownScrollY = window.scrollY || 0;

            if (!tickingRef.current) {
                tickingRef.current = true;
                window.requestAnimationFrame(() => {
                    const prevY = prevScrollYRef.current;
                    const diff = lastKnownScrollY - prevY;

                    // Определяем направление скролла
                    if (diff > DIRECTION_DELTA) {
                        setScrollDirection((prev) => (prev !== 'down' ? 'down' : prev));
                    } else if (diff < -DIRECTION_DELTA) {
                        setScrollDirection((prev) => (prev !== 'up' ? 'up' : prev));
                    }

                    // Определяем, нужен ли fixed-режим
                    let nextIsFixed = isFixedRef.current;
                    if (!isFixedRef.current && lastKnownScrollY > SHOW_THRESHOLD) {
                        nextIsFixed = true;
                    } else if (isFixedRef.current && lastKnownScrollY < HIDE_THRESHOLD) {
                        nextIsFixed = false;
                    }

                    if (nextIsFixed !== isFixedRef.current) {
                        isFixedRef.current = nextIsFixed;
                        setIsFixed(nextIsFixed);
                    }

                    prevScrollYRef.current = lastKnownScrollY;
                    tickingRef.current = false;
                });
            }
        };

        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll(); // Инициализация текущего состояния

        return () => {
            window.removeEventListener('scroll', onScroll);
        };
    }, []);

    return { scrollDirection, isFixed };
}
