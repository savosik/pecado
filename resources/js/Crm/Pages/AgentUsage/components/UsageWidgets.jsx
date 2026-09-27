import { Badge, Box, Card, HStack, SimpleGrid, Table, Text, VStack } from '@chakra-ui/react';
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import { SegmentedControl } from '@/components/ui/segmented-control';

const fmtInt = (v) => Number(v ?? 0).toLocaleString('ru-RU');

const PERIOD_LABELS = { 7: '7 дней', 30: '30 дней', 90: '90 дней', 365: 'Год' };

/**
 * Переключатель периода отчёта: значения приходят с сервера, чтобы экран и
 * сводка не разошлись в том, что считается «месяцем».
 */
export function PeriodSwitch({ value, periods = [], onChange }) {
    return (
        <SegmentedControl
            size="sm"
            value={String(value)}
            onValueChange={(e) => onChange(Number(e.value))}
            items={periods.map((days) => ({ value: String(days), label: PERIOD_LABELS[days] || `${days} дн.` }))}
        />
    );
}

export function StatTile({ label, value, hint, color = 'gray' }) {
    return (
        <Card.Root size="sm">
            <Card.Body>
                <VStack align="start" gap={0.5}>
                    <Text fontSize="xs" color="fg.muted">{label}</Text>
                    <Text fontSize="2xl" fontWeight="bold" color={`${color}.fg`}>{value}</Text>
                    {hint && <Text fontSize="xs" color="fg.muted">{hint}</Text>}
                </VStack>
            </Card.Body>
        </Card.Root>
    );
}

/**
 * Шесть плиток сводки: охват (партнёры), объём (подключения, вызовы),
 * качество (ошибки) и польза (заказы, вопросы) — одним взглядом.
 */
export function SummaryTiles({ summary }) {
    const s = summary || {};

    return (
        <SimpleGrid columns={{ base: 2, md: 3, xl: 6 }} gap={3} mb={4}>
            <StatTile
                label="Партнёров с агентом"
                value={fmtInt(s.partners)}
                hint={`из ${fmtInt(s.partners_with_tokens)} с токеном`}
                color={s.partners > 0 ? 'green' : 'gray'}
            />
            <StatTile
                label="Подключений"
                value={fmtInt(s.connects)}
                hint={`сессий: ${fmtInt(s.sessions)}`}
            />
            <StatTile
                label="Вызовов"
                value={fmtInt(s.requests)}
                hint={`MCP ${fmtInt(s.tool_calls)} · REST ${fmtInt(s.rest_calls)}`}
                color="blue"
            />
            <StatTile
                label="Ошибок"
                value={`${Number(s.error_rate ?? 0).toLocaleString('ru-RU')}%`}
                hint={`${fmtInt(s.errors)} отказов`}
                color={s.errors > 0 ? 'orange' : 'gray'}
            />
            <StatTile
                label="Заказов через агента"
                value={fmtInt(s.orders)}
                hint="создано и оформлено"
                color={s.orders > 0 ? 'green' : 'gray'}
            />
            <StatTile
                label="Вопросов менеджеру"
                value={fmtInt(s.questions)}
                hint="задано агентом"
            />
        </SimpleGrid>
    );
}

function tick(date) {
    const d = new Date(date);
    if (Number.isNaN(d.getTime())) return date;
    return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' });
}

function DayTooltip({ active, payload, label }) {
    if (!active || !payload || payload.length === 0) return null;

    return (
        <Box bg="bg" borderWidth="1px" borderColor="border" borderRadius="md" p={2} boxShadow="md">
            <Text fontSize="xs" color="fg.muted" mb={1}>{tick(label)}</Text>
            {payload.map((p) => (
                <HStack key={p.dataKey} gap={2} fontSize="sm">
                    <Box w="8px" h="8px" borderRadius="full" bg={p.color} />
                    <Text fontWeight="600">{p.name}:</Text>
                    <Text>{fmtInt(p.value)}</Text>
                </HStack>
            ))}
        </Box>
    );
}

/**
 * Вызовы по дням: MCP и REST столбиками, отказы — отдельным столбиком, чтобы
 * всплеск ошибок был виден на фоне объёма.
 */
export function DailyChart({ data = [] }) {
    const empty = data.every((d) => d.tool_calls === 0 && d.rest_calls === 0 && d.connects === 0);

    return (
        <Card.Root size="sm" mb={4}>
            <Card.Body>
                <Text fontWeight="600" mb={2}>Вызовы по дням</Text>
                {empty ? (
                    <Text fontSize="sm" color="fg.muted">За период вызовов не было.</Text>
                ) : (
                    <Box w="100%" h="220px">
                        <ResponsiveContainer>
                            <BarChart data={data} margin={{ top: 5, right: 10, left: 0, bottom: 0 }}>
                                <CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" />
                                <XAxis dataKey="date" tickFormatter={tick} fontSize={11} />
                                <YAxis fontSize={11} width={40} allowDecimals={false} />
                                <Tooltip content={<DayTooltip />} />
                                <Legend />
                                <Bar dataKey="tool_calls" name="MCP" stackId="calls" fill="#3182ce" />
                                <Bar dataKey="rest_calls" name="REST" stackId="calls" fill="#805ad5" />
                                <Bar dataKey="errors" name="Отказы" fill="#dd6b20" />
                            </BarChart>
                        </ResponsiveContainer>
                    </Box>
                )}
            </Card.Body>
        </Card.Root>
    );
}

/**
 * Чем заняты агенты: операции реестра по убыванию, с долей и числом партнёров.
 */
export function OperationsTable({ operations = [] }) {
    return (
        <Card.Root size="sm">
            <Card.Body>
                <Text fontWeight="600" mb={2}>Какие задачи решают</Text>
                {operations.length === 0 ? (
                    <Text fontSize="sm" color="fg.muted">Вызовов операций за период не было.</Text>
                ) : (
                    <Table.Root size="sm" variant="line">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeader>Операция</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="end">Вызовов</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="end">Доля</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="end">Партнёров</Table.ColumnHeader>
                                <Table.ColumnHeader textAlign="end">Отказов</Table.ColumnHeader>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {operations.map((op) => (
                                <Table.Row key={op.key}>
                                    <Table.Cell>
                                        <VStack align="start" gap={0}>
                                            <Text fontSize="sm">{op.label}</Text>
                                            <HStack gap={1.5}>
                                                <Text fontSize="xs" color="fg.muted" fontFamily="mono">{op.key.replace(/^(op|tool):/, '')}</Text>
                                                {op.section && <Badge size="xs" variant="subtle" colorPalette="gray">{op.section}</Badge>}
                                                {op.via_mcp > 0 && op.via_mcp < op.calls && (
                                                    <Badge size="xs" variant="subtle" colorPalette="blue">MCP {fmtInt(op.via_mcp)}</Badge>
                                                )}
                                            </HStack>
                                        </VStack>
                                    </Table.Cell>
                                    <Table.Cell textAlign="end">{fmtInt(op.calls)}</Table.Cell>
                                    <Table.Cell textAlign="end">{Number(op.share).toLocaleString('ru-RU')}%</Table.Cell>
                                    <Table.Cell textAlign="end">{fmtInt(op.partners)}</Table.Cell>
                                    <Table.Cell textAlign="end" color={op.errors > 0 ? 'orange.fg' : 'fg.muted'}>{fmtInt(op.errors)}</Table.Cell>
                                </Table.Row>
                            ))}
                        </Table.Body>
                    </Table.Root>
                )}
            </Card.Body>
        </Card.Root>
    );
}

/**
 * Какими ИИ-клиентами подключаются — по clientInfo протокола MCP.
 */
export function AgentsList({ agents = [] }) {
    return (
        <Card.Root size="sm">
            <Card.Body>
                <Text fontWeight="600" mb={2}>Какими агентами подключаются</Text>
                {agents.length === 0 ? (
                    <Text fontSize="sm" color="fg.muted">Ни один агент ещё не представился (подключений MCP не было).</Text>
                ) : (
                    <VStack align="stretch" gap={1.5}>
                        {agents.map((a) => (
                            <HStack key={a.agent} justify="space-between" fontSize="sm">
                                <Text fontFamily="mono" fontSize="xs">{a.agent}</Text>
                                <HStack gap={2} color="fg.muted" fontSize="xs" flexShrink={0}>
                                    <Text>подкл. {fmtInt(a.connects)}</Text>
                                    <Text>вызовов {fmtInt(a.tool_calls)}</Text>
                                    <Text>партн. {fmtInt(a.partners)}</Text>
                                </HStack>
                            </HStack>
                        ))}
                    </VStack>
                )}
            </Card.Body>
        </Card.Root>
    );
}

/**
 * На чём агенты спотыкаются: коды отказов. Частый код — повод поправить
 * инструкции сервера или сам инструмент, а не только «клиент не разобрался».
 */
export function ErrorsList({ errors = [] }) {
    return (
        <Card.Root size="sm">
            <Card.Body>
                <Text fontWeight="600" mb={2}>Частые отказы</Text>
                {errors.length === 0 ? (
                    <Text fontSize="sm" color="fg.muted">Отказов за период не было.</Text>
                ) : (
                    <VStack align="stretch" gap={1.5}>
                        {errors.map((e) => (
                            <HStack key={e.code} justify="space-between" fontSize="sm">
                                <VStack align="start" gap={0}>
                                    <Text>{e.label}</Text>
                                    <Text fontFamily="mono" fontSize="xs" color="fg.muted">{e.code}</Text>
                                </VStack>
                                <HStack gap={2} color="fg.muted" fontSize="xs" flexShrink={0}>
                                    <Text>{fmtInt(e.calls)} раз</Text>
                                    <Text>партн. {fmtInt(e.partners)}</Text>
                                </HStack>
                            </HStack>
                        ))}
                    </VStack>
                )}
            </Card.Body>
        </Card.Root>
    );
}
