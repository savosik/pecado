import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import { Badge, Box, Card, Flex, HStack, Heading, Input, Table, Text, VStack } from '@chakra-ui/react';
import { LuHistory, LuMail, LuSearch } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { Button } from '@/components/ui/button';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';

/**
 * Журнал уведомлений: что и кому ушло, а что не ушло и почему.
 *
 * Отрицательные исходы показываются наравне с отправленными — именно они
 * отвечают на вопрос «клиент говорит, что не получил», ради которого
 * раньше приходилось звать разработчика.
 */
export default function Journal({ deliveries, filters, events }) {
    const [form, setForm] = useState({
        event_key: filters?.event_key || '',
        status: filters?.status || '',
        recipient: filters?.recipient || '',
        from: filters?.from || '',
        to: filters?.to || '',
    });

    const apply = () => router.get('/crm/notifications/journal', form, { preserveState: true });
    const reset = () => router.get('/crm/notifications/journal');

    const statusColor = (status) =>
        ({ sent: 'green', queued: 'blue', skipped: 'orange', failed: 'red' })[status] || 'gray';

    return (
        <CrmLayout>
            <Head title="Журнал уведомлений" />

            <VStack align="stretch" gap={5}>
                <HStack gap={3}>
                    <LuHistory size={22} />
                    <Heading size="lg">Журнал уведомлений</Heading>
                </HStack>

                <Box borderWidth="1px" borderRadius="md" p={3} bg="bg.subtle">
                    <HStack gap={2}>
                        <LuMail size={16} />
                        <Text fontSize="sm">
                            Здесь — письма, отправленные системой по правилам пульта. Переписка,
                            которую менеджеры пишут руками, живёт в разделе{' '}
                            <Link href="/crm/emails" style={{ textDecoration: 'underline' }}>Письма</Link>.
                        </Text>
                    </HStack>
                </Box>

                <Card.Root>
                    <Card.Body>
                        <Flex gap={3} wrap="wrap" align="end">
                            <Box flex="1 1 220px">
                                <Text fontSize="xs" color="fg.muted" mb={1}>Событие</Text>
                                <NativeSelectRoot size="sm">
                                    <NativeSelectField
                                        value={form.event_key}
                                        onChange={(e) => setForm({ ...form, event_key: e.target.value })}
                                    >
                                        <option value="">Все события</option>
                                        {events.map((group) => (
                                            <optgroup key={group.group} label={group.group}>
                                                {group.items.map((item) => (
                                                    <option key={item.value} value={item.value}>{item.label}</option>
                                                ))}
                                            </optgroup>
                                        ))}
                                    </NativeSelectField>
                                </NativeSelectRoot>
                            </Box>

                            <Box flex="0 0 180px">
                                <Text fontSize="xs" color="fg.muted" mb={1}>Исход</Text>
                                <NativeSelectRoot size="sm">
                                    <NativeSelectField
                                        value={form.status}
                                        onChange={(e) => setForm({ ...form, status: e.target.value })}
                                    >
                                        <option value="">Любой</option>
                                        <option value="sent">Отправлено</option>
                                        <option value="queued">В очереди</option>
                                        <option value="skipped">Пропущено</option>
                                        <option value="failed">Ошибка</option>
                                    </NativeSelectField>
                                </NativeSelectRoot>
                            </Box>

                            <Box flex="1 1 200px">
                                <Text fontSize="xs" color="fg.muted" mb={1}>Адрес получателя</Text>
                                <Input
                                    size="sm"
                                    value={form.recipient}
                                    onChange={(e) => setForm({ ...form, recipient: e.target.value })}
                                    placeholder="buh@romashka.ru"
                                />
                            </Box>

                            <Box flex="0 0 150px">
                                <Text fontSize="xs" color="fg.muted" mb={1}>С даты</Text>
                                <Input size="sm" type="date" value={form.from} onChange={(e) => setForm({ ...form, from: e.target.value })} />
                            </Box>

                            <Box flex="0 0 150px">
                                <Text fontSize="xs" color="fg.muted" mb={1}>По дату</Text>
                                <Input size="sm" type="date" value={form.to} onChange={(e) => setForm({ ...form, to: e.target.value })} />
                            </Box>

                            <HStack>
                                <Button size="sm" onClick={apply}><LuSearch /> Показать</Button>
                                <Button size="sm" variant="ghost" onClick={reset}>Сбросить</Button>
                            </HStack>
                        </Flex>
                    </Card.Body>
                </Card.Root>

                <Card.Root>
                    <Card.Body>
                        {deliveries.data.length === 0 ? (
                            <Text color="fg.muted">Записей нет.</Text>
                        ) : (
                            <Table.Root size="sm" variant="outline">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>Дата</Table.ColumnHeader>
                                        <Table.ColumnHeader>Событие</Table.ColumnHeader>
                                        <Table.ColumnHeader>Клиент</Table.ColumnHeader>
                                        <Table.ColumnHeader>Адрес</Table.ColumnHeader>
                                        <Table.ColumnHeader>Правило</Table.ColumnHeader>
                                        <Table.ColumnHeader>Исход</Table.ColumnHeader>
                                        <Table.ColumnHeader />
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {deliveries.data.map((row) => (
                                        <Table.Row key={row.id}>
                                            <Table.Cell whiteSpace="nowrap" fontSize="xs">{row.created_at}</Table.Cell>
                                            <Table.Cell fontSize="sm">{row.event_label}</Table.Cell>
                                            <Table.Cell fontSize="sm">
                                                {row.client_user_id ? (
                                                    <Link href={`/crm/partners/${row.client_user_id}`} style={{ textDecoration: 'underline' }}>
                                                        {row.client_name || '—'}
                                                    </Link>
                                                ) : '—'}
                                            </Table.Cell>
                                            <Table.Cell fontSize="sm">{row.recipient}</Table.Cell>
                                            <Table.Cell fontSize="xs" color="fg.muted">{row.rule_name || '—'}</Table.Cell>
                                            <Table.Cell>
                                                <Badge colorPalette={statusColor(row.status)} variant="subtle">
                                                    {row.status_label}
                                                </Badge>
                                                {row.skip_reason_label && (
                                                    <Text fontSize="xs" color="fg.muted" mt={1}>
                                                        {row.skip_reason_label}
                                                    </Text>
                                                )}
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Link href={`/crm/notifications/signals/${row.signal_uuid}`}>
                                                    <Button size="xs" variant="ghost">Разбор</Button>
                                                </Link>
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        )}

                        {deliveries.links && deliveries.last_page > 1 && (
                            <HStack mt={4} gap={1} wrap="wrap">
                                {deliveries.links.map((link, index) => (
                                    <Button
                                        key={index}
                                        size="xs"
                                        variant={link.active ? 'solid' : 'ghost'}
                                        disabled={!link.url}
                                        onClick={() => link.url && router.get(link.url)}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </HStack>
                        )}
                    </Card.Body>
                </Card.Root>
            </VStack>
        </CrmLayout>
    );
}
