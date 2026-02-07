import { useState } from 'react';
import { router } from '@inertiajs/react';
import { toaster } from '@/components/ui/toaster';

/**
 * useResourceIndex — хук для Index-страниц ресурсов
 * Инкапсулирует: поиск, сортировку, постраничную навигацию, удаление
 *
 * @param {string} routeName - Базовое имя маршрута (напр. 'admin.brands')
 * @param {Object} filters - Текущие фильтры из Inertia props
 * @param {Object} options - Дополнительные опции
 * @param {string} options.entityLabel - Название сущности для тостеров (напр. 'бренд')
 * @param {string} options.deleteSuccessTitle - Заголовок тостера при удалении
 * @param {string} options.deleteErrorTitle - Заголовок ошибки удаления
 */
export const useResourceIndex = (routeName, filters = {}, options = {}) => {
    const {
        entityLabel = 'запись',
        deleteSuccessTitle,
        deleteErrorTitle,
    } = options;

    const [searchQuery, setSearchQuery] = useState(filters.search || '');
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [entityToDelete, setEntityToDelete] = useState(null);

    // Навигация с фильтрами
    const navigate = (params) => {
        router.get(route(`${routeName}.index`), params, {
            preserveState: true,
            replace: true,
        });
    };

    const handleSearch = (value) => {
        setSearchQuery(value);
        navigate({
            search: value,
            per_page: filters.per_page,
            sort_by: filters.sort_by,
            sort_order: filters.sort_order,
        });
    };

    const handleSort = (field, direction) => {
        // Поддерживаем как (field) так и (field, direction)
        const newDirection = direction || (
            filters.sort_by === field && filters.sort_order === 'asc' ? 'desc' : 'asc'
        );
        navigate({
            ...filters,
            sort_by: field,
            sort_order: newDirection,
        });
    };

    const handlePerPageChange = (perPage) => {
        navigate({
            ...filters,
            per_page: perPage,
        });
    };

    const openDeleteDialog = (entity) => {
        setEntityToDelete(entity);
        setDeleteDialogOpen(true);
    };

    const confirmDelete = () => {
        if (!entityToDelete) return;

        const id = typeof entityToDelete === 'object' ? entityToDelete.id : entityToDelete;

        router.delete(route(`${routeName}.destroy`, id), {
            onSuccess: () => {
                toaster.create({
                    title: deleteSuccessTitle || `${entityLabel} удален(а)`,
                    type: 'success',
                });
                setDeleteDialogOpen(false);
                setEntityToDelete(null);
            },
            onError: () => {
                toaster.create({
                    title: deleteErrorTitle || `Ошибка при удалении`,
                    type: 'error',
                });
            },
        });
    };

    const closeDeleteDialog = () => {
        setDeleteDialogOpen(false);
        setEntityToDelete(null);
    };

    return {
        // Поиск
        searchQuery,
        setSearchQuery,
        handleSearch,

        // Сортировка
        handleSort,

        // Постраничная навигация
        handlePerPageChange,

        // Удаление
        deleteDialogOpen,
        entityToDelete,
        openDeleteDialog,
        confirmDelete,
        closeDeleteDialog,

        // Утилиты
        navigate,
    };
};
