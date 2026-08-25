import { Head } from '@inertiajs/react';
import { Box, Flex, SimpleGrid, Text } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import FinanceFilterBar from './components/FinanceFilterBar';
import FinanceRowsTable from './components/FinanceRowsTable';
import { formatRub } from './components/format';

/**
 * План поступлений построчно: когда, от кого и сколько ждём.
 *
 * Долг реализаций без графика вынесен отдельным блоком под таблицей: плановой даты
 * у него нет, и в отсортированном по дате списке он висел бы непонятным хвостом.
 */
export default function FinancePlan({
    rows,
    summary = {},
    noSchedule = null,
    filters = {},
    managers = [],
    organizations = [],
    seesAll = false,
}) {
    return (
        <CrmLayout breadcrumbs={[{ label: 'Финансы' }, { label: 'План поступлений' }]}>
            <Head title="План поступлений — CRM" />

            <PageHeader
                title="План поступлений"
                description="Ожидаемые платежи по графику оплаты реализаций из 1С"
            />

            <FinanceFilterBar
                routeName="crm.finance.plan"
                filters={filters}
                managers={managers}
                organizations={organizations}
                seesAll={seesAll}
                showOverdueToggle
            />

            <SimpleGrid columns={{ base: 1, md: 3 }} gap={3} mb={4}>
                <Tile label="Ожидается за период" value={formatRub(summary.expected_period)} />
                <Tile label="В том числе просрочено" value={formatRub(summary.overdue_amount)} />
                <Tile label="Долг без графика оплаты" value={formatRub(summary.no_schedule_amount)} />
            </SimpleGrid>

            <FinanceRowsTable
                rows={rows}
                emptyMessage="В выбранном периоде поступлений не ожидается"
            />

            {noSchedule && noSchedule.count > 0 && (
                <Box mt={6}>
                    <Flex justify="space-between" align="baseline" mb={2}>
                        <Text fontWeight="600">Долг без графика оплаты</Text>
                        <Text fontSize="sm" color="fg.muted">
                            {noSchedule.count} реализаций на {formatRub(noSchedule.amount)}
                        </Text>
                    </Flex>

                    <Text fontSize="xs" color="fg.muted" mb={3}>
                        По этим документам 1С не прислала «Правила оплаты», поэтому плановой даты нет
                        и в календарь они не попадают. Показаны последние {noSchedule.rows.length}.
                    </Text>

                    <FinanceRowsTable rows={noSchedule.rows} emptyMessage="Таких реализаций нет" />
                </Box>
            )}
        </CrmLayout>
    );
}

const Tile = ({ label, value }) => (
    <Box borderWidth="1px" borderRadius="lg" p={4}>
        <Text fontSize="xs" color="fg.muted">{label}</Text>
        <Text fontSize="xl" fontWeight="700" mt={1}>{value}</Text>
    </Box>
);
