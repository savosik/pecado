import { Head, router } from '@inertiajs/react';
import { Badge, Box, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Alert } from '@/components/ui/alert';
import MetricHint from '@/Crm/Components/MetricHint';
import PartnerTable from './components/PartnerTable';
import PartnerActions, { usePartnerDialogs } from './components/PartnerActions';
import { BestMonth, Money } from './components/partnerCells';
import { fmtDay, fmtRub0 } from '../Salary/components/format';

const selectStyle = {
    padding: '0.45rem 0.6rem',
    borderRadius: '0.5rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '180px',
    background: 'var(--chakra-colors-bg-panel)',
    color: 'var(--chakra-colors-fg)',
};

const MONTH_NAMES = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
const monthLabel = (iso) => {
    const [y, m] = String(iso).split('-').map(Number);
    return `${MONTH_NAMES[(m || 1) - 1].replace(/[ая]$/, (c) => (c === 'я' ? 'ь' : ''))} ${y}`;
};

/**
 * «Свободные клиенты»: кого я могу взять себе и что это даст.
 *
 * В общем списке шесть сотен партнёров, покупали когда-либо единицы. Экран
 * не выдаёт холодную базу за спящих клиентов: «даст вам» есть только у тех,
 * у кого есть история, и фильтр по умолчанию оставляет только их.
 */
export default function MotivationPool({ month, month_label: monthLabelRu, manager, scope_options: scopeOptions, can_see_all: canSeeAll, query, list }) {
    const { dialogs, setTaskFor, setCallFor } = usePartnerDialogs();
    const summary = list?.summary ?? {};
    const tap = list?.tap;
    const historyOnly = list?.history_only ?? true;

    const navigate = (changes) => {
        const params = { month, ...query, ...changes };
        if (canSeeAll && manager?.id && !('manager' in changes)) params.manager = manager.id;
        Object.keys(params).forEach((k) => { if (params[k] === '' || params[k] === null || params[k] === undefined) delete params[k]; });
        router.get('/crm/motivation/pool', params, { preserveState: true, preserveScroll: true, replace: true });
    };

    const columns = [
        { key: 'name', label: 'Партнёр', sortable: true, render: (row) => (
            <VStack align="start" gap={0}>
                <Text fontWeight="600" fontSize="sm">{row.name}</Text>
                {row.legal_name && row.legal_name !== row.name && <Text fontSize="xs" color="fg.subtle">{row.legal_name}</Text>}
            </VStack>
        ) },
        { key: 'last_purchase_on', label: 'Покупал у нас', sortable: true, render: (row) => (
            row.last_purchase_on
                ? <Text fontSize="sm">{fmtDay(row.last_purchase_on)}</Text>
                : <Badge size="xs" variant="subtle" colorPalette="gray">никогда</Badge>
        ) },
        { key: 'usual_monthly', label: 'Брал в месяц', align: 'right', sortable: true, render: (row) => <Money value={row.ever_bought ? row.usual_monthly : null} /> },
        { key: 'best_month', label: 'Лучший месяц', align: 'right', sortable: true, render: (row) => <BestMonth value={row.ever_bought ? row.best_month : null} /> },
        { key: 'city', label: 'Чем интересен', sortable: true, render: (row) => (
            <VStack align="start" gap={0} fontSize="xs">
                <Text>{row.city || <Text as="span" color="fg.subtle">город не указан</Text>}</Text>
                {row.registered_on && <Text color="fg.subtle">заведён {fmtDay(row.registered_on)}</Text>}
            </VStack>
        ) },
        { key: 'estimate', label: 'Даст вам за полгода', align: 'right', sortable: true, render: (row) => (
            row.estimate !== null && row.estimate !== undefined
                ? <Text fontSize="sm" fontWeight="700" color="green.fg" fontVariantNumeric="tabular-nums">+{fmtRub0(row.estimate)}</Text>
                : <Text fontSize="xs" color="fg.subtle">оценить нельзя — не покупал</Text>
        ) },
        { key: 'actions', label: 'Действия', align: 'right', render: (row) => <PartnerActions row={row} onTask={setTaskFor} onCall={setCallFor} /> },
    ];

    return (
        <CrmLayout breadcrumbs={[{ label: 'Продажи' }, { label: 'Моя мотивация', href: '/crm/motivation' }, { label: 'Свободные клиенты' }]}>
            <Head title="Свободные клиенты — CRM" />
            <PageHeader
                title="Свободные клиенты"
                description="Кого можно взять себе и что это даст. Пакеты выдаёт руководитель."
                actions={(
                    <HStack gap={2} flexWrap="wrap">
                        <input type="search" aria-label="Поиск по партнёрам" placeholder="Название или город…" style={selectStyle} defaultValue={query?.search ?? ''} onKeyDown={(e) => { if (e.key === 'Enter') navigate({ search: e.target.value, page: undefined }); }} />
                        {canSeeAll && (scopeOptions ?? []).length > 0 && (
                            <select aria-label="Работник" style={selectStyle} value={manager?.id ?? ''} onChange={(e) => navigate({ manager: e.target.value, page: undefined })}>
                                <option value="">Выберите работника…</option>
                                {scopeOptions.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                            </select>
                        )}
                    </HStack>
                )}
            />

            <VStack align="stretch" gap={4}>
                {manager === null && (
                    <Alert status="info" title="Карточка работника не привязана">Выберите работника выше или обратитесь к руководителю.</Alert>
                )}

                {list && (
                    <>
                        <SimpleGrid columns={{ base: 1, sm: 3 }} gap={3}>
                            <Stat label="В общем списке" value={String(summary.total ?? 0)} />
                            <Stat label="Из них покупали когда-либо" value={String(summary.with_history ?? 0)} hint="Только по ним можно оценить, что даст закрепление. Остальные — холодная база: их не разбудить, их надо привлечь." />
                            <Stat label="Могу получить пакет" value={tap?.blocked ? 'нет' : 'да'} tone={tap?.blocked ? 'red' : 'green'} hint={`Пакет — до ${summary.package_size ?? 20} партнёров. Выдача останавливается, если отгрузки базы два периода подряд ниже порога оплаты (п. 8.4).`} />
                        </SimpleGrid>

                        {tap && (tap.blocked || tap.note) && (
                            <Alert status={tap.blocked ? 'warning' : 'info'} title={tap.blocked ? 'Выдача пакетов приостановлена' : 'Правило крана'}>
                                <VStack align="start" gap={1}>
                                    {tap.note && <Text>{tap.note}</Text>}
                                    {(tap.checked ?? []).map((c) => (
                                        <Text key={c.month} fontSize="sm">
                                            {monthLabel(c.month)}: {c.base === null ? 'расчёта по новой схеме нет' : `отгрузки базы ${fmtRub0(c.base)} при пороге ${fmtRub0(c.threshold)}`}
                                            {c.below === true ? ' — ниже порога' : c.below === false ? ' — порог достигнут' : ''}
                                        </Text>
                                    ))}
                                </VStack>
                            </Alert>
                        )}

                        <HStack gap={3} flexWrap="wrap" fontSize="sm">
                            <Box
                                as="button"
                                type="button"
                                px={3}
                                py={1.5}
                                borderRadius="full"
                                borderWidth="1px"
                                borderColor={historyOnly ? 'blue.solid' : 'border'}
                                bg={historyOnly ? 'blue.subtle' : 'bg.panel'}
                                cursor="pointer"
                                onClick={() => navigate({ history: historyOnly ? 0 : 1, page: undefined })}
                                aria-pressed={historyOnly}
                            >
                                Только с историей покупок · {summary.with_history ?? 0}
                            </Box>
                            <HStack gap={1} fontSize="xs" color="fg.muted">
                                <Text>{monthLabelRu} · в списке {list.rows.total}</Text>
                                <MetricHint text={list.hint} />
                            </HStack>
                        </HStack>

                        <PartnerTable
                            list={list}
                            columns={columns}
                            onSort={(column, direction) => navigate({ sort: column, direction, page: undefined })}
                            onPage={(page) => navigate({ page })}
                            emptyTitle={historyOnly ? 'В общем списке нет партнёров с историей покупок' : 'Общий список пуст'}
                            emptyText={historyOnly ? 'Снимите фильтр, чтобы увидеть всю базу. Оценить её нечем — это карточки без единой продажи.' : 'Все партнёры закреплены.'}
                        />
                    </>
                )}
            </VStack>

            {dialogs}
        </CrmLayout>
    );
}

function Stat({ label, value, tone, hint }) {
    return (
        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
            <HStack gap={1} fontSize="xs" color="fg.muted">
                <Text>{label}</Text>
                {hint && <MetricHint text={hint} />}
            </HStack>
            <Text fontSize="2xl" fontWeight="800" fontVariantNumeric="tabular-nums" color={tone ? `${tone}.fg` : undefined}>{value}</Text>
        </Box>
    );
}
