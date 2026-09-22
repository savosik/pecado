import { Head, router } from '@inertiajs/react';
import { Box, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Alert } from '@/components/ui/alert';
import MetricHint from '@/Crm/Components/MetricHint';
import { Tooltip } from '@/components/ui/tooltip';
import { LuGlobe, LuShoppingCart, LuTruck } from 'react-icons/lu';
import PartnerTable from './components/PartnerTable';
import PartnerActions, { usePartnerDialogs } from './components/PartnerActions';
import { PartnerName } from './components/partnerCells';
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

    // П1 = ставка × (отгрузки базы − порог): первые M % плана вознаграждения не дают,
    // каждый рубль сверх — по ставке. Цифра по партнёру — его вклад по ставке, а не «выплата».
    const threshold = summary?.threshold ?? null;
    const thresholdReached = threshold ? threshold.reached : true;
    const thresholdHint = threshold
        ? `Отгрузки партнёра в этом месяце × ваша ставка — его вклад в П1. П1 считается со всей базы как ставка × (отгрузки − порог ${threshold.percent} % плана${threshold.plan ? `, ${Math.round(threshold.plan * threshold.percent / 100).toLocaleString('ru-RU')} ₽` : ''}): первые ${threshold.percent} % плана вознаграждения не дают, каждый рубль сверх — по ставке. Сейчас отгружено ${Math.round(threshold.shipped).toLocaleString('ru-RU')} ₽ — ${threshold.reached ? 'порог пройден' : 'порог не пройден, П1 пока 0'}.`
        : 'Отгрузки партнёра в этом месяце × ваша ставка — вклад в П1. П1 = ставка × (отгрузки базы − порог); расчёта за месяц ещё нет.';

    const rub = (v) => `${Math.round(v).toLocaleString('ru-RU')} ₽`;
    const ratePercent = (row) => `${(Number(row.rate ?? 0) * 100).toLocaleString('ru-RU', { maximumFractionDigits: 2 })} %`;
    const k1Percent = `${(Number(summary?.rate_k1_per_day ?? 0) * 100).toLocaleString('ru-RU', { maximumFractionDigits: 3 })} %`;
    const explainUsual = (row) => `${rub(row.usual_monthly)} (обычная закупка) × ${ratePercent(row)} (ваша ставка) = ${rub(row.usual_gain)}`;
    const explainBest = (row) => `${rub(row.best_month?.amount ?? 0)} (лучший месяц) × ${ratePercent(row)} = ${rub(row.best_gain)}`;
    const explainCurrent = (row) => `${rub(row.current_month)} (взял в этом месяце) × ${ratePercent(row)} = ${rub(row.current_gain)} — вклад партнёра в П1${row.in_novelty ? ' (П2: начисляется с первого рубля)' : thresholdReached ? '' : `. П1 платится только сверх порога ${threshold?.percent ?? 60} % плана${threshold?.plan ? ` (${rub(threshold.plan * threshold.percent / 100)})` : ''}: сейчас отгружено ${threshold ? rub(threshold.shipped) : '—'}, ниже порога.`}`;
    const explainK1 = (row) => `Штраф К1 = сумма остатков просроченного долга за каждый день этого месяца (${Math.round(row.k1_integral ?? 0).toLocaleString('ru-RU')} ₽·дн.) × ${k1Percent} в день = −${rub(row.k1_deduction)}. ${row.debt?.overdue ? `Сейчас просрочено ${rub(row.debt.amount)} (${row.debt.days} дн.)` : row.debt?.amount ? `Сейчас просрочки нет, долг ${rub(row.debt.amount)} в сроке — штраф накопился за дни просрочки, уже закрытой в этом месяце` : 'Сейчас просрочки нет — штраф накопился за дни просрочки, уже закрытой в этом месяце'}. Снимается независимо от порога оплаты.`;

    // Давность в днях с подсветкой: до 14 — зелёный, 15–30 — жёлтый, дольше — красный.
    const daysAgo = (iso) => (iso ? Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 86400000)) : null);
    const agoColor = (d) => (d === null ? 'fg.subtle' : d <= 14 ? 'green.fg' : d <= 30 ? 'yellow.fg' : 'red.fg');
    const agoLabel = (d) => (d === null ? 'не было' : d === 0 ? 'сегодня' : d === 1 ? 'вчера' : `${d} дн. назад`);

    const money = (v, { strong = false, tone } = {}) => (
        <Text fontSize="sm" fontWeight={strong ? '700' : '600'} color={v > 0 ? tone : 'fg.subtle'} fontVariantNumeric="tabular-nums">{v > 0 ? rub(v) : '—'}</Text>
    );
    const agoShort = (d) => (d === null ? '—' : d === 0 ? 'сег.' : `${d} д.`);
    const fmtDate = (iso) => (iso ? new Date(iso).toLocaleDateString('ru-RU') : null);
    // Активность одной колонкой: иконка + «сколько дней назад», расшифровка — в тултипе.
    const Activity = ({ icon: Icon, iso, what }) => {
        const d = daysAgo(iso);
        const tip = d === null ? `${what}: не было` : `${what} ${agoLabel(d)}${d > 1 ? ` (${fmtDate(iso)})` : ''}`;
        return (
            <Tooltip content={tip} showArrow>
                <HStack gap={1} color={agoColor(d)} cursor="default" aria-label={tip} whiteSpace="nowrap" lineHeight="1.1">
                    <Icon size={11} />
                    <Text fontSize="xs" fontWeight="600" fontVariantNumeric="tabular-nums">{agoShort(d)}</Text>
                </HStack>
            </Tooltip>
        );
    };

    const columns = [
        { key: 'name', label: 'Партнёр и группа', sortable: true, hint: 'Под именем — ставка вашего вознаграждения с выручки этого партнёра: П2 в периоде новизны, иначе П1.', render: (row) => (
            <VStack align="start" gap={0.5}>
                <PartnerName row={row} />
                <Text fontSize="xs" color={row.in_novelty ? 'purple.fg' : 'fg.muted'}>ставка {ratePercent(row)}</Text>
                {row.in_novelty && row.to_qualification !== null && (
                    <Text fontSize="xs" color={row.to_qualification > 0 ? 'orange.fg' : 'green.fg'} title="Отгрузки нового партнёра за квартал идут в премию отдела, когда доберут порог">
                        {row.to_qualification > 0 ? `до премии отдела ещё ${rub(row.to_qualification)}` : 'идёт в премию отдела'}
                    </Text>
                )}
            </VStack>
        ) },
        { key: 'best_month', group: 'Показатели', label: 'Лучший месяц', align: 'right', sortable: true, hint: 'Лучший месяц партнёра за два года и когда он был.', render: (row) => (
            <VStack align="end" gap={0}>
                {money(row.best_month?.amount ?? 0)}
                {row.best_month?.period && <Text fontSize="xs" color="fg.subtle">{String(row.best_month.period).split('-').reverse().join('.')}</Text>}
            </VStack>
        ) },
        { key: 'usual_monthly', group: 'Показатели', label: 'Обычно берёт', align: 'right', sortable: true, hint: 'Обычная закупка партнёра за месяц — медиана по месяцам с покупками за полгода.', render: (row) => money(row.usual_monthly) },
        { key: 'current_month', group: 'Показатели', label: 'Взял в этом месяце', align: 'right', sortable: true, highlight: true, render: (row) => money(row.current_month, { strong: true }) },
        { key: 'debt', group: 'Показатели', label: 'Просрочка', sub: 'на сейчас', align: 'right', sortable: true, hint: 'Просроченный долг партнёра сейчас и сколько дней висит самый старый документ.', render: (row) => (
            <VStack align="end" gap={0}>
                {money(row.debt?.overdue ? row.debt.amount : 0, { tone: 'red.fg' })}
                {row.debt?.overdue && <Text fontSize="xs" color="red.fg">{row.debt.days} дн.</Text>}
            </VStack>
        ) },
        { key: 'best_gain', group: 'Зарплата', label: 'При лучшем месяце', align: 'right', sortable: true, hint: 'Лучший месяц × ваша ставка.', render: (row) => <HStack gap={1} justify="end">{money(row.best_gain, { tone: 'green.fg' })}{row.best_gain > 0 && <MetricHint text={explainBest(row)} />}</HStack> },
        { key: 'usual_gain', group: 'Зарплата', label: 'В обычный месяц', align: 'right', sortable: true, hint: 'Обычная закупка × ваша ставка.', render: (row) => <HStack gap={1} justify="end">{money(row.usual_gain, { tone: 'green.fg' })}{row.usual_gain > 0 && <MetricHint text={explainUsual(row)} />}</HStack> },
        { key: 'current_gain', group: 'Зарплата', label: 'В этом месяце', align: 'right', sortable: true, highlight: true, hint: thresholdHint, render: (row) => (
            <VStack align="end" gap={0}>
                <HStack gap={1} justify="end">{money(row.current_gain, { strong: true, tone: thresholdReached ? 'green.fg' : 'fg.muted' })}{row.current_gain > 0 && <MetricHint text={explainCurrent(row)} />}</HStack>
                {row.current_gain > 0 && !thresholdReached && <Text fontSize="xs" color="orange.fg">ниже порога</Text>}
                {row.remaining_gain > 0 && <Text fontSize="xs" color="fg.muted" title="Недобор до обычной закупки × ваша ставка">ещё +{rub(row.remaining_gain)}</Text>}
            </VStack>
        ) },
        { key: 'k1_deduction', group: 'Зарплата', label: 'Накопленный штраф', sub: 'в этом месяце', align: 'right', sortable: true, hint: 'Вычет К1: сумма остатков просроченного долга за каждый день месяца × ставка в день. Снимается независимо от порога оплаты.', render: (row) => <HStack gap={1} justify="end"><Text fontSize="sm" fontWeight="600" color={row.k1_deduction > 0 ? 'red.fg' : 'fg.subtle'} fontVariantNumeric="tabular-nums">{row.k1_deduction > 0 ? `−${rub(row.k1_deduction)}` : '—'}</Text>{row.k1_deduction > 0 && <MetricHint text={explainK1(row)} />}</HStack> },
        { key: 'last_purchase_on', label: 'Активность', sortable: true, hint: 'Сколько дней назад партнёр был на сайте, оформлял заказ и был отгружен. Зелёный — до 14 дней, жёлтый — до 30, красный — дольше месяца. Сортировка — по последней отгрузке.', render: (row) => (
            <VStack align="start" gap={0}>
                <Activity icon={LuGlobe} iso={row.last_visit_on} what="Был на сайте" />
                <Activity icon={LuShoppingCart} iso={row.last_order_on} what="Заказывал" />
                <Activity icon={LuTruck} iso={row.last_purchase_on} what="Отгружен" />
            </VStack>
        ) },
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
