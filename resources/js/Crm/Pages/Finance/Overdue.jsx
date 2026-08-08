import { Head } from '@inertiajs/react';
import { Box, Flex, HStack, SimpleGrid, Text } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import FinanceTabs from './components/FinanceTabs';
import FinanceFilterBar from './components/FinanceFilterBar';
import FinanceRowsTable from './components/FinanceRowsTable';
import { formatRub } from './components/format';

/**
 * Просроченные платежи — те же строки графика, но с прошедшей плановой датой.
 *
 * Период фильтров на список не влияет: просрочка нужна вся, иначе часть долга
 * пропадала бы из виду при смене окна на пульте.
 */
export default function FinanceOverdue({
    rows,
    summary = {},
    aging = null,
    filters = {},
    managers = [],
    organizations = [],
    seesAll = false,
}) {
    return (
        <CrmLayout breadcrumbs={[{ label: 'Финансы', href: '/crm/finance' }, { label: 'Просрочка' }]}>
            <Head title="Просроченные платежи — CRM" />

            <PageHeader
                title="Просроченные платежи"
                description="Деньги, которые должны были прийти, но не пришли"
            />

            <FinanceTabs active="overdue" />

            <FinanceFilterBar
                routeName="crm.finance.overdue"
                filters={filters}
                managers={managers}
                organizations={organizations}
                seesAll={seesAll}
            />

            <SimpleGrid columns={{ base: 1, md: 3 }} gap={3} mb={4}>
                <Tile label="Просрочено всего" value={formatRub(summary.overdue_amount)} tone="red" />
                <Tile label="Документов" value={String(summary.overdue_count || 0)} />
                <Tile label="Клиентов" value={String(summary.overdue_clients || 0)} />
            </SimpleGrid>

            {aging && (
                <Box borderWidth="1px" borderRadius="lg" p={4} mb={4}>
                    <Text fontWeight="600" mb={3}>По срокам задержки</Text>

                    <SimpleGrid columns={{ base: 1, md: 5 }} gap={3}>
                        {aging.buckets.map((bucket) => (
                            <Box key={bucket.key}>
                                <Text fontSize="xs" color="fg.muted">{bucket.label}</Text>
                                <Text fontSize="md" fontWeight="600">{formatRub(bucket.amount)}</Text>
                                <Text fontSize="10px" color="fg.muted">{bucket.count} стр.</Text>
                            </Box>
                        ))}
                    </SimpleGrid>
                </Box>
            )}

            <Flex justify="space-between" align="baseline" mb={2}>
                <Text fontWeight="600">Строки к взысканию</Text>
                <HStack gap={2}>
                    <Text fontSize="xs" color="fg.muted">
                        Сортировка — по плановой дате: сверху самые давние
                    </Text>
                </HStack>
            </Flex>

            <FinanceRowsTable rows={rows} emptyMessage="Просроченных платежей нет" />
        </CrmLayout>
    );
}

const Tile = ({ label, value, tone }) => (
    <Box borderWidth="1px" borderRadius="lg" p={4} bg={tone === 'red' ? 'red.subtle' : undefined}>
        <Text fontSize="xs" color="fg.muted">{label}</Text>
        <Text fontSize="xl" fontWeight="700" mt={1} color={tone === 'red' ? 'red.fg' : undefined}>{value}</Text>
    </Box>
);
