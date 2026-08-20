import { Head, Link } from '@inertiajs/react';
import { Badge, Box, Card, Flex, HStack, Heading, Table, Text, VStack } from '@chakra-ui/react';
import { LuCircleCheck, LuCircleX, LuOctagonX, LuRoute } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';

/**
 * Разбор одного события: какие правила рассматривались, что решило каждое,
 * где остановился разбор и кто в итоге получил письмо.
 *
 * Экран, ради которого заводился журнал сигналов: он отвечает менеджеру
 * на «почему клиенту не пришло» без чтения кода.
 */
export default function SignalTrace({ signal, rules, deliveries }) {
    const stateBadge = (state) =>
        ({
            matched: { color: 'green', label: 'Сработало', icon: <LuCircleCheck size={12} /> },
            skipped: { color: 'gray', label: 'Не подошло', icon: <LuCircleX size={12} /> },
            inactive: { color: 'gray', label: 'Выключено', icon: null },
            not_reached: { color: 'orange', label: 'Не рассматривалось', icon: <LuOctagonX size={12} /> },
        })[state] || { color: 'gray', label: state, icon: null };

    return (
        <CrmLayout>
            <Head title="Разбор события" />

            <VStack align="stretch" gap={5}>
                <HStack gap={3}>
                    <LuRoute size={22} />
                    <Heading size="lg">Разбор события</Heading>
                </HStack>

                <Card.Root>
                    <Card.Header pb={2}>
                        <Heading size="sm">{signal.event_label}</Heading>
                    </Card.Header>
                    <Card.Body pt={0}>
                        <Flex gap={6} wrap="wrap" mb={4}>
                            <Box>
                                <Text fontSize="xs" color="fg.muted">Когда</Text>
                                <Text fontSize="sm">{signal.created_at}</Text>
                            </Box>
                            <Box>
                                <Text fontSize="xs" color="fg.muted">Клиент</Text>
                                <Text fontSize="sm">
                                    {signal.client_user_id ? (
                                        <Link href={`/crm/partners/${signal.client_user_id}`} style={{ textDecoration: 'underline' }}>
                                            {signal.client_name || '—'}
                                        </Link>
                                    ) : '—'}
                                </Text>
                            </Box>
                            <Box>
                                <Text fontSize="xs" color="fg.muted">Контрагент</Text>
                                <Text fontSize="sm">{signal.company_name || '—'}</Text>
                            </Box>
                            <Box>
                                <Text fontSize="xs" color="fg.muted">Совпало правил</Text>
                                <Text fontSize="sm">{signal.matched_rules_count}</Text>
                            </Box>
                            <Box>
                                <Text fontSize="xs" color="fg.muted">Режим</Text>
                                <Text fontSize="sm">
                                    {signal.mode === 'shadow'
                                        ? 'теневой (письма не отправлялись)'
                                        : signal.mode === 'dry_run' ? 'предпросмотр' : 'боевой'}
                                </Text>
                            </Box>
                        </Flex>

                        {signal.data.length > 0 && (
                            <Box>
                                <Text fontWeight="600" fontSize="sm" mb={2}>Что произошло</Text>
                                <Table.Root size="sm" variant="outline">
                                    <Table.Body>
                                        {signal.data.map((row, index) => (
                                            <Table.Row key={index}>
                                                <Table.Cell width="40%" color="fg.muted">{row.label}</Table.Cell>
                                                <Table.Cell>{row.value}</Table.Cell>
                                            </Table.Row>
                                        ))}
                                    </Table.Body>
                                </Table.Root>
                            </Box>
                        )}
                    </Card.Body>
                </Card.Root>

                <Card.Root>
                    <Card.Header pb={2}>
                        <Heading size="sm">Как разбирались правила</Heading>
                        <Text fontSize="xs" color="fg.muted">
                            Сверху вниз, в порядке приоритета. Разбор считается по текущим правилам —
                            если правило меняли после события, результат мог быть иным.
                        </Text>
                    </Card.Header>
                    <Card.Body pt={0}>
                        {rules.length === 0 ? (
                            <Text color="fg.muted">
                                Правил для этого события нет вовсе — поэтому письмо никому не ушло.
                            </Text>
                        ) : (
                            <VStack align="stretch" gap={0}>
                                {rules.map((rule, index) => {
                                    const badge = stateBadge(rule.state);
                                    return (
                                        <Box key={index} borderTopWidth={index === 0 ? 0 : '1px'} py={3}>
                                            <HStack gap={2} mb={1} wrap="wrap">
                                                <Badge variant="outline">{rule.priority}</Badge>
                                                <Text fontWeight="600" fontSize="sm">{rule.name}</Text>
                                                <Badge colorPalette={badge.color} variant="subtle">
                                                    {badge.icon} {badge.label}
                                                </Badge>
                                                {rule.stop_processing && rule.state === 'matched' && (
                                                    <Badge colorPalette="red" variant="subtle">разбор остановлен</Badge>
                                                )}
                                            </HStack>
                                            <Text fontSize="xs" color="fg.muted">{rule.note}</Text>
                                        </Box>
                                    );
                                })}
                            </VStack>
                        )}
                    </Card.Body>
                </Card.Root>

                <Card.Root>
                    <Card.Header pb={2}>
                        <Heading size="sm">Кому ушло</Heading>
                    </Card.Header>
                    <Card.Body pt={0}>
                        {deliveries.length === 0 ? (
                            <Text color="fg.muted">Ни одного адресата.</Text>
                        ) : (
                            <Table.Root size="sm" variant="outline">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>Адрес</Table.ColumnHeader>
                                        <Table.ColumnHeader>Правило</Table.ColumnHeader>
                                        <Table.ColumnHeader>Исход</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {deliveries.map((row, index) => (
                                        <Table.Row key={index}>
                                            <Table.Cell fontSize="sm">{row.recipient}</Table.Cell>
                                            <Table.Cell fontSize="xs" color="fg.muted">{row.rule_name}</Table.Cell>
                                            <Table.Cell fontSize="sm">
                                                {row.status_label}
                                                {row.skip_reason_label && (
                                                    <Text fontSize="xs" color="fg.muted">{row.skip_reason_label}</Text>
                                                )}
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        )}
                    </Card.Body>
                </Card.Root>
            </VStack>
        </CrmLayout>
    );
}
