import { Head, router } from '@inertiajs/react';
import { Box, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Alert } from '@/components/ui/alert';
import MetricHint from '@/Crm/Components/MetricHint';
import PartnerTable from './components/PartnerTable';
import PartnerActions, { usePartnerDialogs } from './components/PartnerActions';
import { Assortment, BestMonth, Debt, LastPurchase, Money, PartnerName } from './components/partnerCells';
import MotivationTabs from './components/MotivationTabs';
import { hubBreadcrumbs } from './components/hubs';

const selectStyle = {
    padding: '0.45rem 0.6rem',
    borderRadius: '0.5rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '180px',
    background: 'var(--chakra-colors-bg-panel)',
    color: 'var(--chakra-colors-fg)',
};

const FILTERS = [
    { key: 'bought', label: 'Покупали хоть раз', field: null },
    { key: 'active', label: 'Покупали в этом месяце', field: 'active' },
    { key: 'silent', label: 'Не покупали в этом месяце', field: 'silent' },
    { key: 'never', label: 'Ни разу не покупали', field: 'never_bought' },
];

/**
 * «Моя база»: кто мои партнёры, кто из них работает, а кто нет.
 *
 * Партнёры без единой отгрузки — отдельной вкладкой, а не вперемешку:
 * у одного работника их 58 из 113, у другого 50 из 103, и смешивать их
 * с работающими значит превратить рабочий список в справочник.
 */
export default function MotivationBase({ month, month_label: monthLabel, manager, scope_options: scopeOptions, can_see_all: canSeeAll, query, list }) {
    const { dialogs, setTaskFor, setCallFor } = usePartnerDialogs();
    const summary = list?.summary ?? {};
    const filter = list?.filter ?? 'bought';

    const navigate = (changes) => {
        const params = { month, ...query, ...changes };
        if (canSeeAll && manager?.id && !('manager' in changes)) params.manager = manager.id;
        Object.keys(params).forEach((k) => { if (params[k] === '' || params[k] === null || params[k] === undefined) delete params[k]; });
        router.get('/crm/motivation/base', params, { preserveState: true, preserveScroll: true, replace: true });
    };

    const columns = [
        { key: 'name', label: 'Партнёр', sortable: true, render: (row) => <PartnerName row={row} /> },
        { key: 'usual_monthly', label: 'Обычно берёт в месяц', align: 'right', sortable: true, render: (row) => <Money value={row.usual_monthly} /> },
        { key: 'current_month', label: 'Взял в этом месяце', align: 'right', sortable: true, render: (row) => <Money value={row.current_month} strong /> },
        { key: 'best_month', label: 'Лучший месяц', align: 'right', sortable: true, render: (row) => <BestMonth value={row.best_month} /> },
        { key: 'potential', label: 'Потенциал', align: 'right', sortable: true, render: (row) => <Money value={row.potential} /> },
        { key: 'your_gain', label: 'Даст вам', align: 'right', sortable: true, render: (row) => <Text fontSize="sm" fontWeight="700" color={row.your_gain > 0 ? 'green.fg' : 'fg.subtle'} fontVariantNumeric="tabular-nums">{row.your_gain > 0 ? `+${Math.round(row.your_gain).toLocaleString('ru-RU')} ₽` : '—'}</Text> },
        { key: 'assortment', label: 'Ассортимент', align: 'right', sortable: true, render: (row) => <Assortment value={row.assortment} /> },
        { key: 'last_purchase_on', label: 'Последняя покупка', sortable: true, render: (row) => <LastPurchase row={row} /> },
        { key: 'debt', label: 'Долг', align: 'right', sortable: true, render: (row) => <Debt value={row.debt} /> },
        { key: 'actions', label: 'Действия', align: 'right', render: (row) => <PartnerActions row={row} onTask={setTaskFor} onCall={setCallFor} /> },
    ];

    return (
        <CrmLayout breadcrumbs={hubBreadcrumbs('clients', 'base')}>
            <Head title="Моя база — CRM" />
            <PageHeader
                title={canSeeAll && manager ? `База: ${manager.name}` : 'Моя база'}
                description="Кто ваши партнёры, кто из них работает, а кто нет — и сколько каждый может дать."
                actions={(
                    <HStack gap={2} flexWrap="wrap">
                        <input
                            type="search"
                            aria-label="Поиск по партнёрам"
                            placeholder="Найти партнёра…"
                            style={selectStyle}
                            defaultValue={query?.search ?? ''}
                            onKeyDown={(e) => { if (e.key === 'Enter') navigate({ search: e.target.value, page: undefined }); }}
                        />
                        {canSeeAll && (scopeOptions ?? []).length > 0 && (
                            <select aria-label="Работник" style={selectStyle} value={manager?.id ?? ''} onChange={(e) => navigate({ manager: e.target.value, page: undefined })}>
                                <option value="">Выберите работника…</option>
                                {scopeOptions.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                            </select>
                        )}
                    </HStack>
                )}
            />
            <MotivationTabs hub="clients" current="base" />

            <VStack align="stretch" gap={4}>
                {manager === null && (
                    <Alert status="info" title="Карточка работника не привязана">
                        Выберите работника выше или обратитесь к руководителю.
                    </Alert>
                )}

                {list && (
                    <>
                        <SimpleGrid columns={{ base: 2, md: 4 }} gap={3}>
                            {FILTERS.map((f) => {
                                const active = filter === f.key;
                                const count = f.field ? summary[f.field] : (summary.total ?? 0) - (summary.never_bought ?? 0);

                                return (
                                    <Box
                                        key={f.key}
                                        as="button"
                                        type="button"
                                        textAlign="left"
                                        bg="bg.panel"
                                        borderWidth="2px"
                                        borderColor={active ? 'blue.solid' : 'border'}
                                        borderRadius="xl"
                                        p={3}
                                        cursor="pointer"
                                        _hover={{ bg: 'bg.subtle' }}
                                        onClick={() => navigate({ filter: f.key, page: undefined })}
                                        aria-pressed={active}
                                    >
                                        <Text fontSize="xs" color="fg.muted">{f.label}</Text>
                                        <Text fontSize="2xl" fontWeight="800" fontVariantNumeric="tabular-nums">{count ?? 0}</Text>
                                    </Box>
                                );
                            })}
                        </SimpleGrid>

                        <HStack gap={2} fontSize="xs" color="fg.muted" flexWrap="wrap">
                            <Text>{monthLabel} · партнёров в списке: {list.rows.total}{summary.in_novelty > 0 ? ` · в периоде новизны: ${summary.in_novelty}` : ''}</Text>
                            <MetricHint text={list.hint} />
                        </HStack>

                        <PartnerTable
                            list={list}
                            columns={columns}
                            onSort={(column, direction) => navigate({ sort: column, direction, page: undefined })}
                            onPage={(page) => navigate({ page })}
                            emptyTitle={filter === 'never' ? 'Все партнёры хоть раз покупали' : 'В этой группе никого нет'}
                            emptyText={filter === 'never' ? 'Партнёров без единой отгрузки за вами не числится.' : 'Попробуйте другую группу или снимите поиск.'}
                        />
                    </>
                )}
            </VStack>

            {dialogs}
        </CrmLayout>
    );
}
