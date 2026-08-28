import { useEffect, useState } from 'react';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { Badge, Box, HStack, Input, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import CabinetLayout from '@/Pages/User/Cabinet/CabinetLayout';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Alert } from '@/components/ui/alert';
import { toastError, toastSuccess } from '@/utils/toast';
import { LuDownload, LuPencil, LuTrash2, LuUserPlus } from 'react-icons/lu';

const controlStyle = {
    padding: '0.5rem',
    borderRadius: '0.375rem',
    border: '1px solid var(--chakra-colors-border)',
    width: '100%',
};

const emptyForm = {
    full_name: '',
    greeting_name: '',
    position: '',
    email: '',
    phone: '',
    phone_extra: '',
    telegram: '',
    whatsapp: '',
    instagram: '',
    birthday: '',
    birthday_has_year: true,
    preferred_channel: '',
    is_active: true,
    company_id: '',
    role: 'manager',
};

/**
 * Контакты компании в кабинете партнёра.
 *
 * Партнёр знает о смене бухгалтера раньше нашего менеджера. Свои карточки он
 * правит и удаляет, заведённые менеджером — правит, но вместо удаления помечает
 * «больше не работает»: за такой карточкой могут стоять письма.
 */
export default function Index({ roles = [], channels = [], limit = 50 }) {
    const [contacts, setContacts] = useState([]);
    const [companies, setCompanies] = useState([]);
    const [form, setForm] = useState(emptyForm);
    const [editingId, setEditingId] = useState(null);
    const [open, setOpen] = useState(false);
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);

    const load = () => {
        axios.get(route('cabinet.contacts.list'))
            .then((res) => {
                setContacts(res.data.data || []);
                setCompanies(res.data.companies || []);
            })
            .catch(() => {});
    };

    useEffect(load, []);

    const patch = (changes) => setForm((prev) => ({ ...prev, ...changes }));
    const errorOf = (key) => (errors[key] ? errors[key][0] : null);

    const startCreate = () => {
        setForm(emptyForm);
        setEditingId(null);
        setErrors({});
        setOpen(true);
    };

    const startEdit = (contact) => {
        setForm({
            ...emptyForm,
            ...contact,
            birthday: contact.birthday || '',
            preferred_channel: contact.preferred_channel || '',
            company_id: contact.company_id || '',
            role: contact.role || 'manager',
        });
        setEditingId(contact.id);
        setErrors({});
        setOpen(true);
    };

    const save = async () => {
        setBusy(true);
        setErrors({});

        const payload = {
            ...form,
            birthday: form.birthday || null,
            preferred_channel: form.preferred_channel || null,
            company_id: form.company_id || null,
        };

        try {
            if (editingId) {
                await axios.patch(route('cabinet.contacts.update', editingId), payload);
                toastSuccess('Контакт обновлён');
            } else {
                await axios.post(route('cabinet.contacts.store'), payload);
                toastSuccess('Контакт добавлен');
            }

            setOpen(false);
            load();
        } catch (e) {
            setErrors(e?.response?.data?.errors || {});

            if (!e?.response?.data?.errors) {
                toastError('Не удалось сохранить', e?.response?.data?.message || 'Попробуйте ещё раз.');
            }
        } finally {
            setBusy(false);
        }
    };

    const remove = async (contact) => {
        setBusy(true);
        try {
            await axios.delete(route('cabinet.contacts.destroy', contact.id));
            toastSuccess('Контакт удалён');
            load();
        } catch (e) {
            toastError('Не получилось', e?.response?.data?.message || 'Попробуйте ещё раз.');
        } finally {
            setBusy(false);
        }
    };

    const deactivate = async (contact) => {
        setBusy(true);
        try {
            await axios.post(route('cabinet.contacts.deactivate', contact.id));
            toastSuccess('Отмечено: больше не работает');
            load();
        } catch (e) {
            toastError('Не получилось', e?.response?.data?.message || 'Попробуйте ещё раз.');
        } finally {
            setBusy(false);
        }
    };

    const field = (key, label, props = {}) => (
        <Box>
            <Text fontSize="sm" fontWeight="600" mb={1}>{label}</Text>
            <Input value={form[key] || ''} onChange={(e) => patch({ [key]: e.target.value })} size="sm" {...props} />
            {errorOf(key) && <Text fontSize="xs" color="red.500" mt={1}>{errorOf(key)}</Text>}
        </Box>
    );

    return (
        <CabinetLayout
            title="Контакты"
            actions={(
                <HStack gap={2}>
                    <a href={route('cabinet.contacts.vcf')}>
                        <Button size="sm" variant="outline"><LuDownload /> В телефон</Button>
                    </a>
                    <Button size="sm" onClick={startCreate}><LuUserPlus /> Добавить</Button>
                </HStack>
            )}
        >
            <Head title="Контакты" />

            <VStack align="stretch" gap={5}>
                <Text fontSize="sm" color="fg.muted">
                    Люди вашей компании: бухгалтер, закупщик, директор. По ним мы поймём,
                    кому адресовать документы и письма.
                </Text>

                {open && (
                    <Box borderWidth="1px" borderRadius="lg" p={4}>
                        <VStack align="stretch" gap={3}>
                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={3}>
                                {field('full_name', 'ФИО', { placeholder: 'Афонина Мария Петровна' })}
                                {field('greeting_name', 'Как обращаться', { placeholder: 'Мария Петровна' })}
                                {field('position', 'Должность', { placeholder: 'Главный бухгалтер' })}
                                <Box>
                                    <Text fontSize="sm" fontWeight="600" mb={1}>Кем приходится</Text>
                                    <select value={form.role} onChange={(e) => patch({ role: e.target.value })} style={controlStyle}>
                                        {roles.map((item) => (
                                            <option key={item.value} value={item.value}>{item.label}</option>
                                        ))}
                                    </select>
                                </Box>
                                {field('phone', 'Телефон', { placeholder: '+7 912 345-67-89' })}
                                {field('email', 'Почта')}
                                {field('telegram', 'Telegram', { placeholder: '@username' })}
                                {field('whatsapp', 'WhatsApp')}
                                {field('instagram', 'Instagram')}
                                <Box>
                                    <Text fontSize="sm" fontWeight="600" mb={1}>Предпочитает</Text>
                                    <select
                                        value={form.preferred_channel}
                                        onChange={(e) => patch({ preferred_channel: e.target.value })}
                                        style={controlStyle}
                                    >
                                        <option value="">Не указано</option>
                                        {channels.map((item) => (
                                            <option key={item.value} value={item.value}>{item.label}</option>
                                        ))}
                                    </select>
                                </Box>
                                <Box>
                                    <Text fontSize="sm" fontWeight="600" mb={1}>День рождения</Text>
                                    <Input type="date" value={form.birthday || ''} onChange={(e) => patch({ birthday: e.target.value })} size="sm" />
                                    <Checkbox
                                        mt={2}
                                        checked={!form.birthday_has_year}
                                        onCheckedChange={(e) => patch({ birthday_has_year: !e.checked })}
                                    >
                                        Год неизвестен
                                    </Checkbox>
                                </Box>
                                {companies.length > 0 && (
                                    <Box>
                                        <Text fontSize="sm" fontWeight="600" mb={1}>В какой компании</Text>
                                        <select
                                            value={form.company_id || ''}
                                            onChange={(e) => patch({ company_id: e.target.value })}
                                            style={controlStyle}
                                        >
                                            <option value="">Не указано</option>
                                            {companies.map((item) => (
                                                <option key={item.id} value={item.id}>{item.name}</option>
                                            ))}
                                        </select>
                                    </Box>
                                )}
                            </SimpleGrid>

                            <HStack gap={2}>
                                <Button size="sm" onClick={save} loading={busy}>Сохранить</Button>
                                <Button size="sm" variant="ghost" onClick={() => setOpen(false)}>Отмена</Button>
                            </HStack>
                        </VStack>
                    </Box>
                )}

                {contacts.length === 0 && !open && (
                    <Alert status="info" title="Пока никого нет">
                        Добавьте людей, с которыми мы работаем: бухгалтера, закупщика, директора.
                        Тогда документы и письма будут приходить сразу тому, кому нужно.
                    </Alert>
                )}

                <VStack align="stretch" gap={2}>
                    {contacts.map((contact) => (
                        <HStack
                            key={contact.id}
                            borderWidth="1px"
                            borderRadius="lg"
                            p={3}
                            justifyContent="space-between"
                            flexWrap="wrap"
                            gap={3}
                            opacity={contact.is_active ? 1 : 0.6}
                        >
                            <VStack align="start" gap={0} flex="1" minW="220px">
                                <HStack gap={2} flexWrap="wrap">
                                    <Text fontWeight="600">{contact.full_name}</Text>
                                    {contact.role_label && <Badge variant="subtle">{contact.role_label}</Badge>}
                                    <Badge variant="outline" colorPalette={contact.is_mine ? 'green' : 'gray'}>
                                        {contact.source_label}
                                    </Badge>
                                    {!contact.is_active && <Badge colorPalette="gray">больше не работает</Badge>}
                                </HStack>
                                {contact.position && <Text fontSize="sm" color="fg.muted">{contact.position}</Text>}
                                <Text fontSize="sm" color="fg.muted">
                                    {[contact.phone, contact.email].filter(Boolean).join(' · ') || 'Контакты не указаны'}
                                </Text>
                            </VStack>

                            <HStack gap={1}>
                                <Button size="xs" variant="outline" onClick={() => startEdit(contact)}>
                                    <LuPencil /> Изменить
                                </Button>
                                {contact.is_mine ? (
                                    <Button size="xs" variant="ghost" colorPalette="red" disabled={busy} onClick={() => remove(contact)}>
                                        <LuTrash2 />
                                    </Button>
                                ) : contact.is_active && (
                                    <Button size="xs" variant="ghost" disabled={busy} onClick={() => deactivate(contact)}>
                                        Больше не работает
                                    </Button>
                                )}
                            </HStack>
                        </HStack>
                    ))}
                </VStack>

                <Text fontSize="xs" color="fg.muted">
                    Можно завести до {limit} контактов. Карточку, заведённую вашим менеджером,
                    вы можете дополнить, но не удалить — за ней могут стоять отправленные письма.
                </Text>
            </VStack>
        </CabinetLayout>
    );
}
