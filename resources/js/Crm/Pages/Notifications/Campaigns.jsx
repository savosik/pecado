import { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { Badge, Box, Card, Flex, HStack, Heading, Input, Table, Text, Textarea, VStack } from '@chakra-ui/react';
import { LuMegaphone, LuPlus, LuSend, LuUsers, LuX } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field } from '@/components/ui/field';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';

/**
 * Рассылки по сегменту клиентов.
 *
 * Аудитория собирается заранее и показывается с разбивкой «кому уйдёт, кого
 * отсеяли и почему» — до отправки, а не после. Отсев по согласию и стоп-листу
 * здесь не ошибка, а норма: реклама уходит только тем, кто её ждёт.
 */
export default function Campaigns({ campaigns, templates, roles, canSend }) {
    const [creating, setCreating] = useState(false);
    const [audience, setAudience] = useState(null);
    const [form, setForm] = useState(emptyForm());

    function emptyForm() {
        return {
            name: '',
            subject: '',
            body_html: '',
            crm_email_template_id: '',
            segment: { roles: [], include_accounts: false },
        };
    }

    const applyTemplate = (id) => {
        const template = templates.find((t) => String(t.id) === String(id));
        setForm({
            ...form,
            crm_email_template_id: id,
            subject: template?.subject || form.subject,
            body_html: template?.body_html || form.body_html,
        });
    };

    const toggleRole = (role) => {
        const has = form.segment.roles.includes(role);
        setForm({
            ...form,
            segment: {
                ...form.segment,
                roles: has ? form.segment.roles.filter((r) => r !== role) : [...form.segment.roles, role],
            },
        });
    };

    const submit = () => {
        router.post('/crm/notifications/campaigns', form, {
            preserveScroll: true,
            onSuccess: () => { setForm(emptyForm()); setCreating(false); },
        });
    };

    const build = (campaign) =>
        router.post(`/crm/notifications/campaigns/${campaign.id}/audience`, {}, { preserveScroll: true });

    const send = (campaign) =>
        router.post(`/crm/notifications/campaigns/${campaign.id}/send`, {}, { preserveScroll: true });

    const cancel = (campaign) =>
        router.post(`/crm/notifications/campaigns/${campaign.id}/cancel`, {}, { preserveScroll: true });

    const showAudience = async (campaign) => {
        const { data } = await axios.get(`/crm/notifications/campaigns/${campaign.id}/audience`);
        setAudience({ campaign, ...data });
    };

    const statusColor = (status) =>
        ({ draft: 'gray', scheduled: 'blue', sending: 'orange', sent: 'green', cancelled: 'red' })[status] || 'gray';

    return (
        <CrmLayout>
            <Head title="Рассылки" />

            <VStack align="stretch" gap={5}>
                <Flex justify="space-between" align="center" wrap="wrap" gap={3}>
                    <HStack gap={3}>
                        <LuMegaphone size={22} />
                        <Heading size="lg">Рассылки</Heading>
                    </HStack>
                    <Button size="sm" onClick={() => setCreating(!creating)}>
                        <LuPlus /> Новая рассылка
                    </Button>
                </Flex>

                <Text fontSize="sm" color="fg.muted" maxW="4xl">
                    Письмо уходит только тем контактам, кто дал согласие на рассылки, и минует
                    стоп-лист. Отписка от рассылок не отключает уведомления о заказах и документах —
                    это разные вещи.
                </Text>

                {creating && (
                    <Card.Root>
                        <Card.Body>
                            <VStack align="stretch" gap={4}>
                                <Flex gap={3} wrap="wrap">
                                    <Field label="Название" required flex="1 1 260px" helperText="Видно только вам">
                                        <Input size="sm" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
                                    </Field>
                                    <Field label="Шаблон" flex="0 0 220px" helperText="Подставит тему и текст">
                                        <NativeSelectRoot size="sm">
                                            <NativeSelectField
                                                value={form.crm_email_template_id}
                                                onChange={(e) => applyTemplate(e.target.value)}
                                            >
                                                <option value="">— без шаблона —</option>
                                                {templates.map((t) => (
                                                    <option key={t.id} value={t.id}>{t.name}</option>
                                                ))}
                                            </NativeSelectField>
                                        </NativeSelectRoot>
                                    </Field>
                                </Flex>

                                <Field label="Тема письма" required helperText="Можно подставить {{client_name}}">
                                    <Input size="sm" value={form.subject} onChange={(e) => setForm({ ...form, subject: e.target.value })} />
                                </Field>

                                <Field label="Текст письма" required helperText="HTML. Плейсхолдеры: {{client_name}}, {{contact_name}}">
                                    <Textarea rows={8} value={form.body_html} onChange={(e) => setForm({ ...form, body_html: e.target.value })} />
                                </Field>

                                <Box>
                                    <Text fontWeight="600" fontSize="sm" mb={2}>Кому отправить</Text>
                                    <HStack gap={3} wrap="wrap" mb={2}>
                                        {roles.map((role) => (
                                            <Checkbox
                                                key={role.value}
                                                checked={form.segment.roles.includes(role.value)}
                                                onCheckedChange={() => toggleRole(role.value)}
                                            >
                                                <Text fontSize="sm">{role.label}</Text>
                                            </Checkbox>
                                        ))}
                                    </HStack>
                                    <Checkbox
                                        checked={form.segment.include_accounts}
                                        onCheckedChange={(e) => setForm({
                                            ...form,
                                            segment: { ...form.segment, include_accounts: !!e.checked },
                                        })}
                                    >
                                        <Text fontSize="sm">Плюс учётные записи партнёров, согласных на рассылки</Text>
                                    </Checkbox>
                                </Box>

                                <HStack>
                                    <Button size="sm" onClick={submit}>Создать черновик</Button>
                                    <Button size="sm" variant="ghost" onClick={() => { setCreating(false); setForm(emptyForm()); }}>
                                        <LuX /> Отмена
                                    </Button>
                                </HStack>
                            </VStack>
                        </Card.Body>
                    </Card.Root>
                )}

                <Card.Root>
                    <Card.Body>
                        {campaigns.data.length === 0 ? (
                            <Text color="fg.muted">Рассылок пока нет.</Text>
                        ) : (
                            <Table.Root size="sm" variant="outline">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>Название</Table.ColumnHeader>
                                        <Table.ColumnHeader>Тема</Table.ColumnHeader>
                                        <Table.ColumnHeader>Аудитория</Table.ColumnHeader>
                                        <Table.ColumnHeader>Состояние</Table.ColumnHeader>
                                        <Table.ColumnHeader />
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {campaigns.data.map((campaign) => (
                                        <Table.Row key={campaign.id}>
                                            <Table.Cell>
                                                <Text fontSize="sm" fontWeight="600">{campaign.name}</Text>
                                                <Text fontSize="xs" color="fg.muted">
                                                    {campaign.author || '—'}, {campaign.created_at}
                                                </Text>
                                            </Table.Cell>
                                            <Table.Cell fontSize="sm">{campaign.subject}</Table.Cell>
                                            <Table.Cell fontSize="sm">
                                                {campaign.recipients_total > 0 ? (
                                                    <>
                                                        <Text>получат: {campaign.recipients_total - campaign.recipients_skipped}</Text>
                                                        {campaign.recipients_skipped > 0 && (
                                                            <Text fontSize="xs" color="fg.muted">
                                                                отсеяно: {campaign.recipients_skipped}
                                                            </Text>
                                                        )}
                                                    </>
                                                ) : (
                                                    <Text color="fg.muted">не собрана</Text>
                                                )}
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Badge colorPalette={statusColor(campaign.status)} variant="subtle">
                                                    {campaign.status_label}
                                                </Badge>
                                                {campaign.recipients_sent > 0 && (
                                                    <Text fontSize="xs" color="fg.muted" mt={1}>
                                                        отправлено: {campaign.recipients_sent}
                                                    </Text>
                                                )}
                                            </Table.Cell>
                                            <Table.Cell>
                                                <HStack gap={1}>
                                                    {campaign.is_editable && (
                                                        <Button size="xs" variant="outline" onClick={() => build(campaign)}>
                                                            <LuUsers /> Собрать
                                                        </Button>
                                                    )}
                                                    {campaign.recipients_total > 0 && (
                                                        <Button size="xs" variant="ghost" onClick={() => showAudience(campaign)}>
                                                            Кому
                                                        </Button>
                                                    )}
                                                    {canSend && campaign.status !== 'sent' && campaign.status !== 'cancelled' && campaign.recipients_total > 0 && (
                                                        <Button size="xs" onClick={() => send(campaign)}>
                                                            <LuSend /> Отправить
                                                        </Button>
                                                    )}
                                                    {canSend && campaign.is_editable && (
                                                        <Button size="xs" variant="ghost" colorPalette="red" onClick={() => cancel(campaign)}>
                                                            Отменить
                                                        </Button>
                                                    )}
                                                </HStack>
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        )}
                    </Card.Body>
                </Card.Root>

                {audience && (
                    <Card.Root>
                        <Card.Header pb={2}>
                            <Flex justify="space-between" align="center">
                                <Heading size="sm">Аудитория: {audience.campaign.name}</Heading>
                                <Button size="xs" variant="ghost" onClick={() => setAudience(null)}><LuX /></Button>
                            </Flex>
                            <Text fontSize="xs" color="fg.muted">Всего записей: {audience.total}</Text>
                        </Card.Header>
                        <Card.Body pt={0}>
                            <Table.Root size="sm" variant="outline">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>Адрес</Table.ColumnHeader>
                                        <Table.ColumnHeader>Контакт</Table.ColumnHeader>
                                        <Table.ColumnHeader>Состояние</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {audience.data.map((row, index) => (
                                        <Table.Row key={index}>
                                            <Table.Cell fontSize="sm">{row.email}</Table.Cell>
                                            <Table.Cell fontSize="sm" color="fg.muted">{row.contact_name || '—'}</Table.Cell>
                                            <Table.Cell fontSize="sm">
                                                {row.skip_reason_label
                                                    ? <Text color="orange.600">{row.skip_reason_label}</Text>
                                                    : <Text color="green.600">получит</Text>}
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        </Card.Body>
                    </Card.Root>
                )}
            </VStack>
        </CrmLayout>
    );
}
