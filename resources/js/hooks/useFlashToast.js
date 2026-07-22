import { useEffect, useRef } from 'react';
import { usePage } from '@inertiajs/react';
import { toaster } from '@/components/ui/toaster';

/**
 * Показывает flash-сообщения (success/error/warning/info) как toast.
 *
 * Панели шарят flash как props.flash (HandleAdmin/WmsInertiaRequests), Layout
 * монтирует <Toaster/>, но сам сообщения не показывает. Вызывается на конкретной
 * странице.
 *
 * НЕ вешать глобально на PanelLayout: часть админ-страниц уже показывает те же
 * flash вручную через toaster.create в onSuccess — глобальный хук их задвоил бы.
 *
 * Реагируем на смену объекта flash между Inertia-визитами, а не только на
 * значения: два одинаковых сообщения подряд («Сохранено») должны показаться оба.
 */
export function useFlashToast() {
    const { flash } = usePage().props;
    const lastFlashRef = useRef(null);

    const success = flash?.success;
    const error = flash?.error;
    const warning = flash?.warning;
    const info = flash?.info;

    useEffect(() => {
        if (!flash) {
            return;
        }

        // Один и тот же объект flash (без нового визита) не повторяем.
        if (lastFlashRef.current === flash) {
            return;
        }
        lastFlashRef.current = flash;

        const map = [
            ['success', success],
            ['error', error],
            ['warning', warning],
            ['info', info],
        ];

        for (const [type, message] of map) {
            if (message) {
                toaster.create({ description: message, type });
            }
        }
    }, [flash, success, error, warning, info]);
}
