import { useCallback, useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import {
    Badge, Box, Heading, HStack, SimpleGrid, Spinner, Table, Text, VStack,
} from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { Alert } from '@/components/ui/alert';
import { ProgressBar, ProgressRoot } from '@/components/ui/progress';
import { LuChartLine, LuDownload } from 'react-icons/lu';
import RowActions from '@/shared/Panel/RowActions';
import BurndownChart from './BurndownChart';

const money = (value) => (value === null || value === undefined
    ? '—'
    : `${Number(value).toLocaleString('ru-RU', { maximumFractionDigits: 0 })} ₽`);

const selectStyle = {
    padding: '0.4rem',
    borderRadius: '0.375rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '200px',
};

/**
 * Цвет выполнения — те же грубые пороги, что в колонке «План / факт» списка
 * партнёров: экран отвечает на вопрос «успеваем или нет».
 */
function palette(percent) {
    if (percent === null || percent === undefined) return 'gray';
    if (percent < 70) return 'red';
    if (percent < 95) return 'orange';

    return 'green';
}

const PACE = {
    ahead: { label: 'Идём с опережением', color: 'green' },
    on_track: { label: 'Идём по плану', color: 'blue' },
    behind: { label: 'Отстаём от плана', color: 'red' },
};

/**
 * «Факт из плана» одной ячейкой: раньше это были три колонки (План, Факт,
 * Выполнение), и таблица не влезала в экран уже на семи колонках.
 */
function PlanFactCell({ plan, fact, percent }) {
    return (
        <VStack align="stretch" gap={1} minW="150px">
            <HStack justify="space-between" gap={2}>
                <Text fontSize="sm" fontWeight="600">{money(fact)}</Text>
                <Text fontSize="xs" color="fg.muted" whiteSpace="nowrap">
                    {plan === null || plan === undefined ? 'без плана' : `из ${money(plan)}`}
                </Text>
            </HStack>
            <HStack gap={2}>
                <ProgressRoot flex="1" value={Math.min(percent ?? 0, 100)} size="xs" colorPalette={palette(percent)}>
                    <ProgressBar />
                </ProgressRoot>
                <Text fontSize="xs" fontWeight="600" color={`${palette(percent)}.fg`} minW="34px" textAlign="right">
                    {percent === null || percent === undefined ? '—' : `${percent}%`}
                </Text>
            </HStack>
        </VStack>
    );
}

function KpiTile({ title, value, hint, accent = 'gray' }) {
    return (
        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4} boxShadow="sm">
            <Text fontSize="xs" color="fg.muted" fontWeight="500" mb={1}>{title}</Text>
            <Heading size={{ base: 'md', md: 'lg' }} color={accent === 'gray' ? 'fg' : `${accent}.fg`} lineClamp={1}>
                {value}
            </Heading>
            {hint && <Text fontSize="xs" color="fg.muted" mt={1}>{hint}</Text>}
        </Box>
    );
}

/**
 * Вкладка «Выполнение»: план против факта, прогноз при текущем темпе и burndown.
 *
 * Факт приходит с сервера из ShipmentAnalyticsService — той же цифрой, что
 * показывает /crm/analytics. Ничего не пересчитываем на партнёре: расхождение
 * этих экранов в цифрах было бы багом, а не «разными методиками».
 *
 * Два уровня скоупа. Верхний (отдел или менеджер) задаёт список партнёров и не
 * меняется при провале в партнёра — иначе, кликнув по строке таблицы, менеджер
 * терял бы сам список и не мог перейти к следующему. Нижний (выбранный партнёр)
 * меняет только сводку и burndown.
 */
export default function ProgressPanel({ month, canSeeAll = false }) {
    const [scope, setScope] = useState('department');

    const [parentData, setParentData] = useState(null);
    const [burndown, setBurndown] = useState(null);
    const [managers, setManagers] = useState([]);

    const [loadingScope, setLoadingScope] = useState(true);
    const [loadingDetail, setLoadingDetail] = useState(false);
    const [error, setError] = useState(null);

    const scopeParams = useMemo(() => (scope === 'department'
        ? { month, scope: 'department' }
        : { month, scope: 'manager', scope_id: Number(scope) }), [month, scope]);

    // Верхний уровень: сводка скоупа и разрез по менеджерам.
    const loadScope = useCallback(async () => {
        setLoadingScope(true);
        setError(null);

        try {
            const requests = [axios.get(route('crm.plans.progress'), { params: scopeParams })];

            if (canSeeAll) {
                requests.push(axios.get(route('crm.plans.by-manager'), { params: { month } }));
            }

            const [progressRes, managersRes] = await Promise.all(requests);

            setParentData(progressRes.data);
            setManagers(managersRes?.data?.rows ?? []);
        } catch (e) {
            setError(e?.response?.data?.message || 'Не удалось загрузить выполнение планов.');
        } finally {
            setLoadingScope(false);
        }
    }, [scopeParams, canSeeAll, month]);

    const loadDetail = useCallback(async () => {
        setLoadingDetail(true);

        try {
            const { data } = await axios.get(route('crm.plans.burndown'), { params: scopeParams });
            setBurndown(data);
        } catch (e) {
            setError(e?.response?.data?.message || 'Не удалось загрузить график.');
        } finally {
            setLoadingDetail(false);
        }
    }, [scopeParams]);

    useEffect(() => {
        loadScope();
    }, [loadScope]);

    useEffect(() => {
        loadDetail();
    }, [loadDetail]);

    if (loadingScope && parentData === null) {
        return (
            <HStack justify="center" py={10}>
                <Spinner size="lg" />
            </HStack>
        );
    }

    if (error) {
        return <Alert status="error" title="Ошибка">{error}</Alert>;
    }

    if (parentData === null) {
        return null;
    }

    const scopeOptions = parentData.scopeOptions ?? [];
    const shown = parentData;
    const summary = shown.summary;
    const distribution = shown.distribution;
    const pace = summary.pace ? PACE[summary.pace] : null;
    const busy = loadingScope || loadingDetail;

    const selectScope = (value) => setScope(value);

    return (
        <VStack align="stretch" gap={4}>
            <HStack gap={3} flexWrap="wrap" align="center" justify="space-between">
                <HStack gap={3} flexWrap="wrap" align="center">
                    {canSeeAll && (
                        <select
                            style={selectStyle}
                            value={scope}
                            onChange={(e) => selectScope(e.target.value)}
                        >
                            <option value="department">Отдел целиком</option>
                            {scopeOptions.map((manager) => (
                                <option key={manager.id} value={manager.id}>{manager.name}</option>
                            ))}
                        </select>
                    )}

                    {busy && <Spinner size="xs" />}
                </HStack>

                <Button size="sm" variant="outline" asChild>
                    <a href={route('crm.plans.export', scopeParams)}>
                        <LuDownload /> Выгрузить XLSX
                    </a>
                </Button>
            </HStack>

            <HStack gap={2} flexWrap="wrap" align="center">
                <Text fontSize="sm" fontWeight="600">{shown.scope.label}</Text>
                <Text fontSize="sm" color="fg.muted">
                    · {shown.monthLabel} · партнёров в расчёте: {shown.scope.clients_count}
                </Text>
            </HStack>

            {summary.plan === null && (
                <Alert status="info" title="План на этот месяц не задан">
                    Факт показан, но сравнивать его не с чем. План отдела ставится выше на этой странице, планы менеджеров — приказом на квартал.
                </Alert>
            )}

            <SimpleGrid columns={{ base: 2, md: 3, lg: 6 }} gap={4}>
                <KpiTile title="План" value={money(summary.plan)} hint={summary.plan_to_date !== null ? `к сегодняшнему дню — ${money(summary.plan_to_date)}` : undefined} />
                <KpiTile
                    title="Факт"
                    value={money(summary.fact)}
                    hint={summary.plan_to_date !== null
                        ? `нужно на сегодня ${money(summary.plan_to_date)} · отгрузки по дате документа 1С`
                        : 'отгрузки по дате документа 1С'}
                />
                <KpiTile
                    title="Выполнение"
                    value={summary.percent === null ? '—' : `${summary.percent}%`}
                    accent={palette(summary.percent_to_date ?? summary.percent)}
                    hint={summary.percent_to_date !== null
                        ? `${summary.percent_to_date}% от нужного на сегодня · прошло ${summary.days_passed} из ${summary.days_total} раб. дн.`
                        : `прошло ${summary.days_passed} из ${summary.days_total} рабочих дней`}
                />
                <KpiTile
                    title="Остаток"
                    value={money(summary.remaining)}
                    hint={summary.remaining_to_date !== null
                        ? (summary.remaining_to_date > 0 ? `не добрано к сегодняшнему дню ${money(summary.remaining_to_date)}` : 'к сегодняшнему дню идём с опережением')
                        : undefined}
                />
                <KpiTile
                    title="Нужно в день"
                    value={money(summary.needed_per_day)}
                    hint={`${summary.current_per_day !== null ? `текущий темп ${money(summary.current_per_day)} в день · ` : ''}${summary.days_left > 0 ? `осталось ${summary.days_left} раб. дн.` : 'месяц закрыт'}`}
                />
                <KpiTile
                    title="Прогноз при текущем темпе"
                    value={money(summary.forecast)}
                    hint="линейная экстраполяция, без сезонности"
                />
            </SimpleGrid>

            {pace && (
                <HStack gap={2}>
                    <Badge colorPalette={pace.color} variant="subtle">{pace.label}</Badge>
                    <Text fontSize="xs" color="fg.muted">
                        Темп считается от равномерного списания плана по дням месяца.
                    </Text>
                </HStack>
            )}

            {distribution && distribution.plan !== null && Math.abs(distribution.diff) > 0.01 && (
                <Alert
                    status="info"
                    title={distribution.diff > 0
                        ? 'Расписано больше, чем план скоупа'
                        : 'Расписано меньше, чем план скоупа'}
                >
                    {distribution.label}: {money(distribution.sum)} при плане {money(distribution.plan)} —
                    расхождение {distribution.diff > 0 ? '+' : '−'}{money(Math.abs(distribution.diff))}.
                    Это подсказка, а не запрет: часть цифры может быть намеренно не расписана.
                </Alert>
            )}

            <BurndownChart
                points={burndown?.points ?? []}
                plan={burndown?.plan ?? null}
                subject={shown.scope.label}
            />

            {canSeeAll && managers.length > 0 && (
                <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
                    <Text fontWeight="600" mb={3}>По менеджерам</Text>
                    <Box overflowX="auto">
                        <Table.Root size="sm" interactive>
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeader>Менеджер</Table.ColumnHeader>
                                    <Table.ColumnHeader minW="180px">Факт / план</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Прогноз при текущем темпе</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Покупали в месяце</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="end">Действия</Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {managers.map((row) => (
                                    <Table.Row
                                        key={row.manager_id}
                                        bg={String(scope) === String(row.manager_id) ? 'bg.subtle' : undefined}
                                    >
                                        <Table.Cell>
                                            <Text fontSize="sm" fontWeight="500">{row.name}</Text>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <PlanFactCell plan={row.plan} fact={row.fact} percent={row.percent} />
                                        </Table.Cell>
                                        <Table.Cell textAlign="right">{money(row.forecast)}</Table.Cell>
                                        <Table.Cell textAlign="right">
                                            <Text fontSize="sm" fontWeight="500">{row.clients_count}</Text>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <RowActions
                                                size="xs"
                                                extra={[{
                                                    icon: LuChartLine,
                                                    label: 'Показать на графике',
                                                    onClick: () => selectScope(String(row.manager_id)),
                                                }]}
                                            />
                                        </Table.Cell>
                                    </Table.Row>
                                ))}
                            </Table.Body>
                        </Table.Root>
                    </Box>
                </Box>
            )}

        </VStack>
    );
}
