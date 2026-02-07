import React, { useState } from 'react';
import {
    Box,
    Table,
    HStack,
    Text,
    IconButton,
    Button,
    Skeleton,
    NativeSelect,
    VStack,
    Icon,
} from '@chakra-ui/react';
import { Checkbox } from '../../components/ui/checkbox';
import { Link } from '@inertiajs/react';
import {
    LuChevronLeft,
    LuChevronRight,
    LuChevronsLeft,
    LuChevronsRight,
    LuArrowUp,
    LuArrowDown,
    LuInbox,
} from 'react-icons/lu';

/**
 * DataTable - универсальный компонент таблицы с пагинацией, сортировкой и фильтрацией
 * 
 * @param {Array} columns - Конфигурация колонок [{key, label, sortable, render}]
 * @param {Array} data - Данные для отображения
 * @param {Object} pagination - Данные пагинации Laravel (current_page, last_page, per_page, total, from, to)
 * @param {boolean} loading - Индикатор загрузки
 * @param {Function} onSort - Callback сортировки (column, direction)
 * @param {string} sortColumn - Текущая колонка сортировки
 * @param {string} sortDirection - Направление сортировки ('asc' | 'desc')
 * @param {Array} bulkActions - Массовые действия [{label, action, variant, colorPalette}]
 * @param {boolean} selectable - Разрешить выбор строк
 * @param {ReactNode} emptyMessage - Сообщение при отсутствии данных
 */
export const DataTable = ({
    columns = [],
    data = [],
    pagination = null,
    loading = false,
    onSort,
    sortColumn = null,
    sortDirection = 'asc',
    bulkActions = [],
    selectable = false,
    emptyMessage = 'Нет данных для отображения',
    perPage = null,
    onPerPageChange,
}) => {
    const [selectedRows, setSelectedRows] = useState([]);

    // Обработка выбора всех строк
    const handleSelectAll = (checked) => {
        if (checked) {
            setSelectedRows(data.map(row => row.id));
        } else {
            setSelectedRows([]);
        }
    };

    // Обработка выбора одной строки
    const handleSelectRow = (id, checked) => {
        if (checked) {
            setSelectedRows([...selectedRows, id]);
        } else {
            setSelectedRows(selectedRows.filter(rowId => rowId !== id));
        }
    };

    // Обработка клика по заголовку для сортировки
    const handleSort = (column) => {
        if (!column.sortable || !onSort) return;

        const newDirection =
            sortColumn === column.key && sortDirection === 'asc' ? 'desc' : 'asc';
        onSort(column.key, newDirection);
    };

    // Очистка выбранных строк после выполнения действия
    const handleBulkAction = (action) => {
        action(selectedRows);
        setSelectedRows([]);
    };

    const isAllSelected = data.length > 0 && selectedRows.length === data.length;
    const isSomeSelected = selectedRows.length > 0 && selectedRows.length < data.length;

    return (
        <Box>
            {/* Панель массовых действий */}
            {selectable && selectedRows.length > 0 && bulkActions.length > 0 && (
                <Box
                    bg="bg.emphasized"
                    borderRadius="md"
                    p={3}
                    mb={3}
                >
                    <HStack justifyContent="space-between">
                        <Text fontSize="sm" fontWeight="medium">
                            Выбрано: {selectedRows.length}
                        </Text>
                        <HStack gap={2}>
                            {bulkActions.map((action, index) => (
                                <Button
                                    key={index}
                                    size="sm"
                                    variant={action.variant || 'outline'}
                                    colorPalette={action.colorPalette || 'gray'}
                                    onClick={() => handleBulkAction(action.action)}
                                >
                                    {action.label}
                                </Button>
                            ))}
                        </HStack>
                    </HStack>
                </Box>
            )}

            {/* Таблица */}
            <Box
                borderWidth="1px"
                borderColor="border.muted"
                borderRadius="md"
                overflow="hidden"
            >
                <Box overflowX="auto">
                    <Table.Root size="sm" variant="line">
                        <Table.Header>
                            <Table.Row>
                                {selectable && (
                                    <Table.ColumnHeader width="40px">
                                        <Checkbox
                                            checked={isAllSelected}
                                            indeterminate={isSomeSelected ? true : undefined}
                                            onCheckedChange={(e) => handleSelectAll(e.checked)}
                                        />
                                    </Table.ColumnHeader>
                                )}
                                {columns.map((column) => (
                                    <Table.ColumnHeader
                                        key={column.key}
                                        cursor={column.sortable ? 'pointer' : 'default'}
                                        onClick={() => handleSort(column)}
                                        userSelect="none"
                                    >
                                        <HStack gap={2}>
                                            <Text as="div">{column.label}</Text>
                                            {column.sortable && sortColumn === column.key && (
                                                sortDirection === 'asc' ? (
                                                    <LuArrowUp size={14} />
                                                ) : (
                                                    <LuArrowDown size={14} />
                                                )
                                            )}
                                        </HStack>
                                    </Table.ColumnHeader>
                                ))}
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {loading ? (
                                // Skeleton loader
                                [...Array(5)].map((_, index) => (
                                    <Table.Row key={index}>
                                        {selectable && (
                                            <Table.Cell>
                                                <Skeleton height="20px" width="20px" />
                                            </Table.Cell>
                                        )}
                                        {columns.map((column) => (
                                            <Table.Cell key={column.key}>
                                                <Skeleton height="20px" />
                                            </Table.Cell>
                                        ))}
                                    </Table.Row>
                                ))
                            ) : data.length === 0 ? (
                                // Пустое состояние
                                <Table.Row>
                                    <Table.Cell colSpan={columns.length + (selectable ? 1 : 0)}>
                                        <VStack py={10} gap={3}>
                                            <Icon as={LuInbox} boxSize={12} color="fg.muted" />
                                            <Text fontWeight="medium">{emptyMessage}</Text>
                                            <Text fontSize="sm" color="fg.muted">
                                                Попробуйте изменить фильтры или создать новую запись
                                            </Text>
                                        </VStack>
                                    </Table.Cell>
                                </Table.Row>
                            ) : (
                                // Данные
                                data.map((row) => (
                                    <Table.Row
                                        key={row.id}
                                        _hover={{ bg: 'bg.muted' }}
                                        transition="background 0.2s"
                                    >
                                        {selectable && (
                                            <Table.Cell>
                                                <Checkbox
                                                    checked={selectedRows.includes(row.id)}
                                                    onCheckedChange={(e) => handleSelectRow(row.id, e.checked)}
                                                />
                                            </Table.Cell>
                                        )}
                                        {columns.map((column) => (
                                            <Table.Cell key={column.key}>
                                                {column.render
                                                    ? column.render(row[column.key], row)
                                                    : row[column.key]}
                                            </Table.Cell>
                                        ))}
                                    </Table.Row>
                                ))
                            )}
                        </Table.Body>
                    </Table.Root>
                </Box>

                {/* Пагинация */}
                {pagination && pagination.last_page > 1 && (
                    <Box
                        borderTopWidth="1px"
                        borderColor="border.muted"
                        p={3}
                        bg="bg.subtle"
                    >
                        <HStack justifyContent="space-between" flexWrap="wrap" gap={3}>
                            <HStack gap={3}>
                                <Text fontSize="sm" color="fg.muted">
                                    Показано {pagination.from || 0} - {pagination.to || 0} из{' '}
                                    {pagination.total || 0}
                                </Text>
                                {onPerPageChange && (
                                    <HStack gap={2}>
                                        <Text fontSize="sm" color="fg.muted">Показывать:</Text>
                                        <NativeSelect.Root size="sm" width="80px">
                                            <NativeSelect.Field
                                                value={perPage || pagination.per_page || 15}
                                                onChange={(e) => onPerPageChange(Number(e.target.value))}
                                            >
                                                <option value={10}>10</option>
                                                <option value={15}>15</option>
                                                <option value={25}>25</option>
                                                <option value={50}>50</option>
                                                <option value={100}>100</option>
                                            </NativeSelect.Field>
                                        </NativeSelect.Root>
                                    </HStack>
                                )}
                            </HStack>
                            <HStack gap={1}>
                                <IconButton
                                    as={Link}
                                    href={`?page=1`}
                                    preserveScroll
                                    preserveState
                                    size="sm"
                                    variant="ghost"
                                    disabled={pagination.current_page === 1}
                                    aria-label="Первая страница"
                                >
                                    <LuChevronsLeft />
                                </IconButton>
                                <IconButton
                                    as={Link}
                                    href={`?page=${pagination.current_page - 1}`}
                                    preserveScroll
                                    preserveState
                                    size="sm"
                                    variant="ghost"
                                    disabled={pagination.current_page === 1}
                                    aria-label="Предыдущая страница"
                                >
                                    <LuChevronLeft />
                                </IconButton>
                                <Text fontSize="sm" px={3}>
                                    Страница {pagination.current_page} из {pagination.last_page}
                                </Text>
                                <IconButton
                                    as={Link}
                                    href={`?page=${pagination.current_page + 1}`}
                                    preserveScroll
                                    preserveState
                                    size="sm"
                                    variant="ghost"
                                    disabled={pagination.current_page === pagination.last_page}
                                    aria-label="Следующая страница"
                                >
                                    <LuChevronRight />
                                </IconButton>
                                <IconButton
                                    as={Link}
                                    href={`?page=${pagination.last_page}`}
                                    preserveScroll
                                    preserveState
                                    size="sm"
                                    variant="ghost"
                                    disabled={pagination.current_page === pagination.last_page}
                                    aria-label="Последняя страница"
                                >
                                    <LuChevronsRight />
                                </IconButton>
                            </HStack>
                        </HStack>
                    </Box>
                )}
            </Box>
        </Box>
    );
};
