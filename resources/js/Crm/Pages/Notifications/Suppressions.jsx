import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Badge, Box, Card, Flex, HStack, Heading, Input, Table, Text, VStack } from '@chakra-ui/react';
import { LuBan, LuPlus, LuTrash2 } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';

/**
 * Стоп-лист адресов.
 *
 * Отвечает на «клиент говорит, что не получает письма»: адрес может быть
 * отписан, отвергнут почтовым сервером или внесён руками. Без этого экрана
 * причина невидима, и её ищут в коде.
 */
export default function Suppressions({ rows, filters, canRemove }) {
    const [search, setSearch] = useState(filters?.email || '');
    const [adding, setAdding] = useState(false);
    const [form, setForm] = useState({ email: '', scope: 'all', note: '' });

    const apply = () => router.get('/crm/notifications/suppressions', { email: search }, { preserveState: true });

    const submit = () => {
        router.post('/crm/notifications/suppressions', form, {
            preserveScroll: true,
            onSuccess: () => {
                setForm({ email: '', scope: 'all', note: '' });
                setAdding(false);
            },
        });
    };

    const remove = (row) =>
        router.delete(`/crm/notifications/suppressions/${row.id}`, { preserveScroll: true });

    const reasonColor = (reason) =>
        ({ bounce: 'red', complaint: 'red', unsubscribed: 'orange', manual: 'gray' })[reason] || 'gray';

    return (
        <CrmLayout>
            <Head title="Стоп-лист уведомлений" />

            <VStack align="stretch" gap={5}>
                <Flex justify="space-between" align="center" wrap="wrap" gap={3}>
                    <HStack gap={3}>
                        <LuBan size={22} />
                        <Heading size="lg">Стоп-лист уведомлений</Heading>
                    </HStack>
                    {canRemove && (
                        <Button size="sm" variant="outline" onClick={() => setAdding(!adding)}>
                            <LuPlus /> Добавить адрес
                        </Button>
                    )}
                </Flex>

                <Text fontSize="sm" color="fg.muted" maxW="4xl">
                    На эти адреса письма не уходят. Адрес попадает сюда, когда человек отписался
                    по ссылке, когда почтовый сервер отверг его как несуществующий, или когда его
                    внёс сотрудник. Отписка от рассылок не отключает уведомления о заказах.
                </Text>

                {adding && canRemove && (
                    <Card.Root>
                        <Card.Body>
                            <Flex gap={3} wrap="wrap" align="end">
                                <Field label="Адрес" required flex="1 1 240px">
                                    <Input
                                        size="sm"
                                        type="email"
                                        value={form.email}
                                        onChange={(e) => setForm({ ...form, email: e.target.value })}
                                    />
                                </Field>
                                <Field label="Что запретить" flex="0 0 200px">
                                    <NativeSelectRoot size="sm">
                                        <NativeSelectField
                                            value={form.scope}
                                            onChange={(e) => setForm({ ...form, scope: e.target.value })}
                                        >
                                            <option value="all">Все уведомления</option>
                                            <option value="marketing">Только рассылки</option>
                                        </NativeSelectField>
                                    </NativeSelectRoot>
                                </Field>
                                <Field label="Пояснение" flex="1 1 240px">
                                    <Input
                                        size="sm"
                                        value={form.note}
                                        onChange={(e) => setForm({ ...form, note: e.target.value })}
                                        placeholder="Клиент просил не писать"
                                    />
                                </Field>
                                <Button size="sm" onClick={submit}>Добавить</Button>
                            </Flex>
                        </Card.Body>
                    </Card.Root>
                )}

                <Card.Root>
                    <Card.Body>
                        <HStack mb={4}>
                            <Input
                                size="sm"
                                maxW="320px"
                                placeholder="Поиск по адресу"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                            <Button size="sm" variant="outline" onClick={apply}>Найти</Button>
                        </HStack>

                        {rows.data.length === 0 ? (
                            <Text color="fg.muted">Стоп-лист пуст — писать можно на любые адреса.</Text>
                        ) : (
                            <Table.Root size="sm" variant="outline">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>Адрес</Table.ColumnHeader>
                                        <Table.ColumnHeader>Контакт</Table.ColumnHeader>
                                        <Table.ColumnHeader>Что запрещено</Table.ColumnHeader>
                                        <Table.ColumnHeader>Причина</Table.ColumnHeader>
                                        <Table.ColumnHeader>Когда</Table.ColumnHeader>
                                        {canRemove && <Table.ColumnHeader />}
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {rows.data.map((row) => (
                                        <Table.Row key={row.id}>
                                            <Table.Cell fontSize="sm">{row.email}</Table.Cell>
                                            <Table.Cell fontSize="sm" color="fg.muted">{row.contact_name || '—'}</Table.Cell>
                                            <Table.Cell fontSize="sm">{row.scope_label}</Table.Cell>
                                            <Table.Cell>
                                                <Badge colorPalette={reasonColor(row.reason)} variant="subtle">
                                                    {row.reason_label}
                                                </Badge>
                                                {row.note && (
                                                    <Text fontSize="xs" color="fg.muted" mt={1}>{row.note}</Text>
                                                )}
                                            </Table.Cell>
                                            <Table.Cell fontSize="xs">{row.created_at}</Table.Cell>
                                            {canRemove && (
                                                <Table.Cell>
                                                    <Button
                                                        size="xs"
                                                        variant="ghost"
                                                        colorPalette="red"
                                                        onClick={() => remove(row)}
                                                        title="Снять запрет — письма на этот адрес пойдут снова"
                                                    >
                                                        <LuTrash2 />
                                                    </Button>
                                                </Table.Cell>
                                            )}
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        )}

                        {rows.links && rows.last_page > 1 && (
                            <HStack mt={4} gap={1} wrap="wrap">
                                {rows.links.map((link, index) => (
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
