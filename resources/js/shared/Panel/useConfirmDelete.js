import { useCallback, useState } from 'react';

/**
 * useConfirmDelete — состояние диалога подтверждения удаления для страниц,
 * у которых нет useResourceIndex (CRM, WMS, ручные списки админки).
 *
 *   const del = useConfirmDelete({
 *       onConfirm: (row) => router.delete(route('crm.contacts.destroy', row.id), { preserveScroll: true }),
 *       description: (row) => `Контакт «${row.full_name}» будет удалён.`,
 *   });
 *   <RowActions delete={{ onClick: () => del.request(row) }} />
 *   <ConfirmDialog {...del.dialogProps} />
 *
 * Подтверждение нужно всегда: удаление без окна — одна из причин, по которой
 * пользователи боялись нажимать на иконки.
 */
export function useConfirmDelete({
    onConfirm,
    title = 'Удалить запись?',
    description = 'Действие нельзя отменить.',
    confirmLabel = 'Удалить',
    cancelLabel = 'Отмена',
    colorPalette = 'red',
} = {}) {
    const [target, setTarget] = useState(null);
    const [loading, setLoading] = useState(false);

    const request = useCallback((row) => setTarget(row ?? true), []);
    const close = useCallback(() => setTarget(null), []);

    const confirm = useCallback(async () => {
        const row = target === true ? undefined : target;
        try {
            setLoading(true);
            await onConfirm?.(row);
        } finally {
            setLoading(false);
            setTarget(null);
        }
    }, [onConfirm, target]);

    const resolve = (value) => (typeof value === 'function'
        ? value(target === true ? undefined : target)
        : value);

    return {
        target: target === true ? undefined : target,
        request,
        close,
        dialogProps: {
            open: target !== null,
            onClose: close,
            onConfirm: confirm,
            title: resolve(title),
            description: resolve(description),
            confirmLabel,
            cancelLabel,
            colorPalette,
            isLoading: loading,
        },
    };
}
