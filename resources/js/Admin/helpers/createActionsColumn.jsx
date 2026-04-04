import { HStack, IconButton } from '@chakra-ui/react';
import { router } from '@inertiajs/react';
import { LuPencil, LuTrash2 } from 'react-icons/lu';
import { usePermission } from '@/Admin/hooks/usePermission';

/**
 * ActionsCell — React-компонент для рендера ячейки действий.
 */
const ActionsCell = ({ row, routeName, onDelete, showEdit, showDelete, extraActions, permissionPrefix }) => {
    const { can } = usePermission();

    const canEdit = !permissionPrefix || can(`${permissionPrefix}.edit`);
    const canDelete = !permissionPrefix || can(`${permissionPrefix}.delete`);

    return (
        <HStack gap={1}>
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
 * @param {boolean} options.showEdit - Показать кнопку редактирования (по умолчанию true)
 * @param {boolean} options.showDelete - Показать кнопку удаления (по умолчанию true)
 * @param {string} options.permissionPrefix - Префикс ресурса для проверки прав (напр. 'products').
 *                                            Если не указан — кнопки показываются без проверки.
 * @param {Function} options.extraActions - Дополнительные действия (получает row, возвращает JSX)
 */
export const createActionsColumn = (routeName, onDelete, options = {}) => {
    const {
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
                showEdit={showEdit}
                showDelete={showDelete}
                extraActions={extraActions}
                permissionPrefix={permissionPrefix}
            />
        ),
    };
};
