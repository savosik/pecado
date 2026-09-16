import { Box, HStack, Table, Text, VStack } from '@chakra-ui/react';
import { LuArrowDown, LuArrowUp } from 'react-icons/lu';
import { Pagination } from '@/Admin/Components/Pagination';
import { Alert } from '@/components/ui/alert';
import MetricHint from '@/Crm/Components/MetricHint';

/**
 * Таблица списка партнёров с серверной сортировкой и страницей.
 *
 * Колонки описывает страница: `{ key, label, sub, align, sortable, hint, render }` —
 * `sub` подписывает вторую строку сгруппированной колонки.
 * Клик по заголовку меняет сортировку в адресе; клик по строке ничего
 * не делает — переходы только через действия строки.
 */
export default function PartnerTable({ list, columns, onSort, onPage, emptyTitle, emptyText }) {
    const rows = list?.rows?.data ?? [];
    const sort = list?.sort ?? {};

    if (rows.length === 0) {
        return <Alert status="info" title={emptyTitle}>{emptyText}</Alert>;
    }

    const header = (column) => {
        const active = sort.column === column.key;
        const Icon = active && sort.direction === 'asc' ? LuArrowUp : LuArrowDown;

        const hint = column.hint ? <MetricHint text={column.hint} /> : null;
        const sub = column.sub ? <Text fontSize="xs" fontWeight="400" color="fg.subtle" textAlign={column.align === 'right' ? 'right' : 'left'}>{column.sub}</Text> : null;

        if (!column.sortable) {
            return hint ? <HStack gap={1} justify={column.align === 'right' ? 'flex-end' : 'flex-start'}><Text>{column.label}</Text>{hint}</HStack> : column.label;
        }

        return (
            <VStack align={column.align === 'right' ? 'end' : 'start'} gap={0} w="100%">
            <HStack gap={1} justify={column.align === 'right' ? 'flex-end' : 'flex-start'} w="100%">
                <HStack
                    as="button"
                    type="button"
                    gap={1}
                    cursor="pointer"
                    color={active ? 'fg' : 'fg.muted'}
                    onClick={() => onSort(column.key, active && sort.direction === 'desc' ? 'asc' : 'desc')}
                    aria-label={`Сортировать по: ${column.label}`}
                >
                    <Text>{column.label}</Text>
                    {active && <Icon size={12} />}
                </HStack>
                {hint}
            </HStack>
            {sub}
            </VStack>
        );
    };

    return (
        <>
            <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflowX="auto">
                <Table.Root size="sm">
                    <Table.Header>
                        <Table.Row>
                            {columns.map((column) => (
                                <Table.ColumnHeader key={column.key} textAlign={column.align ?? 'left'} whiteSpace="nowrap">
                                    {header(column)}
                                </Table.ColumnHeader>
                            ))}
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {rows.map((row) => (
                            <Table.Row key={row.id}>
                                {columns.map((column) => (
                                    <Table.Cell key={column.key} textAlign={column.align ?? 'left'} verticalAlign="top">
                                        {column.render(row)}
                                    </Table.Cell>
                                ))}
                            </Table.Row>
                        ))}
                    </Table.Body>
                </Table.Root>
            </Box>
            <Pagination pagination={list.rows} onPageChange={onPage} />
        </>
    );
}
