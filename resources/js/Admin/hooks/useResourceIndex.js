import { useState, useEffect, useRef, useCallback } from 'react';
import { router } from '@inertiajs/react';
import { toaster } from '@/components/ui/toaster';

/**
 * useResourceIndex — хук для Index-страниц ресурсов
 * Инкапсулирует: поиск, сортировку, постраничную навигацию, удаление, массовое удаление
 *
 * @param {string} routeName - Базовое имя маршрута (напр. 'admin.brands')
 * @param {Object} filters - Текущие фильтры из Inertia props
 * @param {Object} options - Дополнительные опции
 * @param {string} options.entityLabel - Название сущности для тостеров (напр. 'бренд')
 * @param {string} options.deleteSuccessTitle - Заголовок тостера при удалении
 * @param {string} options.deleteErrorTitle - Заголовок ошибки удаления
 * @param {string} options.bulkDeleteResource - Slug ресурса для bulk-delete-all (авто-определяется из routeName)
 */
export const useResourceIndex = (routeName, filters = {}, options = {}) => {
    const {
        entityLabel = 'запись',
        deleteSuccessTitle,
        deleteErrorTitle,
        bulkDeleteResource,
    } = options;

    const [searchQuery, setSearchQuery] = useState(filters.search || '');
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [entityToDelete, setEntityToDelete] = useState(null);

    // Массовое удаление
    const [deleteAllDialogOpen, setDeleteAllDialogOpen] = useState(false);
    const [deleteAllProcessing, setDeleteAllProcessing] = useState(false);
    const [deleteAllProgress, setDeleteAllProgress] = useState(null);
    const pollingRef = useRef(null);

    // Определяем slug ресурса: 'admin.brand-stories' => 'brand-stories'
    const resourceSlug = bulkDeleteResource || routeName.replace(/^admin\./, '');

    // Поллинг статуса удаления
    const startPolling = useCallback(() => {
        if (pollingRef.current) return;

        pollingRef.current = setInterval(async () => {
            try {
                const response = await fetch(route('admin.bulk-delete-status', resourceSlug));
                const data = await response.json();

                setDeleteAllProgress(data);

                if (data.status === 'completed') {
                    stopPolling();
                    setDeleteAllProcessing(false);
                    setDeleteAllProgress(null);
                    toaster.create({
                        title: data.message || 'Все записи успешно удалены',
                        type: 'success',
                    });
                    // Обновляем страницу
                    router.reload();
                } else if (data.status === 'failed') {
                    stopPolling();
                    setDeleteAllProcessing(false);
                    toaster.create({
                        title: data.message || 'Ошибка при массовом удалении',
                        type: 'error',
                    });
                }
            } catch {
                // Игнорируем ошибки сети при поллинге
            }
        }, 2000);
    }, [resourceSlug]);

    const stopPolling = useCallback(() => {
        if (pollingRef.current) {
            clearInterval(pollingRef.current);
            pollingRef.current = null;
        }
    }, []);

    // Очищаем поллинг при размонтировании
    useEffect(() => {
        return () => stopPolling();
    }, [stopPolling]);

    // Навигация с фильтрами
    const navigate = (params) => {
        router.get(route(`${routeName}.index`), params, {
            preserveState: true,
            replace: true,
        });
    };

    // Фильтры сохраняются: раньше здесь пересобирался только search/per_page/sort_*,
    // и ввод в поиск молча сбрасывал всё остальное (стадию, менеджера, статус).
    const handleSearch = (value) => {
        setSearchQuery(value);
        navigate({
            ...filters,
            search: value,
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

    // --- Массовое удаление всех записей ---

    const openDeleteAllDialog = () => {
        setDeleteAllDialogOpen(true);
    };

    const confirmDeleteAll = () => {
        setDeleteAllProcessing(true);
        setDeleteAllDialogOpen(false);

        router.delete(route('admin.bulk-delete-all', resourceSlug), {
            onSuccess: () => {
                toaster.create({
                    title: 'Удаление запущено в фоне',
                    description: 'Обновите страницу через несколько секунд',
                    type: 'info',
                });
                // Начинаем поллинг статуса
                startPolling();
            },
            onError: () => {
                toaster.create({
                    title: 'Ошибка при запуске удаления',
                    type: 'error',
                });
                setDeleteAllProcessing(false);
            },
        });
    };

    const closeDeleteAllDialog = () => {
        setDeleteAllDialogOpen(false);
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

        // Удаление одной записи
        deleteDialogOpen,
        entityToDelete,
        openDeleteDialog,
        confirmDelete,
        closeDeleteDialog,

        // Массовое удаление
        deleteAllDialogOpen,
        deleteAllProcessing,
        deleteAllProgress,
        openDeleteAllDialog,
        confirmDeleteAll,
        closeDeleteAllDialog,

        // Утилиты
        navigate,
    };
};
