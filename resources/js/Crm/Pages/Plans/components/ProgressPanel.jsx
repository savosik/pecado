import { useCallback, useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import {
    Badge, Box, Heading, HStack, SimpleGrid, Spinner, Table, Text, VStack,
} from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { Alert } from '@/components/ui/alert';
import { ProgressBar, ProgressRoot } from '@/components/ui/progress';
import { LuDownload } from 'react-icons/lu';
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
 * клиентов: экран отвечает на вопрос «успеваем или нет».
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
 * показывает /crm/analytics. Ничего не пересчитываем на клиенте: расхождение
 * этих экранов в цифрах было бы багом, а не «разными методиками».
 */
export default function ProgressPanel({ month, canSeeAll = false }) {
    const [scope, setScope] = useState('department');
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [data, setData] = useState(null);
    const [burndown, setBurndown] = useState(null);
    const [managers, setManagers] = useState([]);

    const scopeParams = useMemo(() => {
        if (scope === 'department') {
            return { month, scope: 'department' };
        }

        return { month, scope: 'manager', scope_id: Number(scope) };
    }, [month, scope]);

    const load = useCallback(async () => {
        setLoading(true);
        setError(null);

        try {
            const requests = [
                axios.get(route('crm.plans.progress'), { params: scopeParams }),
                axios.get(route('crm.plans.burndown'), { params: scopeParams }),
            ];

            if (canSeeAll) {
                requests.push(axios.get(route('crm.plans.by-manager'), { params: { month } }));
            }

            const [progressRes, burndownRes, managersRes] = await Promise.all(requests);

            setData(progressRes.data);
            setBurndown(burndownRes.data);
            setManagers(managersRes?.data?.rows ?? []);
        } catch (e) {
            setError(e?.response?.data?.message || 'Не удалось загрузить выполнение планов.');
        } finally {
            setLoading(false);
        }
    }, [scopeParams, canSeeAll, month]);

    useEffect(() => {
        load();
    }, [load]);

    if (loading && data === null) {
        return (
            <HStack justify="center" py={10}>
                <Spinner size="lg" />
            </HStack>
        );
    }

    if (error) {
        return <Alert status="error" title="Ошибка">{error}</Alert>;
    }

    if (data === null) {
        return null;
    }

    const { summary, distribution, clients = [], scopeOptions = [] } = data;
    const pace = summary.pace ? PACE[summary.pace] : null;

    return (
        <VStack align="stretch" gap={4}>
            <HStack gap={3} flexWrap="wrap" align="center" justify="space-between">
                <HStack gap={3} flexWrap="wrap" align="center">
                    {canSeeAll && (
                        <select
                            style={selectStyle}
                            value={scope}
                            onChange={(e) => setScope(e.target.value)}
                        >
                            <option value="department">Отдел целиком</option>
                            {scopeOptions.map((manager) => (
                                <option key={manager.id} value={manager.id}>{manager.name}</option>
                            ))}
                        </select>
                    )}
                    <Text fontSize="sm" color="fg.muted">
                        {data.scope.label} · {data.monthLabel} · клиентов в расчёте: {data.scope.clients_count}
                    </Text>
                    {loading && <Spinner size="xs" />}
                </HStack>

                <Button size="sm" variant="outline" asChild>
                    <a href={route('crm.plans.export', scopeParams)}>
                        <LuDownload /> Выгрузить XLSX
                    </a>
                </Button>
            </HStack>

            {summary.plan === null && (
                <Alert status="info" title="План на этот месяц не задан">
                    Факт показан, но сравнивать его не с чем. Поставьте план на вкладке «Ввод планов».
                </Alert>
            )}

            <SimpleGrid columns={{ base: 2, md: 3, lg: 6 }} gap={4}>
                <KpiTile title="План" value={money(summary.plan)} />
                <KpiTile title="Факт" value={money(summary.fact)} hint="отгрузки по дате документа 1С" />
                <KpiTile
                    title="Выполнение"
                    value={summary.percent === null ? '—' : `${summary.percent}%`}
                    accent={palette(summary.percent)}
                    hint={`прошло ${summary.days_passed} из ${summary.days_total} дней`}
                />
                <KpiTile title="Остаток" value={money(summary.remaining)} />
                <KpiTile
                    title="Нужно в день"
                    value={money(summary.needed_per_day)}
                    hint={summary.days_left > 0 ? `осталось ${summary.days_left} дн.` : 'месяц закрыт'}
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

            <BurndownChart points={burndown?.points ?? []} plan={burndown?.plan ?? null} />

            {canSeeAll && managers.length > 0 && (
                <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
                    <Text fontWeight="600" mb={3}>По менеджерам</Text>
                    <Box overflowX="auto">
                        <Table.Root size="sm">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeader>Менеджер</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">План</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Факт</Table.ColumnHeader>
                                    <Table.ColumnHeader minW="160px">Выполнение</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Прогноз при текущем темпе</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Активных клиентов</Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {managers.map((row) => (
                                    <Table.Row key={row.manager_id}>
                                        <Table.Cell>
                                            <Text fontSize="sm" fontWeight="500">{row.name}</Text>
                                        </Table.Cell>
                                        <Table.Cell textAlign="right">{money(row.plan)}</Table.Cell>
                                        <Table.Cell textAlign="right">{money(row.fact)}</Table.Cell>
                                        <Table.Cell>
                                            <VStack align="stretch" gap={1}>
                                                <Text fontSize="xs" fontWeight="600" color={`${palette(row.percent)}.fg`}>
                                                    {row.percent === null ? 'без плана' : `${row.percent}%`}
                                                </Text>
                                                <ProgressRoot
                                                    value={Math.min(row.percent ?? 0, 100)}
                                                    size="xs"
                                                    colorPalette={palette(row.percent)}
                                                >
                                                    <ProgressBar />
                                                </ProgressRoot>
                                            </VStack>
                                        </Table.Cell>
                                        <Table.Cell textAlign="right">{money(row.forecast)}</Table.Cell>
                                        <Table.Cell textAlign="right">{row.clients_count}</Table.Cell>
                                    </Table.Row>
                                ))}
                            </Table.Body>
                        </Table.Root>
                    </Box>
                </Box>
            )}

            <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4}>
                <Text fontWeight="600" mb={1}>По клиентам</Text>
                <Text fontSize="xs" color="fg.muted" mb={3}>
                    Сверху — кто сильнее отстаёт от своего плана. Клиенты без плана идут в конце списка.
                </Text>

                {clients.length === 0 ? (
                    <Text fontSize="sm" color="fg.muted">
                        Ни планов, ни отгрузок за этот месяц — показывать нечего.
                    </Text>
                ) : (
                    <Box overflowX="auto">
                        <Table.Root size="sm">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeader>Клиент</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">План</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Факт</Table.ColumnHeader>
                                    <Table.ColumnHeader minW="160px">Выполнение</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Отставание</Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {clients.map((row) => (
                                    <Table.Row key={row.id}>
                                        <Table.Cell>
                                            <a href={route('crm.clients.show', row.id)}>
                                                <Text fontSize="sm" fontWeight="500">{row.name}</Text>
                                            </a>
                                        </Table.Cell>
                                        <Table.Cell textAlign="right">{money(row.plan)}</Table.Cell>
                                        <Table.Cell textAlign="right">{money(row.fact)}</Table.Cell>
                                        <Table.Cell>
                                            <VStack align="stretch" gap={1}>
                                                <Text fontSize="xs" fontWeight="600" color={`${palette(row.percent)}.fg`}>
                                                    {row.percent === null ? 'без плана' : `${row.percent}%`}
                                                </Text>
                                                <ProgressRoot
                                                    value={Math.min(row.percent ?? 0, 100)}
                                                    size="xs"
                                                    colorPalette={palette(row.percent)}
                                                >
                                                    <ProgressBar />
                                                </ProgressRoot>
                                            </VStack>
                                        </Table.Cell>
                                        <Table.Cell textAlign="right">
                                            <Text fontSize="sm" color={row.lag > 0 ? 'red.fg' : 'fg.muted'}>
                                                {row.lag === null ? '—' : money(row.lag)}
                                            </Text>
                                        </Table.Cell>
                                    </Table.Row>
                                ))}
                            </Table.Body>
                        </Table.Root>
                    </Box>
                )}
            </Box>
        </VStack>
    );
}
