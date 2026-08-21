import { useCallback, useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { Link, router } from '@inertiajs/react';
import { Badge, Box, Flex, HStack, Input, Table, Text, VStack } from '@chakra-ui/react';
import { LuPlus, LuTrash2, LuUserPlus, LuX } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field } from '@/components/ui/field';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';
import { toaster } from '@/components/ui/toaster';

/**
 * ContactsPanel — адресная книга контрагента.
 *
 * Дроп-ин: <ContactsPanel userId={...} companyId={...} canEdit /> на карточке
 * партнёра или контрагента. Компонент сам ходит на /crm/contacts.
 *
 * Эти карточки — адресаты правил пульта уведомлений. Правило ссылается на
 * контакт, а не на строку адреса: сменился бухгалтер — правится одна запись,
 * и все правила разом начинают писать новому.
 *
 * @param {{ userId: number, companyId?: number|null, canEdit?: boolean, canDelete?: boolean, canImport?: boolean }} props
 */
export default function ContactsPanel({ userId, companyId = null, canEdit = false, canDelete = false, canImport = false }) {
    const [contacts, setContacts] = useState([]);
    const [roles, setRoles] = useState([]);
    const [loading, setLoading] = useState(false);
    const [adding, setAdding] = useState(false);
    const [form, setForm] = useState(emptyForm());

    function emptyForm() {
        return { full_name: '', role: 'accountant', position: '', email: '', phone: '', marketing_consent: false };
    }

    const reload = useCallback(async () => {
        setLoading(true);
        try {
            const { data } = await axios.get('/crm/contacts', { params: { user_id: userId, company_id: companyId } });
            setContacts(data.data || []);
            setRoles(data.roles || []);
        } catch (e) {
            console.error('Не удалось загрузить контакты:', e);
            setContacts([]);
        } finally {
            setLoading(false);
        }
    }, [userId, companyId]);

    useEffect(() => {
        reload();
    }, [reload]);

    const drafts = useMemo(() => contacts.filter((c) => c.is_draft), [contacts]);

    const submit = () => {
        if (!form.full_name.trim()) {
            toaster.error({ title: 'Укажите ФИО контакта' });
            return;
        }

        router.post(
            '/crm/contacts',
            { user_id: userId, company_id: companyId, ...form, is_active: true },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setForm(emptyForm());
                    setAdding(false);
                    reload();
                },
                onError: (errors) => {
                    toaster.error({ title: Object.values(errors)[0] || 'Не удалось сохранить контакт' });
                },
            },
        );
    };

    const patch = (contact, changes) => {
        router.patch(
            `/crm/contacts/${contact.id}`,
            {
                user_id: contact.user_id,
                company_id: contact.company_id,
                full_name: contact.full_name,
                role: contact.role,
                position: contact.position,
                email: contact.email,
                phone: contact.phone,
                is_primary: contact.is_primary,
                is_active: contact.is_active,
                marketing_consent: contact.marketing_consent,
                notes: contact.notes,
                ...changes,
            },
            { preserveScroll: true, onSuccess: reload },
        );
    };

    const remove = (contact) => {
        router.delete(`/crm/contacts/${contact.id}`, { preserveScroll: true, onSuccess: reload });
    };

    const importFromProfile = () => {
        router.post('/crm/contacts/import-from-profile', { user_id: userId }, { preserveScroll: true, onSuccess: reload });
    };

    return (
        <VStack align="stretch" gap={4}>
            <Box borderWidth="1px" borderRadius="md" p={3} bg="bg.subtle">
                <Text fontSize="sm">
                    Это адресная книга: люди со стороны клиента, которым система может писать сама.
                    Правило вида «недобор — закупщику» найдёт нужного человека именно здесь, поэтому
                    при смене сотрудника достаточно поправить карточку, а правила менять не нужно.
                </Text>
                <Text fontSize="sm" color="fg.muted" mt={2}>
                    Сами правила — что и при каком событии уходит — живут в разделе{' '}
                    <Link href="/crm/notifications/rules" style={{ textDecoration: 'underline' }}>
                        «Уведомления (шлёт система)»
                    </Link>. Пока там нет правила, письма по этим контактам не пойдут.
                </Text>
            </Box>

            {drafts.length > 0 && (
                <Box borderWidth="1px" borderColor="orange.300" borderRadius="md" p={3} bg="orange.50" _dark={{ bg: 'orange.950' }}>
                    <Text fontSize="sm">
                        Распознано из профиля: {drafts.length}. Черновики не получают писем — проверьте данные
                        и включите нужные галочкой «Активен».
                    </Text>
                </Box>
            )}

            {loading ? (
                <Text fontSize="sm" color="fg.muted">Загрузка…</Text>
            ) : contacts.length === 0 ? (
                <Text fontSize="sm" color="fg.muted">
                    Контактов пока нет. Пока их нет, правила, адресованные роли, писать будет некому.
                </Text>
            ) : (
                <Table.Root size="sm" variant="outline">
                    <Table.Header>
                        <Table.Row>
                            <Table.ColumnHeader>ФИО</Table.ColumnHeader>
                            <Table.ColumnHeader>Роль</Table.ColumnHeader>
                            <Table.ColumnHeader>Почта</Table.ColumnHeader>
                            <Table.ColumnHeader>Телефон</Table.ColumnHeader>
                            <Table.ColumnHeader>Активен</Table.ColumnHeader>
                            <Table.ColumnHeader>Рассылки</Table.ColumnHeader>
                            {canDelete && <Table.ColumnHeader />}
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {contacts.map((contact) => (
                            <Table.Row key={contact.id} opacity={contact.is_active ? 1 : 0.6}>
                                <Table.Cell>
                                    <HStack gap={2}>
                                        <Text>{contact.full_name}</Text>
                                        {contact.is_primary && (
                                            <Badge colorPalette="blue" variant="subtle" size="sm">основной</Badge>
                                        )}
                                        {contact.is_draft && (
                                            <Badge colorPalette="orange" variant="subtle" size="sm">черновик</Badge>
                                        )}
                                        {contact.company_id === null && companyId !== null && (
                                            <Badge colorPalette="gray" variant="subtle" size="sm">общий</Badge>
                                        )}
                                    </HStack>
                                    {contact.position && (
                                        <Text fontSize="xs" color="fg.muted">{contact.position}</Text>
                                    )}
                                </Table.Cell>
                                <Table.Cell>
                                    <Badge colorPalette={contact.role_color} variant="subtle">{contact.role_label}</Badge>
                                </Table.Cell>
                                <Table.Cell>
                                    <Text fontSize="sm">{contact.email || '—'}</Text>
                                    {contact.unsubscribed_at && (
                                        <Text fontSize="xs" color="red.500">отписался</Text>
                                    )}
                                </Table.Cell>
                                <Table.Cell fontSize="sm">{contact.phone || '—'}</Table.Cell>
                                <Table.Cell>
                                    <Checkbox
                                        checked={contact.is_active}
                                        disabled={!canEdit}
                                        onCheckedChange={(e) => patch(contact, { is_active: !!e.checked })}
                                    />
                                </Table.Cell>
                                <Table.Cell>
                                    <Checkbox
                                        checked={contact.marketing_consent}
                                        disabled={!canEdit}
                                        onCheckedChange={(e) => patch(contact, { marketing_consent: !!e.checked })}
                                    />
                                </Table.Cell>
                                {canDelete && (
                                    <Table.Cell>
                                        <Button size="xs" variant="ghost" colorPalette="red" onClick={() => remove(contact)}>
                                            <LuTrash2 />
                                        </Button>
                                    </Table.Cell>
                                )}
                            </Table.Row>
                        ))}
                    </Table.Body>
                </Table.Root>
            )}

            {canEdit && !adding && (
                <HStack>
                    <Button size="sm" variant="outline" onClick={() => setAdding(true)}>
                        <LuPlus /> Добавить контакт
                    </Button>
                    {canImport && (
                        <Button size="sm" variant="ghost" onClick={importFromProfile}>
                            <LuUserPlus /> Распознать из профиля
                        </Button>
                    )}
                </HStack>
            )}

            {canEdit && adding && (
                <Box borderWidth="1px" borderRadius="md" p={4}>
                    <VStack align="stretch" gap={3}>
                        <Flex gap={3} wrap="wrap">
                            <Field label="ФИО" required flex="1 1 200px">
                                <Input
                                    size="sm"
                                    value={form.full_name}
                                    onChange={(e) => setForm({ ...form, full_name: e.target.value })}
                                    placeholder="Жопкин Анатолий Петрович"
                                />
                            </Field>
                            <Field label="Роль" flex="0 0 180px">
                                <NativeSelectRoot size="sm">
                                    <NativeSelectField
                                        value={form.role}
                                        onChange={(e) => setForm({ ...form, role: e.target.value })}
                                    >
                                        {roles.map((role) => (
                                            <option key={role.value} value={role.value}>{role.label}</option>
                                        ))}
                                    </NativeSelectField>
                                </NativeSelectRoot>
                            </Field>
                        </Flex>
                        <Flex gap={3} wrap="wrap">
                            <Field label="Электронная почта" flex="1 1 200px" helperText="Без адреса контакт не получит уведомлений">
                                <Input
                                    size="sm"
                                    type="email"
                                    value={form.email}
                                    onChange={(e) => setForm({ ...form, email: e.target.value })}
                                    placeholder="buh@romashka.ru"
                                />
                            </Field>
                            <Field label="Телефон" flex="0 0 180px">
                                <Input
                                    size="sm"
                                    value={form.phone}
                                    onChange={(e) => setForm({ ...form, phone: e.target.value })}
                                />
                            </Field>
                            <Field label="Должность" flex="1 1 180px">
                                <Input
                                    size="sm"
                                    value={form.position}
                                    onChange={(e) => setForm({ ...form, position: e.target.value })}
                                    placeholder="Главный бухгалтер"
                                />
                            </Field>
                        </Flex>
                        <Checkbox
                            checked={form.marketing_consent}
                            onCheckedChange={(e) => setForm({ ...form, marketing_consent: !!e.checked })}
                        >
                            <Text fontSize="sm">Согласен получать рассылки и акции</Text>
                        </Checkbox>
                        <HStack>
                            <Button size="sm" onClick={submit}>Сохранить</Button>
                            <Button size="sm" variant="ghost" onClick={() => { setAdding(false); setForm(emptyForm()); }}>
                                <LuX /> Отмена
                            </Button>
                        </HStack>
                    </VStack>
                </Box>
            )}
        </VStack>
    );
}
