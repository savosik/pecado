import { Head, Link, router } from '@inertiajs/react';
import { Badge, Card, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import { LuArrowLeft } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { DataTable } from '@/Admin/Components/DataTable';
import { Button } from '@/components/ui/button';
import {
    AgentsList, DailyChart, ErrorsList, OperationsTable, PeriodSwitch, SummaryTiles,
} from './components/UsageWidgets';

const KIND_COLORS = { mcp_connect: 'gray', mcp_tool: 'blue', rest: 'purple' };

/**
 * Журнал вызовов одного партнёра: что его агент делал и с каким исходом.
 */
export default function Show({
    partner, summary, daily = [], operations = [], agents = [], errors = [], calls, filters = {}, periods = [],
}) {
    const setPeriod = (period) => {
        router.get(route('crm.agent-usage.show', partner.id), { period }, { preserveState: true, replace: true });
    };

    const columns = [
        { key: 'created_at', label: 'Когда', render: (v) => <Text fontSize="sm" whiteSpace="nowrap">{v}</Text> },
        {
            key: 'kind',
            label: 'Вызов',
            render: (_, row) => (
                <VStack align="start" gap={0.5}>
                    <HStack gap={1.5}>
                        <Badge size="xs" variant="subtle" colorPalette={KIND_COLORS[row.kind] || 'gray'}>{row.kind_label}</Badge>
                        {row.tool && <Text fontSize="xs" fontFamily="mono">{row.tool}</Text>}
                    </HStack>
                    {row.operation && (
                        <Text fontSize="sm">
                            {row.operation_label}
                            <Text as="span" fontSize="xs" color="fg.muted" fontFamily="mono"> {row.operation}</Text>
                            {row.mutating && <Badge ml={1} size="xs" variant="subtle" colorPalette="green">запись</Badge>}
                        </Text>
                    )}
                </VStack>
            ),
        },
        {
            key: 'ok',
            label: 'Исход',
            render: (v, row) => (v
                ? <Badge size="xs" variant="subtle" colorPalette="green">успех</Badge>
                : (
                    <VStack align="start" gap={0}>
                        <Badge size="xs" variant="subtle" colorPalette="orange">{row.error_label}</Badge>
                        <Text fontSize="xs" color="fg.muted" fontFamily="mono">{row.error_code}</Text>
                    </VStack>
                )),
        },
        { key: 'duration_ms', label: 'мс', render: (v) => <Text fontSize="xs" color="fg.muted">{v}</Text> },
        {
            key: 'agent',
            label: 'Агент / токен',
            render: (v, row) => (
                <VStack align="start" gap={0}>
                    {v && <Text fontSize="xs" fontFamily="mono">{v}</Text>}
                    <Text fontSize="xs" color="fg.muted">{row.token ? `«${row.token}»` : '—'}{row.session_id ? ` · сессия ${row.session_id}` : ''}</Text>
                </VStack>
            ),
        },
    ];

    return (
        <>
            <Head title={`CRM — ИИ-агент: ${partner.name}`} />
            <PageHeader
                title={partner.name}
                description={`Журнал вызовов ИИ-агента партнёра${partner.manager ? ` · менеджер ${partner.manager}` : ''}`}
                actions={(
                    <HStack gap={3}>
                        <Button asChild size="sm" variant="ghost">
                            <Link href={route('crm.agent-usage.index', { period: filters.period })}><LuArrowLeft /> Все партнёры</Link>
                        </Button>
                        <Button asChild size="sm" variant="outline">
                            <Link href={partner.url}>Карточка партнёра</Link>
                        </Button>
                        <PeriodSwitch value={filters.period} periods={periods} onChange={setPeriod} />
                    </HStack>
                )}
            />

            <SummaryTiles summary={summary} />
            <DailyChart data={daily} />

            <SimpleGrid columns={{ base: 1, xl: 2 }} gap={4} mb={4}>
                <OperationsTable operations={operations} />
                <VStack align="stretch" gap={4}>
                    <AgentsList agents={agents} />
                    <ErrorsList errors={errors} />
                </VStack>
            </SimpleGrid>

            <Card.Root size="sm">
                <Card.Body>
                    <Text fontWeight="600" mb={2}>Вызовы</Text>
                    <DataTable
                        columns={columns}
                        data={calls?.data || []}
                        pagination={calls}
                        emptyMessage="За период вызовов не было."
                    />
                </Card.Body>
            </Card.Root>
        </>
    );
}

Show.layout = (page) => <CrmLayout>{page}</CrmLayout>;
