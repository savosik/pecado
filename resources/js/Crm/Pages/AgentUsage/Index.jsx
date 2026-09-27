import { Head, Link, router } from '@inertiajs/react';
import { Badge, Box, Card, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { DataTable } from '@/Admin/Components/DataTable';
import ScopeToggle from '@/Crm/Components/ScopeToggle';
import RowActions from '@/shared/Panel/RowActions';
import {
    AgentsList, DailyChart, ErrorsList, OperationsTable, PeriodSwitch, SummaryTiles,
} from './components/UsageWidgets';

const fmtInt = (v) => Number(v ?? 0).toLocaleString('ru-RU');

/**
 * «ИИ-агенты клиентов»: пользуются ли партнёры MCP-сервером и API v1.
 *
 * Строка — партнёр с вызовами за период; глаз открывает его журнал. Ниже —
 * чем агенты заняты, какими клиентами подключаются, на чём спотыкаются и у
 * кого токен выдан, но лежит без дела. Разрез «только мои / отдел» — общий.
 */
export default function Index({
    summary, daily = [], partners = [], operations = [], agents = [], errors = [], idleTokens = [],
    assistant = null, filters = {}, periods = [], canSeeDepartment = false, endpoints = {},
}) {
    const setPeriod = (period) => {
        router.get(route('crm.agent-usage.index'), { ...filters, period }, { preserveState: true, replace: true });
    };

    const columns = [
        {
            key: 'name',
            label: 'Партнёр',
            render: (_, row) => (
                <VStack align="start" gap={0} minW="200px">
                    <Text fontSize="sm" fontWeight="600">{row.name}</Text>
                    {row.manager && <Text fontSize="xs" color="fg.muted">{row.manager}</Text>}
                </VStack>
            ),
        },
        {
            key: 'agents',
            label: 'Агент',
            render: (_, row) => (
                <HStack gap={1} flexWrap="wrap" maxW="220px">
                    {row.agents.length === 0
                        ? <Text fontSize="xs" color="fg.muted">{row.rest_calls > 0 && row.tool_calls === 0 ? 'только REST' : '—'}</Text>
                        : row.agents.map((a) => <Badge key={a} size="xs" variant="subtle" colorPalette="blue">{a}</Badge>)}
                </HStack>
            ),
        },
        { key: 'connects', label: 'Подкл.', render: (v, row) => <Text fontSize="sm">{fmtInt(v)}<Text as="span" fontSize="xs" color="fg.muted"> / {fmtInt(row.sessions)} сесс.</Text></Text> },
        { key: 'tool_calls', label: 'MCP', render: (v) => fmtInt(v) },
        { key: 'rest_calls', label: 'REST', render: (v) => fmtInt(v) },
        { key: 'orders', label: 'Заказов', render: (v) => <Text fontWeight={v > 0 ? '600' : '400'} color={v > 0 ? 'green.fg' : 'fg.muted'}>{fmtInt(v)}</Text> },
        { key: 'questions', label: 'Вопросов', render: (v) => fmtInt(v) },
        { key: 'errors', label: 'Отказов', render: (v) => <Text color={v > 0 ? 'orange.fg' : 'fg.muted'}>{fmtInt(v)}</Text> },
        { key: 'last_at', label: 'Последний вызов', render: (v, row) => (
            <VStack align="start" gap={0}>
                <Text fontSize="sm">{v}</Text>
                <Text fontSize="xs" color="fg.muted">первый {row.first_at}</Text>
            </VStack>
        ) },
        {
            key: 'actions',
            label: '',
            render: (_, row) => (
                <RowActions view={{ href: route('crm.agent-usage.show', { client: row.id, period: filters.period }), label: 'Журнал вызовов' }} />
            ),
        },
    ];

    return (
        <>
            <Head title="CRM — ИИ-агенты клиентов" />
            <PageHeader
                title="ИИ-агенты клиентов"
                description="Пользуются ли партнёры своим ИИ-агентом через MCP-сервер и REST API v1: кто, как часто и для чего"
                actions={(
                    <HStack gap={4}>
                        <ScopeToggle section="agent-usage" scope={filters.scope} available={canSeeDepartment} />
                        <PeriodSwitch value={filters.period} periods={periods} onChange={setPeriod} />
                    </HStack>
                )}
            />

            <Text fontSize="xs" color="fg.muted" mb={4}>
                Журнал ведётся по вызовам {endpoints.mcp} и {endpoints.rest}. Считаются подключения агента, вызовы инструментов и запросы REST;
                состав заказов и аргументы не сохраняются — они в карточке партнёра. «Заказов через агента» — успешные orders.create и checkout.submit.
            </Text>

            <SummaryTiles summary={summary} />
            <DailyChart data={daily} />

            <Card.Root size="sm" mb={4}>
                <Card.Body>
                    <Text fontWeight="600" mb={2}>Партнёры с вызовами</Text>
                    <DataTable
                        columns={columns}
                        data={partners}
                        emptyMessage="За период ни один партнёр агентом не пользовался."
                    />
                </Card.Body>
            </Card.Root>

            <SimpleGrid columns={{ base: 1, xl: 2 }} gap={4} mb={4}>
                <OperationsTable operations={operations} />
                <VStack align="stretch" gap={4}>
                    <AgentsList agents={agents} />
                    <ErrorsList errors={errors} />
                </VStack>
            </SimpleGrid>

            {assistant && (
                <Card.Root size="sm" mb={4}>
                    <Card.Body>
                        <Text fontWeight="600" mb={1}>Помощник на сайте</Text>
                        <Text fontSize="xs" color="fg.muted" mb={3}>
                            Иконка-консультант у клиентов кабинета: сколько человек её видели, открыли диалог, написали и подтвердили действие.
                            Считается по клиентам, не по событиям.
                        </Text>
                        <SimpleGrid columns={{ base: 2, md: 4 }} gap={3} mb={3}>
                            {assistant.funnel.map((step, i) => (
                                <Box key={step.key} borderWidth="1px" borderColor="border.muted" borderRadius="md" p={3}>
                                    <Text fontSize="xs" color="fg.muted">{step.label}</Text>
                                    <HStack align="baseline" gap={2}>
                                        <Text fontSize="xl" fontWeight="700">{step.value}</Text>
                                        {i > 0 && assistant.funnel[0].value > 0 && (
                                            <Text fontSize="xs" color="fg.muted">
                                                {Math.round((step.value / assistant.funnel[0].value) * 100)}%
                                            </Text>
                                        )}
                                    </HStack>
                                </Box>
                            ))}
                        </SimpleGrid>
                        <HStack gap={4} fontSize="sm" flexWrap="wrap" mb={assistant.bubbles.length ? 3 : 0}>
                            <Text>Разговоров: <b>{assistant.threads}</b></Text>
                            <Text>Ответов: <b>{assistant.turns}</b></Text>
                            <Text>Заказов из чата: <b>{assistant.orders}</b></Text>
                            <Text>Расход: <b>${assistant.cost_usd}</b>{assistant.cost_per_thread_usd > 0 && <Text as="span" color="fg.muted"> (${assistant.cost_per_thread_usd} на разговор)</Text>}</Text>
                            <Text>Ушло менеджеру: <b>{assistant.escalated}</b>{assistant.escalation_share > 0 && <Text as="span" color="fg.muted"> ({assistant.escalation_share}% разговоров)</Text>}</Text>
                        </HStack>
                        {assistant.bubbles.length > 0 && (
                            <VStack align="stretch" gap={1}>
                                <Text fontSize="xs" color="fg.muted">Какие реплики иконки ведут в диалог</Text>
                                {assistant.bubbles.map((b) => (
                                    <HStack key={b.key} justify="space-between" fontSize="sm">
                                        <Text fontFamily="mono" fontSize="xs">{b.key}</Text>
                                        <Text color="fg.muted" fontSize="xs">показов {b.shown} · кликов {b.clicked} · {b.ctr}%</Text>
                                    </HStack>
                                ))}
                            </VStack>
                        )}
                    </Card.Body>
                </Card.Root>
            )}

            <Card.Root size="sm">
                <Card.Body>
                    <Text fontWeight="600" mb={1}>Токены без вызовов за период</Text>
                    <Text fontSize="xs" color="fg.muted" mb={2}>
                        Партнёр выдал токен в кабинете, но агент за период не обращался: не подключил, бросил или пользуется реже. Повод спросить.
                    </Text>
                    {idleTokens.length === 0 ? (
                        <Text fontSize="sm" color="fg.muted">Все выданные токены за период использовались.</Text>
                    ) : (
                        <VStack align="stretch" gap={1}>
                            {idleTokens.map((t) => (
                                <HStack key={t.id} justify="space-between" fontSize="sm" flexWrap="wrap">
                                    <HStack gap={2}>
                                        <Link href={route('crm.clients.show', t.partner.id)}>
                                            <Text fontWeight="600" _hover={{ textDecoration: 'underline' }}>{t.partner.name}</Text>
                                        </Link>
                                        <Text color="fg.muted">«{t.name}»</Text>
                                        {t.manager && <Text fontSize="xs" color="fg.muted">{t.manager}</Text>}
                                    </HStack>
                                    <Box fontSize="xs" color="fg.muted">
                                        выдан {t.created_at}{t.last_used_at ? ` · последний раз ${t.last_used_at}` : ' · не использовался'}
                                    </Box>
                                </HStack>
                            ))}
                        </VStack>
                    )}
                </Card.Body>
            </Card.Root>
        </>
    );
}

Index.layout = (page) => <CrmLayout>{page}</CrmLayout>;
