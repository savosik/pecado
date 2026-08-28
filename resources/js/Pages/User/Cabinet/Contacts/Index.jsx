import { useEffect, useState } from 'react';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { Badge, Box, HStack, Input, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import CabinetLayout from '@/Pages/User/Cabinet/CabinetLayout';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Alert } from '@/components/ui/alert';
import { toastError, toastSuccess } from '@/utils/toast';
import { LuDownload, LuUserPlus, LuUserX } from 'react-icons/lu';
import RowActions from '@/shared/Panel/RowActions';
import { useConfirmDelete } from '@/shared/Panel/useConfirmDelete';
import { ConfirmDialog } from '@/shared/Panel/ConfirmDialog';

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
    links: [],
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
            links: (contact.links || []).map((link) => ({ company_id: link.company_id, role: link.role })),
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

    const del = useConfirmDelete({
        title: 'Удалить контакт?',
        description: (contact) => `${contact?.full_name ?? 'Контакт'} будет удалён из вашей адресной книги.`,
        onConfirm: (contact) => remove(contact),
    });

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
                    <Box border="1px solid" borderColor="border.muted" borderRadius="xl" bg="bg" p={4}>
                        <VStack align="stretch" gap={3}>
                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={3}>
                                {field('full_name', 'ФИО', { placeholder: 'Афонина Мария Петровна' })}
                                {field('greeting_name', 'Как обращаться', { placeholder: 'Мария Петровна' })}
                                {field('position', 'Должность', { placeholder: 'Главный бухгалтер' })}
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
                            </SimpleGrid>

                            {companies.length > 0 && (
                                <Box>
                                    <Text fontSize="sm" fontWeight="600" mb={1}>В каких компаниях и кем</Text>
                                    <Text fontSize="xs" color="fg.muted" mb={2}>
                                        Один человек может быть, например, бухгалтером сразу в нескольких ваших организациях — отметьте каждую.
                                    </Text>
                                    <VStack align="stretch" gap={2}>
                                        {companies.map((item) => {
                                            const link = form.links.find((row) => row.company_id === item.id);
                                            return (
                                                <HStack key={item.id} gap={3} flexWrap="wrap">
                                                    <Checkbox
                                                        checked={!!link}
                                                        onCheckedChange={(e) => patch({
                                                            links: e.checked
                                                                ? [...form.links, { company_id: item.id, role: 'manager' }]
                                                                : form.links.filter((row) => row.company_id !== item.id),
                                                        })}
                                                    >
                                                        {item.name}
                                                    </Checkbox>
                                                    {link && (
                                                        <select
                                                            value={link.role}
                                                            onChange={(e) => patch({
                                                                links: form.links.map((row) => (row.company_id === item.id ? { ...row, role: e.target.value } : row)),
                                                            })}
                                                            style={{ ...controlStyle, width: 'auto', minWidth: '180px' }}
                                                        >
                                                            {roles.map((role) => (
                                                                <option key={role.value} value={role.value}>{role.label}</option>
                                                            ))}
                                                        </select>
                                                    )}
                                                </HStack>
                                            );
                                        })}
                                    </VStack>
                                </Box>
                            )}

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
                            border="1px solid"
                            borderColor="border.muted"
                            borderRadius="xl"
                            bg="bg"
                            p={4}
                            justifyContent="space-between"
                            flexWrap="wrap"
                            gap={3}
                            opacity={contact.is_active ? 1 : 0.6}
                        >
                            <VStack align="start" gap={0} flex="1" minW="220px">
                                <HStack gap={2} flexWrap="wrap">
                                    <Text fontWeight="600" color="fg">{contact.full_name}</Text>
                                    {(contact.links || []).map((link) => (
                                        <Badge key={`${link.company_id}-${link.role}`} variant="subtle">
                                            {link.role_label}{link.company_name ? ` · ${link.company_name}` : ''}
                                        </Badge>
                                    ))}
                                    <Badge variant="outline" colorPalette={contact.is_mine ? 'green' : 'gray'}>
                                        {contact.source_label}
                                    </Badge>
                                    {!contact.is_active && <Badge colorPalette="gray">больше не работает</Badge>}
                                </HStack>
                                {contact.position && <Text fontSize="sm" color="fg.muted">{contact.position}</Text>}
                                {[contact.phone, contact.email].filter(Boolean).length > 0
                                    ? <Text fontSize="sm" color="fg">{[contact.phone, contact.email].filter(Boolean).join(' · ')}</Text>
                                    : <Text fontSize="sm" color="orange.fg">Телефон и почта не указаны — добавьте, чтобы мы могли писать этому человеку</Text>}
                            </VStack>

                            <RowActions
                                edit={{ onClick: () => startEdit(contact), label: 'Изменить' }}
                                extra={!contact.is_mine && contact.is_active ? [
                                    { icon: LuUserX, label: 'Больше не работает', onClick: () => deactivate(contact), disabled: busy },
                                ] : []}
                                delete={contact.is_mine ? { onClick: () => del.request(contact), disabled: busy } : null}
                            />
                        </HStack>
                    ))}
                </VStack>

                <Text fontSize="xs" color="fg.muted">
                    Можно завести до {limit} контактов. Карточку, заведённую вашим менеджером,
                    вы можете дополнить, но не удалить — за ней могут стоять отправленные письма.
                </Text>
            </VStack>

            <ConfirmDialog {...del.dialogProps} />
        </CabinetLayout>
    );
}
