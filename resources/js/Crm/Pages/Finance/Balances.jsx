import { useState } from 'react';
import { Head } from '@inertiajs/react';
import { Badge, Box, HStack, Text, VStack } from '@chakra-ui/react';
import { LuListPlus } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { DataTable } from '@/Admin/Components/DataTable';
import { Button } from '@/components/ui/button';
import TaskDialog from '@/Crm/Components/TaskDialog';
import { usePermission } from '@/shared/Panel/usePermission';
import FinanceTabs from './components/FinanceTabs';
import FinanceFilterBar from './components/FinanceFilterBar';
import { formatRub } from './components/format';

/**
 * Балансы взаиморасчётов клиентов — проекция из 1С.
 *
 * Сальдо и просрочку считает учётная система; сайт показывает их как есть и
 * рядом — свою просрочку по графику оплаты. Расхождение это не поломка: 1С может
 * учитывать документы, которых на сайте нет, — но именно оно повод свериться.
 */
export default function FinanceBalances({
    balances = [],
    filters = {},
    managers = [],
    organizations = [],
    seesAll = false,
}) {
    const { can } = usePermission();
    const [taskFor, setTaskFor] = useState(null);

    const columns = [
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
                    <Text fontSize="10px" color="fg.muted">
                        {row.tax_id ? `ИНН ${row.tax_id}` : 'без ИНН'}
                        {row.manager_name ? ` · ${row.manager_name}` : ''}
                    </Text>
                </VStack>
            ),
        },
        {
            key: 'current_balance',
            label: 'Сальдо',
            render: (value) => (
                <Text fontSize="sm" fontWeight="600" color={value < 0 ? 'red.fg' : undefined} whiteSpace="nowrap">
                    {formatRub(value)}
                </Text>
            ),
        },
        {
            key: 'overdue_debt',
            label: 'Просрочено (1С)',
            render: (value) => (
                <Text fontSize="sm" fontWeight="600" color={value > 0 ? 'red.fg' : 'fg.muted'} whiteSpace="nowrap">
                    {formatRub(value)}
                </Text>
            ),
        },
        {
            key: 'overdue_by_schedule',
            label: 'Просрочено (график)',
            render: (value) => (
                <Text fontSize="sm" whiteSpace="nowrap">{formatRub(value)}</Text>
            ),
        },
        {
            key: 'overdue_diff',
            label: 'Расхождение',
            render: (value) => (Math.abs(value) < 0.01 ? (
                <Text fontSize="xs" color="fg.muted">—</Text>
            ) : (
                <Badge size="xs" colorPalette="orange">{formatRub(value)}</Badge>
            )),
        },
        {
            key: 'erp_updated_at',
            label: 'Данные 1С от',
            render: (value) => <Text fontSize="xs" color="fg.muted">{value || '—'}</Text>,
        },
        {
            key: 'actions',
            label: '',
            render: (_, row) => (can('crm-tasks.create') ? (
                <Button size="xs" variant="ghost" onClick={() => setTaskFor(row)}>
                    <LuListPlus /> Задача
                </Button>
            ) : null),
        },
    ];

    return (
        <CrmLayout breadcrumbs={[{ label: 'Финансы', href: '/crm/finance' }, { label: 'Балансы клиентов' }]}>
            <Head title="Балансы клиентов — CRM" />

            <PageHeader
                title="Балансы клиентов"
                description="Сальдо взаиморасчётов и просроченная задолженность по данным 1С"
            />

            <FinanceTabs active="balances" />

            <FinanceFilterBar
                routeName="crm.finance.balances"
                filters={filters}
                managers={managers}
                organizations={organizations}
                seesAll={seesAll}
            />

            <Box mb={3}>
                <Text fontSize="xs" color="fg.muted">
                    Отрицательное сальдо — долг клиента. Суммы приходят из 1С и подписаны как рубли:
                    для мультивалютного контрагента учётная система валюту не передаёт.
                </Text>
            </Box>

            <DataTable
                columns={columns}
                data={balances}
                emptyMessage="Балансы по вашим клиентам ещё не приходили из 1С"
            />

            {taskFor && (
                <TaskDialog
                    open
                    onClose={() => setTaskFor(null)}
                    entity={{ type: 'client', id: taskFor.client.id }}
                    initialTitle={`Дебиторка: ${taskFor.client.name} — ${formatRub(taskFor.overdue_debt)}`}
                    onSaved={() => setTaskFor(null)}
                />
            )}
        </CrmLayout>
    );
}
