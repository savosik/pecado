import { useCallback, useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { Badge, Box, HStack, Spinner, Table, Text, VStack } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { Alert } from '@/components/ui/alert';
import { LuDownload, LuListChecks, LuMail, LuPhone } from 'react-icons/lu';
import { usePermission } from '@/shared/Panel/usePermission';
import TaskDialog from '@/Crm/Components/TaskDialog';
import EmailComposeDialog from '@/Crm/Components/EmailComposeDialog';
import CallDialog from '@/Crm/Components/CallDialog';

const money = (value) => (value === null || value === undefined
    ? '—'
    : `${Number(value).toLocaleString('ru-RU', { maximumFractionDigits: 0 })} ₽`);

const selectStyle = {
    padding: '0.4rem',
    borderRadius: '0.375rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '220px',
};

const ABC_COLOR = { A: 'green', B: 'blue', C: 'gray' };

/**
 * Возможности: кому звонить сегодня и почему.
 *
 * Один компонент на два места — раздел «Возможности» и вкладка на «Планах
 * продаж». Список одинаковый, и две его копии разошлись бы уже на третьей правке.
 *
 * Оценка и порядок строк приходят с сервера: веса сигналов лежат в конфиге,
 * а не в вёрстке, иначе поменять приоритет обзвона было бы задачей на релиз.
 */
export default function OpportunityPanel({ month, canSeeAll = false }) {
    const { can } = usePermission();

    const [preset, setPreset] = useState('plan_lag');
    const [scope, setScope] = useState('department');
    const [dimension, setDimension] = useState({ type: 'brand', value: '' });

    const [data, setData] = useState(null);
    const [dimensions, setDimensions] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    const [taskFor, setTaskFor] = useState(null);
    const [emailFor, setEmailFor] = useState(null);
    const [callFor, setCallFor] = useState(null);

    const needsDimension = preset === 'not_buying';

    const params = useMemo(() => {
        const base = { month, preset };

        if (scope !== 'department') {
            base.scope = 'manager';
            base.scope_id = Number(scope);
        }

        if (needsDimension && dimension.value) {
            base.dimension = dimension.type;
            base.value = Number(dimension.value);
        }

        return base;
    }, [month, preset, scope, needsDimension, dimension]);

    const load = useCallback(async () => {
        setLoading(true);
        setError(null);

        try {
            const { data: payload } = await axios.get(route('crm.opportunities.data'), { params });
            setData(payload);
        } catch (e) {
            setError(e?.response?.data?.message || 'Не удалось загрузить список возможностей.');
        } finally {
            setLoading(false);
        }
    }, [params]);

    useEffect(() => {
        load();
    }, [load]);

    // Бренды и категории тянем только под пресет, которому они нужны: список
    // собирается тяжёлым запросом по отгрузкам всего отдела.
    useEffect(() => {
        if (! needsDimension || dimensions !== null) {
            return;
        }

        axios.get(route('crm.opportunities.dimensions'), {
            params: scope === 'department' ? {} : { scope: 'manager', scope_id: Number(scope) },
        })
            .then(({ data: payload }) => setDimensions(payload))
            .catch(() => setDimensions({ brands: [], categories: [] }));
    }, [needsDimension, dimensions, scope]);

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

    const rows = data.rows ?? [];
    const presets = data.presets ?? [];
    const scopeOptions = data.scopeOptions ?? [];
    const current = presets.find((item) => item.value === preset);
    const dimensionList = dimension.type === 'brand'
        ? (dimensions?.brands ?? [])
        : (dimensions?.categories ?? []);

    const selectPreset = (value) => {
        setPreset(value);
        // Скоуп сохраняем, а выбранный бренд — нет: он относится к вопросу,
        // который менеджер только что закрыл.
        if (value !== 'not_buying') {
            setDimension((prev) => ({ ...prev, value: '' }));
        }
    };

    return (
        <VStack align="stretch" gap={4}>
            <HStack gap={3} flexWrap="wrap" align="center" justify="space-between">
                <HStack gap={3} flexWrap="wrap" align="center">
                    <select style={selectStyle} value={preset} onChange={(e) => selectPreset(e.target.value)}>
                        {presets.map((item) => (
                            <option key={item.value} value={item.value}>{item.label}</option>
                        ))}
                    </select>

                    {canSeeAll && (
                        <select
                            style={selectStyle}
                            value={scope}
                            onChange={(e) => {
                                setScope(e.target.value);
                                setDimensions(null);
                            }}
                        >
                            <option value="department">Отдел целиком</option>
                            {scopeOptions.map((manager) => (
                                <option key={manager.id} value={manager.id}>{manager.name}</option>
                            ))}
                        </select>
                    )}

                    {needsDimension && (
                        <>
                            <select
                                style={{ ...selectStyle, minWidth: '150px' }}
                                value={dimension.type}
                                onChange={(e) => setDimension({ type: e.target.value, value: '' })}
                            >
                                <option value="brand">Бренд</option>
                                <option value="category">Категория</option>
                            </select>
                            <select
                                style={selectStyle}
                                value={dimension.value}
                                onChange={(e) => setDimension((prev) => ({ ...prev, value: e.target.value }))}
                            >
                                <option value="">
                                    {dimensions === null ? 'Загружаем…' : 'Выберите значение'}
                                </option>
                                {dimensionList.map((item) => (
                                    <option key={item.id} value={item.id}>{item.name}</option>
                                ))}
                            </select>
                        </>
                    )}

                    {loading && <Spinner size="xs" />}
                </HStack>

                {rows.length > 0 && (
                    <Button size="sm" variant="outline" asChild>
                        <a href={route('crm.opportunities.export', params)}>
                            <LuDownload /> Выгрузить XLSX
                        </a>
                    </Button>
                )}
            </HStack>

            <HStack gap={2} flexWrap="wrap" align="baseline">
                <Text fontSize="sm" color="fg.muted">{current?.description}</Text>
                <Text fontSize="xs" color="fg.muted">
                    · {data.scope?.label} · {data.monthLabel} · подходит клиентов: {data.summary?.matched ?? 0}
                    {(data.summary?.gap_total ?? 0) > 0 && ` · недобор ${money(data.summary.gap_total)}`}
                </Text>
            </HStack>

            {rows.length === 0 ? (
                <Alert
                    status="info"
                    title={needsDimension && ! dimension.value
                        ? 'Выберите бренд или категорию'
                        : 'По этому вопросу никого нет'}
                >
                    {needsDimension && ! dimension.value
                        ? 'Пресет отвечает на вопрос «покупают у нас, но это не берут» — вопрос нужно задать.'
                        : 'Попробуйте другой пресет. Если планы на месяц не заданы, «Отстают от плана» будет пустым всегда.'}
                </Alert>
            ) : (
                <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={4} overflowX="auto">
                    <Table.Root size="sm">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeader>Клиент</Table.ColumnHeader>
                                <Table.ColumnHeader>Почему в списке</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">Недобор</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">План / факт</Table.ColumnHeader>
                                <Table.ColumnHeader>Последняя отгрузка</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="center">Класс</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="right">Оценка</Table.ColumnHeader>
                                <Table.ColumnHeader />
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {rows.map((row) => (
                                <Table.Row key={row.id}>
                                    <Table.Cell>
                                        <VStack align="start" gap={0}>
                                            <a href={route('crm.clients.show', row.id)}>
                                                <Text fontSize="sm" fontWeight="500" textDecoration="underline" textDecorationStyle="dotted">
                                                    {row.name}
                                                </Text>
                                            </a>
                                            {canSeeAll && row.manager && (
                                                <Text fontSize="xs" color="fg.muted">{row.manager}</Text>
                                            )}
                                        </VStack>
                                    </Table.Cell>
                                    <Table.Cell maxW="360px">
                                        <Text fontSize="xs" color="fg.muted">{row.explanation}</Text>
                                    </Table.Cell>
                                    <Table.Cell textAlign="right">
                                        <Text fontSize="sm" color={row.lag > 0 ? 'red.fg' : 'fg.muted'}>
                                            {row.lag === null ? '—' : money(row.lag)}
                                        </Text>
                                    </Table.Cell>
                                    <Table.Cell textAlign="right">
                                        <Text fontSize="sm">{money(row.fact)}</Text>
                                        <Text fontSize="xs" color="fg.muted">
                                            {row.plan === null ? 'плана нет' : `из ${money(row.plan)}`}
                                        </Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text fontSize="sm">{row.last_purchase_at || 'не покупал'}</Text>
                                        {row.days_since !== null && (
                                            <Text fontSize="xs" color="fg.muted">{row.days_since} дн. назад</Text>
                                        )}
                                    </Table.Cell>
                                    <Table.Cell textAlign="center">
                                        {row.abc
                                            ? <Badge colorPalette={ABC_COLOR[row.abc]} variant="subtle">{row.abc}</Badge>
                                            : <Text fontSize="xs" color="fg.muted">—</Text>}
                                    </Table.Cell>
                                    <Table.Cell textAlign="right">
                                        <Text fontSize="sm" fontWeight="600">{row.score}</Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <HStack gap={1} justify="end">
                                            {can('crm-tasks.create') && (
                                                <Button
                                                    size="xs"
                                                    variant="ghost"
                                                    onClick={() => setTaskFor(row)}
                                                    aria-label="Поставить задачу"
                                                    title="Поставить задачу"
                                                >
                                                    <LuListChecks />
                                                </Button>
                                            )}
                                            {can('crm-calls.create') && (
                                                <Button
                                                    size="xs"
                                                    variant="ghost"
                                                    onClick={() => setCallFor(row)}
                                                    aria-label="Записать звонок"
                                                    title="Записать звонок"
                                                >
                                                    <LuPhone />
                                                </Button>
                                            )}
                                            {can('crm-emails.create') && (
                                                <Button
                                                    size="xs"
                                                    variant="ghost"
                                                    onClick={() => setEmailFor(row)}
                                                    aria-label="Написать письмо"
                                                    title="Написать письмо"
                                                >
                                                    <LuMail />
                                                </Button>
                                            )}
                                        </HStack>
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </Table.Root>
                </Box>
            )}

            <TaskDialog
                open={taskFor !== null}
                entity={taskFor ? { type: 'client', id: taskFor.id } : null}
                onClose={() => setTaskFor(null)}
                onSaved={() => setTaskFor(null)}
            />

            <CallDialog
                open={callFor !== null}
                client={callFor}
                onClose={() => setCallFor(null)}
                onSaved={() => setCallFor(null)}
            />

            <EmailComposeDialog
                open={emailFor !== null}
                entity={emailFor ? { type: 'client', id: emailFor.id } : null}
                defaultTo={emailFor?.email}
                onClose={() => setEmailFor(null)}
            />
        </VStack>
    );
}
