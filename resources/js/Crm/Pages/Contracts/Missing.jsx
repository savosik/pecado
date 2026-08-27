import { Head, router } from '@inertiajs/react';
import { Badge, Box, HStack, Text, VStack } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { DataTable } from '@/Admin/Components/DataTable';
import { SearchInput } from '@/Admin/Components/SearchInput';
import ScopeToggle from '@/Crm/Components/ScopeToggle';
import ContractsTabs from '@/Crm/Pages/Contracts/components/ContractsTabs';
import RowActions from '@/shared/Panel/RowActions';
import { formatPrice } from '@/utils/formatPrice';
import { LuFilePlus } from 'react-icons/lu';

const selectStyle = {
    padding: '0.5rem',
    borderRadius: '0.375rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '160px',
};

/**
 * Вкладка «Без договора».
 *
 * Контрагенты, на которых проведена реализация или хотя бы заказ, а действующего
 * договора в реестре нет. Реализация без договора — красная строка, только
 * заказ — жёлтая: заказ ещё не долг, но уже повод подписать.
 */
export default function Missing({
    gaps,
    filters,
    categories = [],
    missingCount = 0,
    managers = [],
    can = {},
    canSeeDepartment = false,
    canFilterByManager = false,
}) {
    const apply = (patch) => {
        router.get(route('crm.contracts.missing'), { ...filters, ...patch, page: undefined }, {
            preserveState: true,
            replace: true,
        });
    };

    const columns = [
        {
            key: 'name',
            label: 'Контрагент',
            render: (_, row) => (
                <VStack align="start" gap={0}>
                    <HStack gap={2}>
                        <Text fontSize="sm" fontWeight="600">{row.name}</Text>
                        <Badge size="sm" colorPalette={row.severity_color}>{row.severity_label}</Badge>
                    </HStack>
                    {row.legal_name && row.legal_name !== row.name && <Text fontSize="xs" color="fg.muted">{row.legal_name}</Text>}
                    {row.tax_id && <Text fontSize="xs" color="fg.muted">ИНН {row.tax_id}</Text>}
                    {row.terminated_contracts_count > 0 && (
                        <Text fontSize="xs" color="red.500">расторгнутых договоров: {row.terminated_contracts_count}</Text>
                    )}
                </VStack>
            ),
        },
        {
            key: 'partner',
            label: 'Партнёр / менеджер',
            render: (_, row) => (
                <VStack align="start" gap={0}>
                    <Text fontSize="sm">{row.partner?.name || '—'}</Text>
                    <Text fontSize="xs" color="fg.muted">{row.manager?.name || 'без менеджера'}</Text>
                </VStack>
            ),
        },
        {
            key: 'shipments',
            label: 'Реализации',
            render: (_, row) => (row.shipments_count > 0
                ? (
                    <VStack align="start" gap={0}>
                        <Text fontSize="sm" fontWeight="600" color="red.500">{formatPrice(row.shipments_total)}</Text>
                        <Text fontSize="xs" color="fg.muted">{row.shipments_count} шт., последняя {row.last_shipment_at}</Text>
                    </VStack>
                )
                : <Text fontSize="xs" color="fg.muted">не было</Text>),
        },
        {
            key: 'orders',
            label: 'Заказы',
            render: (_, row) => (row.orders_count > 0
                ? (
                    <VStack align="start" gap={0}>
                        <Text fontSize="sm">{row.orders_count} шт.</Text>
                        <Text fontSize="xs" color="fg.muted">последний {row.last_order_at || '—'}</Text>
                    </VStack>
                )
                : <Text fontSize="xs" color="fg.muted">не было</Text>),
        },
        {
            key: 'actions',
            label: 'Действия',
            render: (_, row) => (
                <RowActions
                    size="xs"
                    view={{ href: route('crm.contractors.show', row.id) }}
                    extra={[
                        {
                            icon: LuFilePlus,
                            label: 'Завести договор',
                            allowed: !!can.create,
                            onClick: () => router.visit(route('crm.contracts.index', { create: 1, company_id: row.id })),
                        },
                    ]}
                />
            ),
        },
    ];

    return (
        <>
            <Head title="CRM — Без договора" />
            <PageHeader
                title="Договоры"
                description="Контрагенты с реализацией или заказом, по которым в реестре нет действующего договора"
            />

            <VStack align="stretch" gap={4}>
                <ContractsTabs
                    categories={categories}
                    missingCount={missingCount}
                    missingActive
                    scope={filters.scope}
                />

                <HStack gap={3} flexWrap="wrap" align="center">
                    <Box flex="1" minW="240px">
                        <SearchInput
                            value={filters.search || ''}
                            onChange={(value) => apply({ search: value || undefined })}
                            placeholder="Контрагент, ИНН, партнёр…"
                        />
                    </Box>

                    <select value={filters.kind || ''} onChange={(e) => apply({ kind: e.target.value || undefined })} style={selectStyle}>
                        <option value="">Реализации и заказы</option>
                        <option value="shipments">Только с реализацией</option>
                        <option value="orders">Только с заказом</option>
                    </select>

                    {canFilterByManager && (
                        <select value={filters.manager_id || ''} onChange={(e) => apply({ manager_id: e.target.value || undefined })} style={selectStyle}>
                            <option value="">Любой менеджер</option>
                            {managers.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
                        </select>
                    )}

                    <select value={filters.sort_by || 'shipments_total'} onChange={(e) => apply({ sort_by: e.target.value })} style={selectStyle}>
                        <option value="shipments_total">По сумме реализаций</option>
                        <option value="shipments_count">По числу реализаций</option>
                        <option value="last_shipment">По последней реализации</option>
                        <option value="orders_count">По числу заказов</option>
                        <option value="name">По названию</option>
                    </select>

                    <ScopeToggle section="contracts" scope={filters.scope} available={canSeeDepartment} />
                </HStack>

                <DataTable
                    data={gaps.data}
                    columns={columns}
                    pagination={gaps}
                    emptyMessage="У всех контрагентов с реализациями и заказами есть договор"
                />
            </VStack>
        </>
    );
}

Missing.layout = (page) => <CrmLayout>{page}</CrmLayout>;
