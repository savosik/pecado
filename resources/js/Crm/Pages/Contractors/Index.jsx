import { useCallback, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { Badge, Box, HStack, Text, VStack } from '@chakra-ui/react';
import { LuEye } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { DataTable } from '@/Admin/Components/DataTable';
import { Button } from '@/components/ui/button';
import { usePermission } from '@/shared/Panel/usePermission';
import { useResourceIndex } from '@/Admin/hooks/useResourceIndex';
import TaskDialog from '@/Crm/Components/TaskDialog';
import ContractorsFilterBar from './components/ContractorsFilterBar';
import { formatPrice } from '@/utils/formatPrice';

export default function Index({ contractors, filters, managers = [], canSeeAll = false }) {
    const { can } = usePermission();
    // Раздел ничего не удаляет — контрагенты принадлежат 1С. Из хука берём
    // только поиск и сортировку.
    const { searchQuery, handleSearch, handleSort } = useResourceIndex('crm.contractors', filters, {
        entityLabel: 'Контрагент',
    });

    const [taskFor, setTaskFor] = useState(null);
    const canCreateTask = can('crm-tasks.create');

    const applyFilters = useCallback((patch) => {
        router.get(route('crm.contractors.index'), { ...filters, ...patch }, {
            preserveState: true,
            replace: true,
        });
    }, [filters]);

    const resetFilters = useCallback(() => {
        router.get(route('crm.contractors.index'), { per_page: filters.per_page }, {
            preserveState: false,
            replace: true,
        });
    }, [filters.per_page]);

    const columns = [
        {
            key: 'name',
            label: 'Контрагент',
            sortable: true,
            render: (_, row) => (
                <VStack align="start" gap={0}>
                    <HStack gap={2}>
                        <Text fontWeight="semibold">{row.name}</Text>
                        {row.is_default && (
                            <Badge colorPalette="blue" variant="subtle" size="sm">основной</Badge>
                        )}
                    </HStack>
                    {/* Юридическое наименование — то, что стоит в договоре;
                        в списке оно нужно, чтобы отличить одноимённые ООО. */}
                    {row.legal_name && row.legal_name !== row.name && (
                        <Text fontSize="xs" color="fg.muted">{row.legal_name}</Text>
                    )}
                </VStack>
            ),
        },
        {
            key: 'tax_id',
            label: 'ИНН / КПП',
            render: (_, row) => (
                <VStack align="start" gap={0}>
                    <Text fontSize="sm" fontFamily="mono">{row.tax_id || '—'}</Text>
                    {row.tax_code && (
                        <Text fontSize="xs" fontFamily="mono" color="fg.muted">{row.tax_code}</Text>
                    )}
                </VStack>
            ),
        },
        {
            key: 'partner',
            label: 'Партнёр',
            render: (_, row) => (row.partner
                ? (
                    <Link href={route('crm.clients.show', row.partner.id)}>
                        <Text fontSize="sm" color="blue.500" _hover={{ textDecoration: 'underline' }}>
                            {row.partner.name}
                        </Text>
                    </Link>
                )
                : <Badge colorPalette="orange" variant="subtle" size="sm">без партнёра</Badge>),
        },
        {
            key: 'balance',
            label: 'Баланс',
            sortable: true,
            render: (_, row) => (row.balance === null
                ? <Text fontSize="sm" color="fg.muted">—</Text>
                : (
                    <Text fontSize="sm" color={row.balance < 0 ? 'red.500' : undefined}>
                        {formatPrice(row.balance)}
                    </Text>
                )),
        },
        {
            key: 'overdue',
            label: 'Просрочка',
            sortable: true,
            render: (_, row) => (row.overdue_debt
                ? <Text fontSize="sm" fontWeight="semibold" color="red.500">{formatPrice(row.overdue_debt)}</Text>
                : <Text fontSize="sm" color="fg.muted">—</Text>),
        },
        {
            key: 'tasks',
            label: 'Задачи',
            sortable: true,
            render: (_, row) => (
                <HStack gap={2}>
                    {row.open_tasks_count > 0
                        ? <Badge colorPalette="purple" variant="subtle">{row.open_tasks_count}</Badge>
                        : <Text fontSize="sm" color="fg.muted">—</Text>}
                    {canCreateTask && (
                        <Button size="xs" variant="ghost" onClick={() => setTaskFor(row)}>
                            + задача
                        </Button>
                    )}
                </HStack>
            ),
        },
        {
            key: 'comments_count',
            label: 'Комментарии',
            render: (_, row) => (row.comments_count > 0
                ? <Text fontSize="sm">{row.comments_count}</Text>
                : <Text fontSize="sm" color="fg.muted">—</Text>),
        },
        {
            key: 'actions',
            label: '',
            render: (_, row) => (
                <Button
                    size="xs"
                    variant="ghost"
                    onClick={() => router.visit(route('crm.contractors.show', row.id))}
                    aria-label="Открыть карточку контрагента"
                >
                    <LuEye />
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title="CRM — Контрагенты" />
            <PageHeader
                title="Контрагенты"
                description={canSeeAll
                    ? 'Юрлица партнёров отдела: реквизиты, долг из 1С и переписка по конкретному юрлицу'
                    : 'Юрлица ваших партнёров: реквизиты, долг из 1С и переписка по конкретному юрлицу'}
            />

            {/* Планов по контрагентам нет намеренно: они считаются по партнёру.
                Пишем это прямо здесь, чтобы вопрос «а где план?» не возникал. */}
            <Box mb={4}>
                <Text fontSize="xs" color="fg.muted">
                    План и его выполнение считаются по партнёру — у одного партнёра юрлиц может быть несколько.
                    Смотреть выполнение: раздел «Планы продаж».
                </Text>
            </Box>

            <Box mb={4}>
                <ContractorsFilterBar
                    filters={filters}
                    searchQuery={searchQuery}
                    onSearch={handleSearch}
                    onChange={applyFilters}
                    onReset={resetFilters}
                    managers={managers}
                    canSeeAll={canSeeAll}
                />
            </Box>

            <DataTable
                data={contractors.data}
                columns={columns}
                pagination={contractors}
                sortColumn={filters.sort_by}
                sortDirection={filters.sort_order}
                onSort={handleSort}
                perPage={filters.per_page}
                onPerPageChange={(perPage) => applyFilters({ per_page: perPage })}
                emptyMessage="Контрагенты не найдены"
            />

            <TaskDialog
                open={taskFor !== null}
                entity={taskFor ? { type: 'contractor', id: taskFor.id } : null}
                onClose={() => setTaskFor(null)}
                onSaved={() => {
                    setTaskFor(null);
                    // Счётчик открытых задач считается на сервере — пересобирать
                    // его в стейте значит завести вторую правду.
                    router.reload({ only: ['contractors'] });
                }}
            />
        </>
    );
}

Index.layout = (page) => <CrmLayout>{page}</CrmLayout>;
