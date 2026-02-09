import React from 'react';
import {
    Box,
    HStack,
    Text,
    IconButton,
    NativeSelect,
} from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import {
    LuChevronLeft,
    LuChevronRight,
    LuChevronsLeft,
    LuChevronsRight,
} from 'react-icons/lu';

/**
 * Pagination — универсальный компонент пагинации
 *
 * @param {Object} pagination - Данные пагинации Laravel (current_page, last_page, per_page, total, from, to)
 * @param {number} perPage - Текущее значение per_page (опционально, если управляется извне)
 * @param {Function} onPerPageChange - Callback при изменении per_page
 * @param {Function} onPageChange - Callback при смене страницы; если передан — используется onClick вместо Link
 */
export const Pagination = ({
    pagination,
    perPage = null,
    onPerPageChange,
    onPageChange,
}) => {
    if (!pagination || pagination.last_page <= 1) return null;

    const isFirst = pagination.current_page === 1;
    const isLast = pagination.current_page === pagination.last_page;

    const navButtonProps = (page, disabled) => {
        if (onPageChange) {
            return {
                onClick: () => onPageChange(page),
                disabled,
            };
        }
        return {
            as: Link,
            href: `?page=${page}`,
            preserveScroll: true,
            preserveState: true,
            disabled,
        };
    };

    return (
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
                        {...navButtonProps(1, isFirst)}
                        size="sm"
                        variant="ghost"
                        aria-label="Первая страница"
                    >
                        <LuChevronsLeft />
                    </IconButton>
                    <IconButton
                        {...navButtonProps(pagination.current_page - 1, isFirst)}
                        size="sm"
                        variant="ghost"
                        aria-label="Предыдущая страница"
                    >
                        <LuChevronLeft />
                    </IconButton>
                    <Text fontSize="sm" px={3}>
                        Страница {pagination.current_page} из {pagination.last_page}
                    </Text>
                    <IconButton
                        {...navButtonProps(pagination.current_page + 1, isLast)}
                        size="sm"
                        variant="ghost"
                        aria-label="Следующая страница"
                    >
                        <LuChevronRight />
                    </IconButton>
                    <IconButton
                        {...navButtonProps(pagination.last_page, isLast)}
                        size="sm"
                        variant="ghost"
                        aria-label="Последняя страница"
                    >
                        <LuChevronsRight />
                    </IconButton>
                </HStack>
            </HStack>
        </Box>
    );
};
