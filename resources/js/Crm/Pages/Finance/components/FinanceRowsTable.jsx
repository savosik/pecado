import { useState } from 'react';
import { Badge, Box, HStack, Text, VStack } from '@chakra-ui/react';
import { LuListPlus } from 'react-icons/lu';
import { DataTable } from '@/Admin/Components/DataTable';
import { Button } from '@/components/ui/button';
import TaskDialog from '@/Crm/Components/TaskDialog';
import { usePermission } from '@/shared/Panel/usePermission';
import { dueHint, formatRub } from './format';

/**
 * Таблица строк ожидаемых поступлений — общая для «Плана» и «Просрочки».
 *
 * Задача ставится прямо отсюда: разговор про деньги начинается со строки графика,
 * и заставлять менеджера открывать карточку клиента ради поручения — лишний шаг.
 * Привязка идёт к реализации (CrmEntityMap разрешает `shipment`), поэтому задача
 * попадёт и в ленту клиента: client_user_id проставляет сама модель CrmTask.
 */
export default function FinanceRowsTable({ rows, onSort, sortColumn, sortDirection, emptyMessage }) {
    const { can } = usePermission();
    const [taskFor, setTaskFor] = useState(null);

    const canCreateTask = can('crm-tasks.create');

    const columns = [
        {
            key: 'due_date',
            label: 'Плановая дата',
            render: (_, row) => (
                <VStack align="start" gap={0}>
                    <Text fontSize="sm" fontWeight="600">{row.due_date_label}</Text>
                    <Text fontSize="10px" color={row.is_overdue ? 'red.fg' : 'fg.muted'}>
                        {dueHint(row)}
                    </Text>
                </VStack>
            ),
        },
        {
            key: 'client',
            label: 'Клиент',
            render: (_, row) => (
                <VStack align="start" gap={0}>
                    <Box
                        as="a"
                        href={row.client.url}
                        fontSize="sm"
                        _hover={{ color: 'blue.fg', textDecoration: 'underline' }}
                    >
                        {row.client.name}
                    </Box>
                    {row.manager_name && (
                        <Text fontSize="10px" color="fg.muted">{row.manager_name}</Text>
                    )}
                </VStack>
            ),
        },
        {
            key: 'shipment',
            label: 'Реализация',
            render: (_, row) => (
                <VStack align="start" gap={0}>
                    <Box
                        as="a"
                        href={row.shipment.url}
                        fontSize="sm"
                        _hover={{ color: 'blue.fg', textDecoration: 'underline' }}
                    >
                        {row.shipment.number}
                    </Box>
                    <Text fontSize="10px" color="fg.muted">
                        {row.shipment.date || '—'}
                        {row.shipment.invoice_number ? ` · с-ф ${row.shipment.invoice_number}` : ''}
                    </Text>
                </VStack>
            ),
        },
        {
            key: 'stage',
            label: 'Этап',
            render: (_, row) => (
                <VStack align="start" gap={1}>
                    <Text fontSize="xs" color="fg.muted">{row.stage_name || '—'}</Text>
                    {row.source === 'no_schedule' && (
                        <Badge size="xs" colorPalette="orange">без графика</Badge>
                    )}
                </VStack>
            ),
        },
        {
            key: 'unpaid',
            label: 'Остаток',
            render: (_, row) => (
                <VStack align="end" gap={0}>
                    <Text fontSize="sm" fontWeight="600" whiteSpace="nowrap">
                        {formatRub(row.unpaid_rub)}
                    </Text>
                    {row.currency_code !== 'RUB' && (
                        <Text fontSize="10px" color="fg.muted" whiteSpace="nowrap">
                            {row.unpaid_amount} {row.currency_code}
                        </Text>
                    )}
                </VStack>
            ),
        },
        {
            key: 'actions',
            label: '',
            render: (_, row) => (canCreateTask ? (
                <Button
                    size="xs"
                    variant="ghost"
                    onClick={() => setTaskFor(row)}
                    title="Поставить задачу по оплате"
                >
                    <LuListPlus /> Задача
                </Button>
            ) : null),
        },
    ];

    return (
        <>
            <DataTable
                columns={columns}
                data={rows?.data ?? rows ?? []}
                pagination={rows?.data ? rows : null}
                onSort={onSort}
                sortColumn={sortColumn}
                sortDirection={sortDirection}
                emptyMessage={emptyMessage}
            />

            {taskFor && (
                <TaskDialog
                    open
                    onClose={() => setTaskFor(null)}
                    entity={{ type: 'shipment', id: taskFor.shipment.id }}
                    initialTitle={taskTitle(taskFor)}
                    onSaved={() => setTaskFor(null)}
                />
            )}
        </>
    );
}

/**
 * Заголовок задачи по умолчанию: менеджеру остаётся только выбрать исполнителя
 * и срок, а в ленте клиента видно, о каких деньгах речь.
 */
const taskTitle = (row) => {
    const base = `Оплата по реализации ${row.shipment.number} — ${formatRub(row.unpaid_rub)}`;

    return row.days_overdue > 0 ? `${base}, просрочка ${row.days_overdue} дн.` : base;
};
