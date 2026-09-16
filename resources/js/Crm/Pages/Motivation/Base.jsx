import { Head, router } from '@inertiajs/react';
import { Box, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Alert } from '@/components/ui/alert';
import MetricHint from '@/Crm/Components/MetricHint';
import PartnerTable from './components/PartnerTable';
import PartnerActions, { usePartnerDialogs } from './components/PartnerActions';
import { Assortment, Debt, LastPurchase, Money, PartnerName } from './components/partnerCells';
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
    { key: 'all', label: 'Все', field: 'total' },
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
export default function MotivationBase({ tab_counts: tabCounts = null, month, month_label: monthLabel, manager, scope_options: scopeOptions, can_see_all: canSeeAll, query, list }) {
    const { dialogs, setTaskFor, setCallFor } = usePartnerDialogs();
    const summary = list?.summary ?? {};
    const filter = list?.filter ?? 'all';

    const navigate = (changes) => {
        const params = { month, ...query, ...changes };
        if (canSeeAll && manager?.id && !('manager' in changes)) params.manager = manager.id;
        Object.keys(params).forEach((k) => { if (params[k] === '' || params[k] === null || params[k] === undefined) delete params[k]; });
        router.get('/crm/motivation/base', params, { preserveState: true, preserveScroll: true, replace: true });
    };

    // «Принёс» начисляется только после порога оплаты: пока база ниже M % плана — сноска «пока 0».
    const threshold = summary?.threshold ?? null;
    const thresholdReached = threshold ? threshold.reached : true;
    const thresholdHint = threshold
        ? `Отгрузки партнёра в этом месяце × ваша ставка. Начисляется, когда отгрузки всей базы дойдут до ${threshold.percent} % плана${threshold.plan ? ` (${Math.round(threshold.plan * threshold.percent / 100).toLocaleString('ru-RU')} ₽ из ${Math.round(threshold.plan).toLocaleString('ru-RU')} ₽)` : ''}: сейчас ${Math.round(threshold.shipped).toLocaleString('ru-RU')} ₽ — ${threshold.reached ? 'порог пройден' : 'порог не пройден, пока 0'}.`
        : 'Отгрузки партнёра в этом месяце × ваша ставка. Начисляется после порога оплаты; расчёта за месяц ещё нет.';

    const rub = (v) => `${Math.round(v).toLocaleString('ru-RU')} ₽`;
    const ratePercent = (row) => `${(Number(row.rate ?? 0) * 100).toLocaleString('ru-RU', { maximumFractionDigits: 2 })} %`;

    // Колонки сгруппированы парами «сколько взял → сколько это вам»: верхняя строка —
    // деньги партнёра, нижняя — ваши. Сортировка — по верхней строке группы.
    const columns = [
        { key: 'name', label: 'Партнёр и группа', sortable: true, hint: 'Под именем — ставка вашего вознаграждения с выручки этого партнёра: П2 в периоде новизны, иначе П1. Порог оплаты здесь не учитывается.', render: (row) => (
            <VStack align="start" gap={0.5}>
                <PartnerName row={row} />
                <Text fontSize="xs" color={row.in_novelty ? 'purple.fg' : 'fg.muted'}>ставка {ratePercent(row)}</Text>
            </VStack>
        ) },
        { key: 'usual_monthly', label: 'Обычно берёт в месяц', sub: 'обычно приносит вам', align: 'right', sortable: true, hint: 'Верхняя строка — обычная закупка партнёра в месяц. Нижняя — она же × ваша ставка: столько партнёр приносит вам в обычный месяц.', render: (row) => (
            <VStack align="end" gap={0}>
                <Money value={row.usual_monthly} />
                <Text fontSize="xs" fontWeight="600" color={row.usual_gain > 0 ? 'green.fg' : 'fg.subtle'} fontVariantNumeric="tabular-nums">{row.usual_gain > 0 ? rub(row.usual_gain) : '—'}</Text>
            </VStack>
        ) },
        { key: 'current_month', label: 'Взял в этом месяце', sub: 'принёс · отнял', align: 'right', sortable: true, hint: `Верхняя строка — отгрузки партнёра в этом месяце. Ниже: сколько это вам (${thresholdHint}) и сколько снял его просроченный долг (вычет К1: остаток × ставка в день × дни просрочки, независимо от порога).`, render: (row) => (
            <VStack align="end" gap={0}>
                <Money value={row.current_month} strong />
                <HStack gap={1.5} fontSize="xs" fontVariantNumeric="tabular-nums">
                    <Text fontWeight="600" color={row.current_gain > 0 ? (thresholdReached ? 'green.fg' : 'fg.muted') : 'fg.subtle'}>{row.current_gain > 0 ? `+${rub(row.current_gain)}` : '—'}</Text>
                    {row.current_gain > 0 && !thresholdReached && <Text color="orange.fg">(пока 0)</Text>}
                    {row.k1_deduction > 0 && <Text fontWeight="600" color="red.fg">−{rub(row.k1_deduction)}</Text>}
                </HStack>
            </VStack>
        ) },
        { key: 'best_month', label: 'Лучший месяц', sub: 'потенциал · может дать', align: 'right', sortable: true, hint: 'Верхняя строка — лучший месяц партнёра за два года и когда он был. Ниже: потенциал (лучший месяц минус закупка в этом месяце) и сколько это вам по ставке.', render: (row) => (
            <VStack align="end" gap={0}>
                <HStack gap={1.5} fontSize="sm" fontVariantNumeric="tabular-nums">
                    <Text>{row.best_month?.amount ? rub(row.best_month.amount) : '—'}</Text>
                    {row.best_month?.period && <Text fontSize="xs" color="fg.subtle">{String(row.best_month.period).split('-').reverse().join('.')}</Text>}
                </HStack>
                <HStack gap={1.5} fontSize="xs" fontVariantNumeric="tabular-nums">
                    <Text color="fg.muted">{row.potential > 0 ? rub(row.potential) : '—'}</Text>
                    <Text fontWeight="600" color={row.your_gain > 0 ? 'green.fg' : 'fg.subtle'}>{row.your_gain > 0 ? `+${rub(row.your_gain)}` : ''}</Text>
                </HStack>
            </VStack>
        ) },
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
            <MotivationTabs hub="clients" current="base" counts={tabCounts} />

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
