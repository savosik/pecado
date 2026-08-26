import RowActions from '@/shared/Panel/RowActions';

const routeExists = (name) => {
    try {
        return route().has(name);
    } catch {
        return false;
    }
};

/**
 * createActionsColumn — колонка «Действия» для DataTable поверх общего RowActions.
 *
 * Глаз, карандаш и корзина — единый стандарт всех панелей, поэтому просмотр
 * включён по умолчанию; выключать его нужно явно и только там, где нет
 * show-маршрута (иначе Ziggy упадёт на рендере всей страницы — на это
 * стоит страховка routeExists).
 *
 * @param {string} routeName - Базовый маршрут (напр. 'admin.brands')
 * @param {Function} onDelete - Обработчик удаления (получает row); корзина не рисуется без него
 * @param {Object} options
 * @param {boolean} options.showView - Кнопка просмотра (по умолчанию true, если есть маршрут .show)
 * @param {boolean} options.showEdit - Кнопка редактирования (по умолчанию true)
 * @param {boolean} options.showDelete - Кнопка удаления (по умолчанию true)
 * @param {string} options.permissionPrefix - Префикс ресурса для проверки прав (напр. 'products')
 * @param {Function} options.viewHref - (row) => url — нестандартный адрес просмотра
 * @param {Function} options.editHref - (row) => url — нестандартный адрес редактирования
 * @param {Function} options.canDelete - (row) => true | false | 'причина' — блокировка корзины по данным строки
 * @param {string} options.deleteLabel - Подпись корзины (напр. «Удалить навсегда»)
 * @param {Function} options.extraActions - (row) => массив действий RowActions либо JSX
 */
export const createActionsColumn = (routeName, onDelete, options = {}) => {
    const {
        showView = true,
        showEdit = true,
        showDelete = true,
        extraActions,
        permissionPrefix,
        viewHref,
        editHref,
        canDelete,
        deleteLabel,
    } = options;

    const showRoute = `${routeName}.show`;
    const editRoute = `${routeName}.edit`;
    const perm = (action) => (permissionPrefix ? `${permissionPrefix}.${action}` : undefined);

    const hasView = showView && (viewHref || routeExists(showRoute));
    const hasEdit = showEdit && (editHref || routeExists(editRoute));

    const disabledFor = (row) => {
        if (!canDelete) return false;
        const verdict = canDelete(row);
        if (verdict === false) return true;
        if (typeof verdict === 'string') return verdict;
        return false;
    };

    return {
        key: 'actions',
        label: 'Действия',
        render: (_, row) => (
            <RowActions
                view={hasView ? {
                    href: viewHref ? viewHref(row) : route(showRoute, row.id),
                    permission: perm('view'),
                } : null}
                edit={hasEdit ? {
                    href: editHref ? editHref(row) : route(editRoute, row.id),
                    permission: perm('edit'),
                } : null}
                delete={showDelete && onDelete ? {
                    onClick: () => onDelete(row),
                    permission: perm('delete'),
                    disabled: disabledFor(row),
                    label: deleteLabel,
                } : null}
                extra={extraActions ? extraActions(row) : null}
            />
        ),
    };
};
