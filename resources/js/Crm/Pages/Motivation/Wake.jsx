import { Head, router } from '@inertiajs/react';
import { Box, HStack, Text, VStack } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Alert } from '@/components/ui/alert';
import MetricHint from '@/Crm/Components/MetricHint';
import PartnerTable from './components/PartnerTable';
import PartnerActions, { usePartnerDialogs } from './components/PartnerActions';
import { BestMonth, LastPurchase, Money, PartnerName } from './components/partnerCells';
import { fmtRub0, plural } from '../Salary/components/format';
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

const TABS = [
    { key: 'never', label: 'Ни разу не покупали', hint: 'Первая покупка даёт повышенную ставку П2 на полгода' },
    { key: 'silent', label: 'Молчат дольше трёх месяцев', hint: 'Повышенной ставки не дают, но вернуть их стоит' },
];

/**
 * «Кого разбудить»: кто из забытых партнёров вернётся выгоднее всего.
 *
 * Две вкладки, которые нельзя смешивать: карточки без единой продажи —
 * это работа с чистого листа и повышенная ставка; молчащие — возврат
 * к обычным закупкам по обычной ставке.
 */
export default function MotivationWake({ month, month_label: monthLabel, manager, scope_options: scopeOptions, can_see_all: canSeeAll, query, list }) {
    const { dialogs, setTaskFor, setCallFor } = usePartnerDialogs();
    const tab = list?.filter ?? 'never';
    const summary = list?.summary ?? {};

    const navigate = (changes) => {
        const params = { month, ...query, ...changes };
        if (canSeeAll && manager?.id && !('manager' in changes)) params.manager = manager.id;
        Object.keys(params).forEach((k) => { if (params[k] === '' || params[k] === null || params[k] === undefined) delete params[k]; });
        router.get('/crm/motivation/wake', params, { preserveState: true, preserveScroll: true, replace: true });
    };

    const contact = (row) => (
        <VStack align="start" gap={0} fontSize="xs">
            {row.phone ? <Text>{row.phone}</Text> : null}
            {row.email ? <Text color="fg.muted">{row.email}</Text> : null}
            {!row.phone && !row.email ? <Text color="fg.subtle">контактов нет</Text> : null}
        </VStack>
    );

    const columns = tab === 'silent'
        ? [
            { key: 'name', label: 'Партнёр', sortable: true, render: (row) => <PartnerName row={row} /> },
            { key: 'last_purchase_on', label: 'Последняя покупка', sortable: true, render: (row) => <LastPurchase row={row} /> },
            { key: 'silent_days', label: 'Не покупает', align: 'right', sortable: true, render: (row) => <Text fontSize="sm">{row.silent_months} {plural(row.silent_months, 'месяц', 'месяца', 'месяцев')}</Text> },
            { key: 'best_month', label: 'Раньше брал', align: 'right', sortable: true, render: (row) => <BestMonth value={row.best_month} /> },
            { key: 'top_products', label: 'Что брал', render: (row) => (
                <VStack align="start" gap={0} fontSize="xs" maxW="260px">
                    {(row.top_products ?? []).map((p) => <Text key={p.name} truncate maxW="260px">{p.name} · {fmtRub0(p.amount)}</Text>)}
                    {(row.top_products ?? []).length === 0 && <Text color="fg.subtle">—</Text>}
                </VStack>
            ) },
            { key: 'contact', label: 'Контакт', render: contact },
            { key: 'actions', label: 'Действия', align: 'right', render: (row) => <PartnerActions row={row} onTask={setTaskFor} onCall={setCallFor} /> },
        ]
        : [
            { key: 'name', label: 'Партнёр', sortable: true, render: (row) => <PartnerName row={row} /> },
            { key: 'debt', label: 'Долг', align: 'right', sortable: true, render: (row) => <Money value={row.debt?.amount} muted /> },
            { key: 'contact', label: 'Контакт', render: contact },
            { key: 'actions', label: 'Действия', align: 'right', render: (row) => <PartnerActions row={row} onTask={setTaskFor} onCall={setCallFor} /> },
        ];

    return (
        <CrmLayout breadcrumbs={hubBreadcrumbs('clients', 'wake')}>
            <Head title="Кого разбудить — CRM" />
            <PageHeader
                title={canSeeAll && manager ? `Разбудить: ${manager.name}` : 'Кого разбудить'}
                description="Кто из забытых партнёров вернётся выгоднее всего."
                actions={(
                    <HStack gap={2} flexWrap="wrap">
                        <input type="search" aria-label="Поиск по партнёрам" placeholder="Найти партнёра…" style={selectStyle} defaultValue={query?.search ?? ''} onKeyDown={(e) => { if (e.key === 'Enter') navigate({ search: e.target.value, page: undefined }); }} />
                        {canSeeAll && (scopeOptions ?? []).length > 0 && (
                            <select aria-label="Работник" style={selectStyle} value={manager?.id ?? ''} onChange={(e) => navigate({ manager: e.target.value, page: undefined })}>
                                <option value="">Выберите работника…</option>
                                {scopeOptions.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                            </select>
                        )}
                    </HStack>
                )}
            />
            <MotivationTabs hub="clients" current="wake" />

            <VStack align="stretch" gap={4}>
                {manager === null && (
                    <Alert status="info" title="Карточка работника не привязана">Выберите работника выше или обратитесь к руководителю.</Alert>
                )}

                {list && (
                    <>
                        <HStack gap={2} flexWrap="wrap">
                            {TABS.map((t) => (
                                <Box
                                    key={t.key}
                                    as="button"
                                    type="button"
                                    px={4}
                                    py={2}
                                    borderRadius="xl"
                                    borderWidth="2px"
                                    borderColor={tab === t.key ? 'blue.solid' : 'border'}
                                    bg="bg.panel"
                                    textAlign="left"
                                    cursor="pointer"
                                    onClick={() => navigate({ tab: t.key, page: undefined, sort: undefined, direction: undefined })}
                                    aria-pressed={tab === t.key}
                                >
                                    <Text fontWeight="700" fontSize="sm">{t.label} · {summary[t.key] ?? 0}</Text>
                                    <Text fontSize="xs" color="fg.muted">{t.hint}</Text>
                                </Box>
                            ))}
                        </HStack>

                        <HStack gap={2} fontSize="xs" color="fg.muted">
                            <Text>{monthLabel} · в списке {list.rows.total}</Text>
                            <MetricHint text={list.hint} />
                        </HStack>

                        <PartnerTable
                            list={list}
                            columns={columns}
                            onSort={(column, direction) => navigate({ sort: column, direction, page: undefined })}
                            onPage={(page) => navigate({ page })}
                            emptyTitle={tab === 'silent' ? 'Молчащих дольше трёх месяцев нет' : 'Все ваши партнёры хоть раз покупали'}
                            emptyText={tab === 'silent' ? 'Все покупавшие партнёры отгружались за последние три месяца.' : 'Карточек без единой продажи за вами не числится.'}
                        />
                    </>
                )}
            </VStack>

            {dialogs}
        </CrmLayout>
    );
}
