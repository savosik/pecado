import { HStack, IconButton } from '@chakra-ui/react';
import { router } from '@inertiajs/react';
import { LuPencil, LuTrash2 } from 'react-icons/lu';

/**
 * createActionsColumn — генерирует колонку «Действия» для DataTable
 *
 * @param {string} routeName - Базовый маршрут (напр. 'admin.brands')
 * @param {Function} onDelete - Обработчик удаления (получает row)
 * @param {Object} options - Дополнительные опции
 * @param {boolean} options.showEdit - Показать кнопку редактирования (по умолчанию true)
 * @param {boolean} options.showDelete - Показать кнопку удаления (по умолчанию true)
 * @param {Function} options.extraActions - Дополнительные действия (получает row, возвращает JSX)
 */
export const createActionsColumn = (routeName, onDelete, options = {}) => {
    const {
        showEdit = true,
        showDelete = true,
        extraActions,
    } = options;

    return {
        key: 'actions',
        label: 'Действия',
        render: (_, row) => (
            <HStack gap={1}>
                {extraActions && extraActions(row)}
                {showEdit && (
                    <IconButton
                        size="sm"
                        variant="ghost"
                        aria-label="Редактировать"
                        onClick={() => router.visit(route(`${routeName}.edit`, row.id))}
                    >
                        <LuPencil />
                    </IconButton>
                )}
                {showDelete && (
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
        ),
    };
};
