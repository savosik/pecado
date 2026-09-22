import { useEffect, useState } from 'react';
import { Box, HStack, Table, Text, VStack } from '@chakra-ui/react';
import { LuArrowDown, LuArrowUp } from 'react-icons/lu';
import { Pagination } from '@/Admin/Components/Pagination';
import { Alert } from '@/components/ui/alert';
import MetricHint from '@/Crm/Components/MetricHint';

/**
 * Таблица списка партнёров с серверной сортировкой и страницей.
 *
 * Колонки описывает страница: `{ key, label, sub, align, sortable, hint, group, render }` —
 * `sub` подписывает вторую строку колонки, `group` — надзаголовок, соседние колонки
 * с одним `group` сливаются в одну ячейку шапки и обрамляются вертикальными линиями,
 * `highlight` подкрашивает фон колонки (текущий месяц).
 * Клик по заголовку меняет сортировку в адресе; клик по строке ничего
 * не делает — переходы только через действия строки.
 */
export default function PartnerTable({ list, columns, onSort, onPage, emptyTitle, emptyText }) {
    const rows = list?.rows?.data ?? [];
    const sort = list?.sort ?? {};

    // Шапка липнет под шапкой панели: её высоту меряем, а не угадываем.
    const [stickyTop, setStickyTop] = useState(0);
    useEffect(() => {
        const bar = document.querySelector('header');
        const measure = () => setStickyTop(bar ? bar.getBoundingClientRect().height : 0);
        measure();
        window.addEventListener('resize', measure);
        return () => window.removeEventListener('resize', measure);
    }, []);

    if (rows.length === 0) {
        return <Alert status="info" title={emptyTitle}>{emptyText}</Alert>;
    }

    // Надзаголовки: соседние колонки с одинаковым `group` сливаются в одну ячейку шапки.
    const groups = columns.some((c) => c.group) ? columns.reduce((acc, c) => {
        const last = acc[acc.length - 1];
        if (last && last.label === (c.group ?? '') ) { last.span += 1; } else { acc.push({ label: c.group ?? '', span: 1 }); }
        return acc;
    }, []) : [];

    // Границы групп: вертикальная линия по краям каждой группы колонок; подсветка фона.
    const edge = (i) => {
        const g = columns[i].group;
        if (!g) return columns[i].highlight ? { bg: 'orange.50' } : {};
        return {
            ...(columns[i].highlight ? { bg: 'orange.50' } : {}),
            ...(i === 0 || columns[i - 1].group !== g ? { borderLeftWidth: '1px' } : {}),
            ...(i === columns.length - 1 || columns[i + 1].group !== g ? { borderRightWidth: '1px' } : {}),
            borderColor: 'border',
        };
    };

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
            <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl">
                <Table.Root size="sm">
                    <Table.Header position="sticky" top={`${stickyTop}px`} zIndex={2} bg="bg.panel" boxShadow="0 1px 0 var(--chakra-colors-border)">
                        {groups.length > 0 && (
                            <Table.Row>
                                {groups.map((g, i) => (
                                    <Table.ColumnHeader key={`${g.label}-${i}`} colSpan={g.span} textAlign="center" fontSize="xs" textTransform="uppercase" letterSpacing="wide" color="fg.muted" borderBottomWidth={g.label ? '1px' : '0'} borderLeftWidth={g.label ? '1px' : '0'} borderRightWidth={g.label ? '1px' : '0'} borderColor="border" py={1}>
                                        {g.label}
                                    </Table.ColumnHeader>
                                ))}
                            </Table.Row>
                        )}
                        <Table.Row>
                            {columns.map((column, i) => (
                                <Table.ColumnHeader key={column.key} textAlign={column.align ?? 'left'} whiteSpace="nowrap" {...edge(i)}>
                                    {header(column)}
                                </Table.ColumnHeader>
                            ))}
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {rows.map((row) => (
                            <Table.Row key={row.id}>
                                {columns.map((column, i) => (
                                    <Table.Cell key={column.key} textAlign={column.align ?? 'left'} verticalAlign="top" {...edge(i)}>
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
