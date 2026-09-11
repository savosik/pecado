import { Head, router } from '@inertiajs/react';
import { Badge, Box, HStack, SimpleGrid, Table, Text, VStack } from '@chakra-ui/react';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Alert } from '@/components/ui/alert';
import { fmtCompact, fmtFactor, fmtPercent, fmtRub0 } from '../Salary/components/format';

const selectStyle = {
    padding: '0.45rem 0.6rem',
    borderRadius: '0.5rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '180px',
    background: 'var(--chakra-colors-bg-panel)',
    color: 'var(--chakra-colors-fg)',
};

const MONTHS = ['янв', 'фев', 'мар', 'апр', 'май', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
const monthShort = (iso) => {
    const [y, m] = String(iso).split('-').map(Number);
    return `${MONTHS[(m || 1) - 1]} ${String(y).slice(2)}`;
};

const formatStep = (step) => {
    if (step.value === null || step.value === undefined) return '—';
    switch (step.unit) {
        case 'rub': return fmtRub0(step.value);
        case 'days': return `${step.value}`;
        case 'factor': return fmtFactor(step.value);
        case 'share': return fmtPercent(step.value, 1);
        default: return String(step.value);
    }
};

/**
 * «Откуда мой план»: расчёт по шагам, что вошло в медиану, три месяца квартала.
 *
 * Прогноза «как падение продаж уменьшит план следующего квартала» нет намеренно:
 * он подсказывает провалить квартал ради лёгкого плана.
 */
export default function MotivationPlan({ month, manager, scope_options: scopeOptions, can_see_all: canSeeAll, data }) {
    const navigate = (changes) => {
        const params = { month, ...changes };
        if (canSeeAll && manager?.id && !('manager' in changes)) params.manager = manager.id;
        Object.keys(params).forEach((k) => { if (params[k] === '' || params[k] === null || params[k] === undefined) delete params[k]; });
        router.get('/crm/motivation/plan', params, { preserveState: true, preserveScroll: true, replace: true });
    };

    const chart = (data?.sample?.by_month ?? []).map((m) => ({
        name: monthShort(m.month),
        amount: m.amount,
        excluded: m.excluded_days,
        days: m.working_days,
    }));

    return (
        <CrmLayout breadcrumbs={[{ label: 'Продажи' }, { label: 'Моя мотивация', href: '/crm/motivation' }, { label: 'Откуда мой план' }]}>
            <Head title="Откуда мой план — CRM" />
            <PageHeader
                title={canSeeAll && manager ? `План: ${manager.name}` : 'Откуда мой план'}
                description="Почему план такой и как он изменится."
                actions={canSeeAll && (scopeOptions ?? []).length > 0 ? (
                    <select aria-label="Работник" style={selectStyle} value={manager?.id ?? ''} onChange={(e) => navigate({ manager: e.target.value })}>
                        <option value="">Выберите работника…</option>
                        {scopeOptions.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                    </select>
                ) : null}
            />

            <VStack align="stretch" gap={4} maxW="1100px">
                {manager === null && (
                    <Alert status="info" title="Карточка работника не привязана">Выберите работника выше или обратитесь к руководителю.</Alert>
                )}

                {data && (
                    <>
                        {!data.approved && (
                            <Alert status="warning" title="План на квартал не утверждён — показан расчётный, предварительно">
                                Значения ниже посчитаны по формуле Положения на сегодняшних данных. Приказ о плане издаёт руководитель до начала квартала.
                            </Alert>
                        )}
                        {data.approved && data.order && (
                            <HStack gap={2} fontSize="sm" color="fg.muted">
                                <Badge colorPalette="green" variant="subtle">утверждён</Badge>
                                <Text>приказ, версия {data.order.version}{data.order.decline_limited ? ' · применён предел снижения' : ''}</Text>
                            </HStack>
                        )}

                        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={{ base: 4, md: 5 }}>
                            <Text fontWeight="700" mb={3}>Расчёт по шагам</Text>
                            <VStack align="stretch" gap={0} divideY="1px" divideColor="border">
                                {data.steps.map((step) => (
                                    <HStack key={step.key} py={2.5} gap={4} align="start">
                                        <Box flex="1" minW={0}>
                                            <Text fontSize="sm" fontWeight={step.key === 'total' ? '700' : '600'}>{step.label}</Text>
                                            <Text fontSize="xs" color="fg.muted">{step.note}</Text>
                                        </Box>
                                        <Text fontWeight={step.key === 'total' ? '800' : '600'} fontSize={step.key === 'total' ? 'lg' : 'md'} fontVariantNumeric="tabular-nums" flexShrink={0}>
                                            {formatStep(step)}
                                        </Text>
                                    </HStack>
                                ))}
                            </VStack>
                        </Box>

                        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={{ base: 4, md: 5 }}>
                            <Text fontWeight="700">Что вошло в медиану</Text>
                            <Text fontSize="xs" color="fg.muted" mb={3}>
                                Отгрузки закреплённой базы по месяцам выборки. Дни отсутствия по табелю исключены — они помечены под столбцами.
                                {data.sample.zero_days > 0 ? ` Дней без отгрузок: ${data.sample.zero_days} из ${data.sample.days}.` : ''}
                            </Text>
                            <Box h="220px">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={chart} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                                        <CartesianGrid strokeDasharray="3 3" stroke="var(--chakra-colors-border)" />
                                        <XAxis dataKey="name" tick={{ fontSize: 11 }} />
                                        <YAxis tickFormatter={(v) => fmtCompact(v)} tick={{ fontSize: 11 }} width={56} />
                                        <Tooltip formatter={(v) => [fmtRub0(v), 'Отгрузки базы']} labelFormatter={(l, p) => `${l}${p?.[0]?.payload?.excluded ? ` · исключено дней: ${p[0].payload.excluded}` : ''}`} />
                                        <Bar dataKey="amount" fill="var(--chakra-colors-blue-solid)" radius={[4, 4, 0, 0]} />
                                    </BarChart>
                                </ResponsiveContainer>
                            </Box>
                            <HStack gap={3} mt={2} flexWrap="wrap" fontSize="xs" color="fg.subtle">
                                {chart.map((m) => (
                                    <Text key={m.name}>{m.name}: {m.days} раб. дн.{m.excluded > 0 ? `, исключено ${m.excluded}` : ''}</Text>
                                ))}
                            </HStack>
                        </Box>

                        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" overflowX="auto">
                            <Text fontWeight="700" px={4} pt={3}>Три месяца квартала</Text>
                            <Table.Root size="sm">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>Месяц</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="right">Рабочих дней</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="right">Сезон</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="right">{data.approved ? 'План' : 'Расчётный план'}</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="right">Действующий сейчас</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {data.months.map((m) => (
                                        <Table.Row key={m.month} bg={m.is_this_month ? 'bg.subtle' : undefined}>
                                            <Table.Cell><Text fontSize="sm" fontWeight={m.is_this_month ? '700' : undefined}>{monthShort(m.month)}{m.is_this_month ? ' · этот месяц' : ''}</Text></Table.Cell>
                                            <Table.Cell textAlign="right"><Text fontSize="sm">{m.working_days}</Text></Table.Cell>
                                            <Table.Cell textAlign="right"><Text fontSize="sm">{fmtFactor(m.seasonal)}</Text></Table.Cell>
                                            <Table.Cell textAlign="right"><Text fontSize="sm" fontWeight="700" fontVariantNumeric="tabular-nums">{fmtRub0(m.plan)}</Text></Table.Cell>
                                            <Table.Cell textAlign="right"><Text fontSize="sm" color="fg.muted" fontVariantNumeric="tabular-nums">{m.current_plan === null ? '—' : fmtRub0(m.current_plan)}</Text></Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        </Box>

                        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="xl" p={{ base: 4, md: 5 }}>
                            <Text fontWeight="700" mb={2}>Что меняет план</Text>
                            <VStack align="stretch" gap={1.5}>
                                {data.rules.map((rule) => (
                                    <Text key={rule} fontSize="sm">• {rule}</Text>
                                ))}
                            </VStack>
                        </Box>
                    </>
                )}
            </VStack>
        </CrmLayout>
    );
}
