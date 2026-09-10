import { useCallback, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { toastError } from '@/utils/toast';

/**
 * Галочка «Нераспределённые» — партнёры без персонального менеджера во всех
 * разделах CRM.
 *
 * В отличие от разреза «только мои» это не фокус экрана, а серверная настройка
 * сотрудника (users.crm_show_unassigned): ей подчиняются списки, счётчики,
 * поиск и открытие карточки, поэтому память в localStorage здесь не годится.
 * После сохранения страница перезапрашивается — данные уже другие.
 */
export function useShowUnassigned() {
    const { auth } = usePage().props;
    const enabled = Boolean(auth?.user?.crm_show_unassigned);
    const [busy, setBusy] = useState(false);

    const set = useCallback((next) => {
        setBusy(true);
        router.put(route('crm.preferences.unassigned'), { enabled: Boolean(next) }, {
            preserveScroll: true,
            onError: (errors) => toastError(
                'Не удалось изменить настройку',
                Object.values(errors)[0] || 'Попробуйте ещё раз.',
            ),
            onFinish: () => setBusy(false),
        });
    }, []);

    return {
        enabled,
        busy,
        toggle: useCallback(() => set(! enabled), [enabled, set]),
    };
}

export default useShowUnassigned;
