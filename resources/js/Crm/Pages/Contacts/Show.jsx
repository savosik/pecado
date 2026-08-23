import { useRef, useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { Badge, Box, Card, HStack, Image, SimpleGrid, Tabs, Text, VStack, Wrap } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/Admin/Components/ConfirmDialog';
import ContactForm from '@/Crm/Components/ContactForm';
import ContactLinkPicker from '@/Crm/Components/ContactLinkPicker';
import CommentThread from '@/Crm/Components/CommentThread';
import TaskPanel from '@/Crm/Components/TaskPanel';
import AttachmentPanel from '@/Crm/Components/AttachmentPanel';
import { usePermission } from '@/shared/Panel/usePermission';
import { LuArrowLeft, LuCake, LuDownload, LuPencil, LuTrash2, LuUpload } from 'react-icons/lu';
import { toastError, toastSuccess } from '@/utils/toast';

function InfoRow({ label, value }) {
    if (!value) {
        return null;
    }

    return (
        <HStack gap={2} align="start">
            <Text fontSize="xs" color="fg.muted" minW="130px">{label}</Text>
            <Text fontSize="sm">{value}</Text>
        </HStack>
    );
}

/**
 * Карточка человека: кто он, как связаться, кем приходится и что с ним связано.
 */
export default function Show({ contact, letters = [], calls = [], roles = [], channels = [], linkableTypes = [], can = {} }) {
    const { can: hasPermission } = usePermission();
    const [editing, setEditing] = useState(false);
    const [pendingDelete, setPendingDelete] = useState(false);
    const [busy, setBusy] = useState(false);
    const fileInput = useRef(null);

    const reload = () => router.reload();

    const uploadAvatar = async (file) => {
        if (!file) {
            return;
        }

        setBusy(true);
        const data = new FormData();
        data.append('avatar', file);

        try {
            await axios.post(route('crm.contacts.avatar', contact.id), data);
            toastSuccess('Фото обновлено');
            reload();
        } catch (e) {
            toastError('Не удалось загрузить', e?.response?.data?.message || 'Попробуйте другой файл.');
        } finally {
            setBusy(false);
        }
    };

    const link = async (payload) => {
        setBusy(true);
        try {
            await axios.post(route('crm.contacts.link', contact.id), payload);
            toastSuccess('Привязано');
            reload();
        } catch (e) {
            toastError('Не получилось', e?.response?.data?.message || 'Попробуйте ещё раз.');
        } finally {
            setBusy(false);
        }
    };

    const unlink = async (linkId) => {
        setBusy(true);
        try {
            await axios.delete(route('crm.contacts.unlink', [contact.id, linkId]));
            toastSuccess('Привязка снята');
            reload();
        } catch (e) {
            toastError('Не получилось', e?.response?.data?.message || 'Попробуйте ещё раз.');
        } finally {
            setBusy(false);
        }
    };

    const remove = async () => {
        setBusy(true);
        try {
            await axios.delete(route('crm.contacts.destroy', contact.id));
            toastSuccess('Контакт удалён');
            router.visit(route('crm.contacts.index'));
        } catch (e) {
            toastError('Не получилось', e?.response?.data?.message || 'Попробуйте ещё раз.');
            setBusy(false);
        }
    };

    const canComment = hasPermission('crm-comments.view');
    const canTasks = hasPermission('crm-tasks.view');
    const canFiles = hasPermission('crm-attachments.view');
    const defaultTab = canComment ? 'comments' : (canTasks ? 'tasks' : (canFiles ? 'files' : 'letters'));

    return (
        <>
            <Head title={`CRM — ${contact.full_name}`} />
            <PageHeader
                title={contact.full_name}
                description={contact.position || 'Контактное лицо'}
                actions={(
                    <HStack gap={2}>
                        <Link href={route('crm.contacts.index')}>
                            <Button size="sm" variant="outline"><LuArrowLeft /> К списку</Button>
                        </Link>
                        <a href={route('crm.contacts.vcard', contact.id)}>
                            <Button size="sm" variant="outline"><LuDownload /> В телефон</Button>
                        </a>
                        {can.edit && (
                            <Button size="sm" variant="outline" onClick={() => setEditing((v) => !v)}>
                                <LuPencil /> {editing ? 'Закрыть' : 'Изменить'}
                            </Button>
                        )}
                        {can.delete && (
                            <Button size="sm" variant="ghost" colorPalette="red" onClick={() => setPendingDelete(true)}>
                                <LuTrash2 />
                            </Button>
                        )}
                    </HStack>
                )}
            />

            <VStack align="stretch" gap={4}>
                {editing && (
                    <Card.Root>
                        <Card.Body>
                            <ContactForm
                                contact={contact}
                                channels={channels}
                                roles={roles}
                                onSaved={() => { setEditing(false); reload(); }}
                                onCancel={() => setEditing(false)}
                            />
                        </Card.Body>
                    </Card.Root>
                )}

                <Card.Root>
                    <Card.Body>
                        <HStack gap={5} align="start" flexWrap="wrap">
                            <VStack gap={2}>
                                {contact.avatar_url
                                    ? <Image src={contact.avatar_url} alt="" boxSize="96px" borderRadius="lg" objectFit="cover" />
                                    : (
                                        <Box boxSize="96px" borderRadius="lg" bg="bg.emphasized" display="flex" alignItems="center" justifyContent="center">
                                            <Text fontSize="2xl" color="fg.muted">{(contact.full_name || '?').slice(0, 1)}</Text>
                                        </Box>
                                    )}
                                {can.edit && (
                                    <>
                                        <input
                                            ref={fileInput}
                                            type="file"
                                            accept="image/jpeg,image/png,image/webp"
                                            style={{ display: 'none' }}
                                            onChange={(e) => uploadAvatar(e.target.files?.[0])}
                                        />
                                        <Button size="xs" variant="ghost" disabled={busy} onClick={() => fileInput.current?.click()}>
                                            <LuUpload /> Фото
                                        </Button>
                                    </>
                                )}
                            </VStack>

                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={2} flex="1" minW="280px">
                                <InfoRow label="Как обращаться" value={contact.greeting_name} />
                                <InfoRow label="Должность" value={contact.position} />
                                <InfoRow label="Телефон" value={contact.phone} />
                                <InfoRow label="Ещё телефон" value={contact.phone_extra} />
                                <InfoRow label="Почта" value={contact.email} />
                                <InfoRow label="Telegram" value={contact.telegram} />
                                <InfoRow label="WhatsApp" value={contact.whatsapp} />
                                <InfoRow label="Instagram" value={contact.instagram} />
                                <InfoRow label="Сайт" value={contact.website} />
                                <InfoRow label="Предпочитает" value={contact.preferred_channel_label} />
                                <InfoRow label="Партнёр" value={contact.client?.name} />
                                <InfoRow label="Завёл" value={contact.created_by} />
                            </SimpleGrid>
                        </HStack>

                        <Wrap gap={2} mt={4}>
                            <Badge colorPalette={contact.source_color} variant="subtle">{contact.source_label}</Badge>
                            {contact.partner_touched_at_label && (
                                <Badge colorPalette="green" variant="subtle">
                                    Правил партнёр {contact.partner_touched_at_label}
                                </Badge>
                            )}
                            {!contact.is_active && <Badge colorPalette="gray">не работает</Badge>}
                            {contact.unsubscribed_at_label && (
                                <Badge colorPalette="red" variant="subtle">
                                    отписался {contact.unsubscribed_at_label}
                                </Badge>
                            )}
                            {contact.birthday_label && (
                                <Badge colorPalette="pink" variant="subtle">
                                    <LuCake size={11} /> {contact.birthday_label}
                                </Badge>
                            )}
                        </Wrap>

                        {contact.notes && (
                            <Box mt={4} p={3} bg="bg.subtle" borderRadius="md">
                                <Text fontSize="xs" color="fg.muted" mb={1}>Заметка (партнёру не видна)</Text>
                                <Text fontSize="sm">{contact.notes}</Text>
                            </Box>
                        )}
                    </Card.Body>
                </Card.Root>

                <Card.Root>
                    <Card.Body>
                        <Text fontSize="sm" fontWeight="600" mb={3}>Кем приходится</Text>

                        {contact.links?.length ? (
                            <VStack align="stretch" gap={2}>
                                {contact.links.map((link) => (
                                    <HStack key={link.id} justifyContent="space-between" borderWidth="1px" borderRadius="md" p={2}>
                                        <HStack gap={3}>
                                            <Badge colorPalette={link.role_color} variant="subtle">{link.role_label}</Badge>
                                            {link.subject?.url
                                                ? <a href={link.subject.url}><Text fontSize="sm">{link.subject.title}</Text></a>
                                                : <Text fontSize="sm">{link.subject?.title || '—'}</Text>}
                                            {link.is_primary && <Badge size="sm" colorPalette="blue">основной</Badge>}
                                        </HStack>
                                        {can.edit && (
                                            <Button size="xs" variant="ghost" colorPalette="red" disabled={busy} onClick={() => unlink(link.id)}>
                                                <LuTrash2 />
                                            </Button>
                                        )}
                                    </HStack>
                                ))}
                            </VStack>
                        ) : (
                            <Text fontSize="sm" color="fg.muted" mb={3}>
                                Пока ни к кому не привязан. Без привязки человек виден только вам —
                                укажите, кому он приходится.
                            </Text>
                        )}

                        {can.edit && (
                            <Box mt={4} pt={3} borderTopWidth={contact.links?.length ? '1px' : '0'}>
                                <ContactLinkPicker
                                    types={linkableTypes}
                                    roles={roles}
                                    busy={busy}
                                    onSubmit={link}
                                />
                            </Box>
                        )}
                    </Card.Body>
                </Card.Root>

                {(
                    <Card.Root>
                        <Card.Body>
                            <Tabs.Root defaultValue={defaultTab} lazyMount>
                                <Tabs.List>
                                    {canComment && <Tabs.Trigger value="comments">Комментарии</Tabs.Trigger>}
                                    {canTasks && <Tabs.Trigger value="tasks">Задачи</Tabs.Trigger>}
                                    {canFiles && <Tabs.Trigger value="files">Файлы</Tabs.Trigger>}
                                    <Tabs.Trigger value="letters">Письма</Tabs.Trigger>
                                    <Tabs.Trigger value="calls">Звонки</Tabs.Trigger>
                                </Tabs.List>

                                {canComment && (
                                    <Tabs.Content value="comments">
                                        <CommentThread
                                            entityType="contact"
                                            entityId={contact.id}
                                            canCreate={hasPermission('crm-comments.create')}
                                        />
                                    </Tabs.Content>
                                )}
                                {canTasks && (
                                    <Tabs.Content value="tasks">
                                        <TaskPanel entityType="contact" entityId={contact.id} />
                                    </Tabs.Content>
                                )}
                                {canFiles && (
                                    <Tabs.Content value="files">
                                        <AttachmentPanel
                                            entityType="contact"
                                            entityId={contact.id}
                                            canUpload={hasPermission('crm-attachments.create')}
                                        />
                                    </Tabs.Content>
                                )}
                                <Tabs.Content value="letters">
                                    {letters.length === 0 ? (
                                        <Text fontSize="sm" color="fg.muted">
                                            Этому человеку ещё не писали. Письмо на его адрес подошьётся сюда само.
                                        </Text>
                                    ) : (
                                        <VStack align="stretch" gap={2}>
                                            {letters.map((letter) => (
                                                <HStack key={letter.id} justifyContent="space-between" borderWidth="1px" borderRadius="md" p={2}>
                                                    <VStack align="start" gap={0}>
                                                        <a href={letter.url}><Text fontSize="sm" fontWeight="600">{letter.subject}</Text></a>
                                                        <Text fontSize="xs" color="fg.muted">{letter.date_label}</Text>
                                                    </VStack>
                                                    <Badge colorPalette={letter.status_color} variant="subtle">{letter.status_label}</Badge>
                                                </HStack>
                                            ))}
                                        </VStack>
                                    )}
                                </Tabs.Content>
                                <Tabs.Content value="calls">
                                    {calls.length === 0 ? (
                                        <Text fontSize="sm" color="fg.muted">
                                            Разговоров пока не записано.
                                        </Text>
                                    ) : (
                                        <VStack align="stretch" gap={2}>
                                            {calls.map((call) => (
                                                <Box key={call.id} borderWidth="1px" borderRadius="md" p={2}>
                                                    <Text fontSize="xs" color="fg.muted">{call.date_label}</Text>
                                                    <Text fontSize="sm">{call.summary || 'Без описания'}</Text>
                                                </Box>
                                            ))}
                                        </VStack>
                                    )}
                                </Tabs.Content>
                            </Tabs.Root>
                        </Card.Body>
                    </Card.Root>
                )}
            </VStack>

            <ConfirmDialog
                open={pendingDelete}
                onClose={() => setPendingDelete(false)}
                onConfirm={remove}
                title="Удалить контакт?"
                description="Карточка скроется из справочника. Письма и звонки, связанные с человеком, останутся."
                confirmLabel="Удалить"
                cancelLabel="Отмена"
                isLoading={busy}
            />
        </>
    );
}

Show.layout = (page) => <CrmLayout>{page}</CrmLayout>;
