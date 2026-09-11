import { Head, router } from '@inertiajs/react';
import { Box, HStack, Text, VStack } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Alert } from '@/components/ui/alert';
import MetricHint from '@/Crm/Components/MetricHint';
import PartnerTable from './components/PartnerTable';
import PartnerActions, { usePartnerDialogs } from './components/PartnerActions';
import { LastTouch, Money, PartnerName } from './components/partnerCells';
import { fmtRub0, plural } from '../Salary/components/format';

const selectStyle = {
    padding: '0.45rem 0.6rem',
    borderRadius: '0.5rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '180px',
    background: 'var(--chakra-colors-bg-panel)',
    color: 'var(--chakra-colors-fg)',
};

const FLAGS = [
    { key: 'drop', label: 'Просели на четверть и больше', palette: 'orange' },
    { key: 'stopped', label: 'Перестали покупать совсем', palette: 'red' },
    { key: 'silent', label: 'Молчат дольше своего цикла', palette: 'purple' },
];

/**
 * «Кто выпал из ритма»: кому позвонить сегодня, чтобы дожать месяц.
 *
 * Сортировка по умолчанию — «что это стоит вам»: список отвечает на вопрос,
 * кому звонить первым, а не кто больше всех просел в процентах.
 */
export default function MotivationRhythm({ month, month_label: monthLabel, manager, scope_options: scopeOptions, can_see_all: canSeeAll, query, list }) {
    const { dialogs, setTaskFor, setCallFor } = usePartnerDialogs();
    const summary = list?.summary ?? {};
    const flags = list?.filter ?? { drop: true, stopped: true, silent: true };

    const navigate = (changes) => {
        const params = { month, ...query, ...changes };
        if (canSeeAll && manager?.id && !('manager' in changes)) params.manager = manager.id;
        Object.keys(params).forEach((k) => { if (params[k] === '' || params[k] === null || params[k] === undefined) delete params[k]; });
        router.get('/crm/motivation/rhythm', params, { preserveState: true, preserveScroll: true, replace: true });
    };

    const toggle = (key) => navigate({
        drop: flags.drop ? 1 : 0,
        stopped: flags.stopped ? 1 : 0,
        silent: flags.silent ? 1 : 0,
        [key]: flags[key] ? 0 : 1,
        page: undefined,
    });

    const columns = [
        { key: 'name', label: 'Партнёр', sortable: true, render: (row) => <PartnerName row={row} /> },
        { key: 'usual_monthly', label: 'Обычно берёт в месяц', align: 'right', sortable: true, render: (row) => <Money value={row.usual_monthly} /> },
        { key: 'current_month', label: 'Взял в этом месяце', align: 'right', sortable: true, render: (row) => <Money value={row.current_month} /> },
        { key: 'shortfall', label: 'Не добрал', align: 'right', sortable: true, render: (row) => <Money value={row.shortfall} /> },
        { key: 'cost', label: 'Что это стоит вам', align: 'right', sortable: true, render: (row) => <Text fontSize="sm" fontWeight="700" color={row.cost > 0 ? 'red.fg' : 'fg.subtle'} fontVariantNumeric="tabular-nums">{row.cost > 0 ? `−${fmtRub0(row.cost)}` : '—'}</Text> },
        { key: 'silent_days', label: 'Молчит', align: 'right', sortable: true, render: (row) => (
            <Text fontSize="sm" color={row.flags?.silent ? 'orange.fg' : undefined}>
                {row.silent_days === null || row.silent_days === undefined ? '—' : `${row.silent_days} ${plural(row.silent_days, 'день', 'дня', 'дней')}`}
            </Text>
        ) },
        { key: 'cycle_days', label: 'Обычный ритм', align: 'right', render: (row) => <Text fontSize="sm" color="fg.muted">{row.cycle_days ? `раз в ${row.cycle_days} ${plural(row.cycle_days, 'день', 'дня', 'дней')}` : '—'}</Text> },
        { key: 'last_touch', label: 'Последнее касание', render: (row) => <LastTouch value={row.last_touch} /> },
        { key: 'actions', label: 'Действия', align: 'right', render: (row) => <PartnerActions row={row} onTask={setTaskFor} onCall={setCallFor} /> },
    ];

    return (
        <CrmLayout breadcrumbs={[{ label: 'Продажи' }, { label: 'Моя мотивация', href: '/crm/motivation' }, { label: 'Кто выпал из ритма' }]}>
            <Head title="Кто выпал из ритма — CRM" />
            <PageHeader
                title={canSeeAll && manager ? `Ритм: ${manager.name}` : 'Кто выпал из ритма'}
                description="Кому позвонить сегодня, чтобы дожать месяц. Сверху те, чей недобор стоит вам дороже всего."
                actions={canSeeAll && (scopeOptions ?? []).length > 0 ? (
                    <select aria-label="Работник" style={selectStyle} value={manager?.id ?? ''} onChange={(e) => navigate({ manager: e.target.value, page: undefined })}>
                        <option value="">Выберите работника…</option>
                        {scopeOptions.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                    </select>
                ) : null}
            />

            <VStack align="stretch" gap={4}>
                {manager === null && (
                    <Alert status="info" title="Карточка работника не привязана">
                        Выберите работника выше или обратитесь к руководителю.
                    </Alert>
                )}

                {list && (
                    <>
                        <HStack gap={2} flexWrap="wrap">
                            {FLAGS.map((f) => {
                                const on = Boolean(flags[f.key]);
                                return (
                                    <Box
                                        key={f.key}
                                        as="button"
                                        type="button"
                                        px={3}
                                        py={1.5}
                                        borderRadius="full"
                                        borderWidth="1px"
                                        borderColor={on ? `${f.palette}.solid` : 'border'}
                                        bg={on ? `${f.palette}.subtle` : 'bg.panel'}
                                        fontSize="sm"
                                        cursor="pointer"
                                        onClick={() => toggle(f.key)}
                                        aria-pressed={on}
                                    >
                                        {f.label} · {summary[f.key] ?? 0}
                                    </Box>
                                );
                            })}
                        </HStack>

                        <HStack gap={2} fontSize="xs" color="fg.muted" flexWrap="wrap">
                            <Text>
                                {monthLabel} · в списке {list.rows.total} из {summary.total ?? 0} покупавших
                                {summary.cost_total > 0 ? ` · недобор стоит вам ${fmtRub0(summary.cost_total)}` : ''}
                            </Text>
                            <MetricHint text={list.hint} />
                        </HStack>

                        <PartnerTable
                            list={list}
                            columns={columns}
                            onSort={(column, direction) => navigate({ sort: column, direction, page: undefined })}
                            onPage={(page) => navigate({ page })}
                            emptyTitle="Из ритма никто не выпал"
                            emptyText="По включённым признакам партнёров нет. Снимите фильтр, чтобы увидеть всю покупавшую базу."
                        />
                    </>
                )}
            </VStack>

            {dialogs}
        </CrmLayout>
    );
}
