import { Badge, HStack, IconButton, Text, VStack } from '@chakra-ui/react';
import { LuPencil } from 'react-icons/lu';
import { DataTable } from '@/Admin/Components/DataTable';
import { Tooltip } from '@/components/ui/tooltip';
import TasksCell from '@/Crm/Pages/Clients/components/TasksCell';

const RUB = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 });

/**
 * Табличный режим доски лидов.
 *
 * Нужен там, где канбан бесполезен: разобрать всю базу, отсортировать по сумме,
 * найти залежавшихся. Доска остаётся способом вести сделку, таблица — способом
 * её найти, поэтому обе живут на одной странице и делят фильтры.
 *
 * Колонка задач — тот же `TasksCell`, что в партнёрах и планах: одинаковые данные
 * обязаны выглядеть одинаково, иначе пустое значение читается как разное.
 */
export default function LeadsTable({
    rows,
    staleDays = 14,
    sort,
    direction,
    selectable = false,
    bulkActions = [],
    onSort,
    onOpen,
    onCreateTask,
    onOpenTask,
}) {
    const columns = [
        {
            key: 'name',
            label: 'Лид',
            sortable: true,
            render: (_value, row) => (
                <VStack align="start" gap={0}>
                    <Text
                        as="button"
                        type="button"
                        fontWeight="600"
                        fontSize="sm"
                        textAlign="left"
                        _hover={{ textDecoration: 'underline' }}
                        onClick={() => onOpen?.(row)}
                    >
                        {row.name}
                    </Text>
                    {row.company_name && (
                        <Text fontSize="xs" color="fg.muted">{row.company_name}</Text>
                    )}
                </VStack>
            ),
        },
        {
            key: 'contact',
            label: 'Контакт',
            render: (value) => <Text fontSize="sm">{value || '—'}</Text>,
        },
        {
            key: 'stage_id',
            label: 'Стадия',
            render: (_value, row) => (row.stage
                ? <Badge size="sm" variant="subtle" colorPalette={row.stage.color || 'gray'}>{row.stage.name}</Badge>
                : <Text fontSize="sm" color="fg.muted">Без стадии</Text>),
        },
        {
            key: 'manager',
            label: 'Менеджер',
            render: (value) => (value
                ? <Text fontSize="sm">{value.name}</Text>
                : <Text fontSize="sm" color="orange.fg">Ничей</Text>),
        },
        {
            key: 'qualified_amount',
            label: 'Оценка',
            sortable: true,
            render: (value) => (value
                ? <Text fontSize="sm">{RUB.format(value)} ₽</Text>
                : <Text fontSize="sm" color="fg.muted">—</Text>),
        },
        {
            key: 'stage_changed_at',
            label: 'На стадии',
            sortable: true,
            render: (_value, row) => {
                if (row.days_on_stage === null) {
                    return <Text fontSize="sm" color="fg.muted">—</Text>;
                }

                return (
                    <Badge
                        size="sm"
                        variant="subtle"
                        colorPalette={row.days_on_stage >= staleDays ? 'red' : 'gray'}
                    >
                        {row.days_on_stage} дн.
                    </Badge>
                );
            },
        },
        {
            key: 'tasks',
            label: 'Задачи',
            render: (value, row) => (
                <TasksCell
                    tasks={value}
                    onCreate={() => onCreateTask?.(row)}
                    onOpen={(taskId) => onOpenTask?.(taskId)}
                />
            ),
        },
        {
            key: 'converted_user',
            label: 'Партнёр',
            render: (value) => (value
                ? (
                    <HStack gap={1}>
                        <Badge size="sm" variant="subtle" colorPalette="green">Переведён</Badge>
                        <Text fontSize="xs" color="fg.muted" lineClamp={1}>{value.name}</Text>
                    </HStack>
                )
                : <Text fontSize="sm" color="fg.muted">—</Text>),
        },
        {
            // Явная кнопка, хотя имя в первой колонке тоже открывает карточку:
            // кликабельность имени ничем не обозначена, и её не находят.
            key: 'actions',
            label: '',
            render: (_value, row) => (
                <HStack gap={1} justify="end">
                    <Tooltip content="Открыть карточку лида" openDelay={400}>
                        <IconButton
                            size="xs"
                            variant="ghost"
                            aria-label={`Открыть лида ${row.name}`}
                            onClick={() => onOpen?.(row)}
                        >
                            <LuPencil />
                        </IconButton>
                    </Tooltip>
                </HStack>
            ),
        },
    ];

    return (
        <DataTable
            columns={columns}
            data={rows?.data ?? []}
            pagination={rows}
            selectable={selectable}
            bulkActions={bulkActions}
            sortColumn={sort}
            sortDirection={direction || 'desc'}
            onSort={onSort}
            emptyMessage="Лидов не нашлось — измените фильтры или заведите нового."
        />
    );
}
