import { HStack, IconButton } from '@chakra-ui/react';
import { router } from '@inertiajs/react';
import { LuEye, LuPencil, LuTrash2 } from 'react-icons/lu';
import { usePermission } from '@/Admin/hooks/usePermission';

const ActionsCell = ({ row, routeName, onDelete, showView, showEdit, showDelete, extraActions, permissionPrefix }) => {
    const { can } = usePermission();

    const canView = !permissionPrefix || can(`${permissionPrefix}.view`);
    const canEdit = !permissionPrefix || can(`${permissionPrefix}.edit`);
    const canDelete = !permissionPrefix || can(`${permissionPrefix}.delete`);

    return (
        <HStack gap={1}>
            {showView && canView && (
                <IconButton
                    size="sm"
                    variant="ghost"
                    aria-label="Просмотреть"
                    onClick={() => router.visit(route(`${routeName}.show`, row.id))}
                >
                    <LuEye />
                </IconButton>
            )}
            {extraActions && extraActions(row)}
            {showEdit && canEdit && (
                <IconButton
                    size="sm"
                    variant="ghost"
                    aria-label="Редактировать"
                    onClick={() => router.visit(route(`${routeName}.edit`, row.id))}
                >
                    <LuPencil />
                </IconButton>
            )}
            {showDelete && canDelete && (
                <IconButton
                    size="sm"
                    variant="ghost"
                    colorPalette="red"
                    aria-label="Удалить"
                    onClick={() => onDelete(row)}
                >
                    <LuTrash2 />
                </IconButton>
            )}
        </HStack>
    );
};

/**
 * createActionsColumn — генерирует колонку «Действия» для DataTable
 *
 * @param {string} routeName - Базовый маршрут (напр. 'admin.brands')
 * @param {Function} onDelete - Обработчик удаления (получает row)
 * @param {Object} options
 * @param {boolean} options.showView - Показать кнопку просмотра (по умолчанию false)
 * @param {boolean} options.showEdit - Показать кнопку редактирования (по умолчанию true)
 * @param {boolean} options.showDelete - Показать кнопку удаления (по умолчанию true)
 * @param {string} options.permissionPrefix - Префикс ресурса для проверки прав (напр. 'products').
 *                                            Если не указан — кнопки показываются без проверки.
 * @param {Function} options.extraActions - Дополнительные действия (получает row, возвращает JSX)
 */
export const createActionsColumn = (routeName, onDelete, options = {}) => {
    const {
        showView = false,
        showEdit = true,
        showDelete = true,
        extraActions,
        permissionPrefix,
    } = options;

    return {
        key: 'actions',
        label: 'Действия',
        render: (_, row) => (
            <ActionsCell
                row={row}
                routeName={routeName}
                onDelete={onDelete}
                showView={showView}
                showEdit={showEdit}
                showDelete={showDelete}
                extraActions={extraActions}
                permissionPrefix={permissionPrefix}
            />
        ),
    };
};
