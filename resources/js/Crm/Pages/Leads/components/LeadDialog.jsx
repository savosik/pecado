import { useEffect, useState } from 'react';
import axios from 'axios';
import { router } from '@inertiajs/react';
import {
    Box,
    Dialog,
    HStack,
    Input,
    Portal,
    SimpleGrid,
    Tabs,
    Text,
    Textarea,
    VStack,
} from '@chakra-ui/react';
import { LuTrash2, LuUserCheck } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';
import { ConfirmDialog } from '@/Admin/Components/ConfirmDialog';
import { EntitySelector } from '@/Admin/Components/EntitySelector';
import CommentThread from '@/Crm/Components/CommentThread';
import TaskPanel from '@/Crm/Components/TaskPanel';
import AttachmentPanel from '@/Crm/Components/AttachmentPanel';
import VoiceNotes from '@/Crm/Components/VoiceNotes';
import { usePermission } from '@/shared/Panel/usePermission';
import { toastError, toastSuccess } from '@/utils/toast';

const EMPTY = {
    name: '',
    company_name: '',
    phone: '',
    email: '',
    messenger: '',
    source: '',
    manager_id: '',
    stage_id: '',
    qualified_amount: '',
    expected_close_at: '',
    decision_maker: '',
    interests: '',
    notes: '',
    lost_reason: '',
};

/**
 * Карточка лида.
 *
 * Заполняется не хуже партнёра — те же смысловые поля, что в профиле клиента.
 * Но обязательное только одно: имя. Контакт нужен любой, и требовать
 * конкретный означало бы не дать завести лида, у которого есть только телефон.
 *
 * Переписка, задачи и файлы живут во вкладках и появляются только у сохранённого
 * лида: до первого сохранения привязывать их не к чему. Приём тот же, что
 * в `TaskDialog`, и панели те же самые — тип `lead` уже есть в `CrmEntityMap`.
 */
export default function LeadDialog({
    open,
    lead,
    stages = [],
    managers = [],
    currentManagerId = null,
    canEdit = true,
    canDelete = false,
    onClose,
    onSaved,
    onDeleted,
}) {
    const { can } = usePermission();
    const [form, setForm] = useState(EMPTY);
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);
    const [tab, setTab] = useState('main');
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [partner, setPartner] = useState(null);

    useEffect(() => {
        if (! open) return;

        setErrors({});
        setTab('main');
        setPartner(null);
        setForm(lead
            ? {
                ...EMPTY,
                ...Object.fromEntries(
                    Object.keys(EMPTY).map((key) => [key, lead[key] ?? '']),
                ),
                // manager приезжает объектом, а форме нужен идентификатор.
                manager_id: lead.manager?.id ?? '',
                stage_id: lead.stage_id ?? '',
            }
            : EMPTY);
    }, [open, lead]);

    const set = (patch) => setForm((prev) => ({ ...prev, ...patch }));

    const payload = (overrides = {}) => ({
        ...form,
        manager_id: form.manager_id === '' ? null : form.manager_id,
        stage_id: form.stage_id === '' ? null : form.stage_id,
        qualified_amount: form.qualified_amount === '' ? null : form.qualified_amount,
        expected_close_at: form.expected_close_at || null,
        ...overrides,
    });

    const save = async (overrides = {}) => {
        setBusy(true);
        setErrors({});

        try {
            if (lead) {
                await axios.patch(route('crm.leads.update', lead.id), payload(overrides));
            } else {
                await axios.post(route('crm.leads.store'), payload(overrides));
            }

            onSaved?.();
        } catch (error) {
            if (error.response?.status === 422) {
                setErrors(error.response.data.errors ?? {});
                setTab('main');
            } else {
                toastError('Не удалось сохранить лида.');
            }
        } finally {
            setBusy(false);
        }
    };

    // Разбор ничьих лидов: список менеджеров рядовому сотруднику не отдаётся,
    // поэтому «взять себе» — отдельное действие, а не выбор в списке.
    const takeOwnership = () => {
        set({ manager_id: currentManagerId });
        save({ manager_id: currentManagerId });
    };

    const convert = async () => {
        if (! partner) return;

        setBusy(true);

        try {
            await axios.post(route('crm.leads.convert', lead.id), { user_id: partner.id });
            toastSuccess('Лид переведён в партнёры.');
            onSaved?.();
        } catch (error) {
            toastError(error.response?.data?.message ?? 'Не удалось перевести лида в партнёры.');
        } finally {
            setBusy(false);
        }
    };

    // Удаление идёт через Inertia, а не axios: маршрут отвечает редиректом назад,
    // и axios получил бы в ответ HTML страницы вместо JSON.
    const remove = () => {
        router.delete(route('crm.leads.destroy', lead.id), {
            preserveScroll: true,
            onSuccess: () => {
                setConfirmDelete(false);
                toastSuccess('Лид удалён.');
                onDeleted?.();
            },
            onError: () => {
                setConfirmDelete(false);
                toastError('Не удалось удалить лида.');
            },
        });
    };

    const err = (key) => errors[key]?.[0];
    const saved = Boolean(lead);
    const canAssignAny = managers.length > 0;
    const isOrphan = saved && ! lead.manager;
    const canTakeOwnership = isOrphan && canEdit && ! canAssignAny && currentManagerId !== null;

    return (
        <Dialog.Root
            open={open}
            onOpenChange={({ open: isOpen }) => ! isOpen && onClose()}
            size="lg"
            scrollBehavior="inside"
            closeOnInteractOutside={false}
        >
            <Portal>
                <Dialog.Backdrop />
                <Dialog.Positioner>
                    <Dialog.Content>
                        <Dialog.Header>
                            <Dialog.Title>{saved ? lead.name : 'Новый лид'}</Dialog.Title>
                        </Dialog.Header>

                        <Dialog.Body>
                            <Tabs.Root
                                value={tab}
                                onValueChange={({ value }) => setTab(value)}
                                lazyMount
                                unmountOnExit
                            >
                                <Tabs.List>
                                    <Tabs.Trigger value="main">Основное</Tabs.Trigger>
                                    {saved && can('crm-comments.view') && (
                                        <Tabs.Trigger value="comments">Комментарии</Tabs.Trigger>
                                    )}
                                    {saved && can('crm-tasks.view') && (
                                        <Tabs.Trigger value="tasks">Задачи</Tabs.Trigger>
                                    )}
                                    {saved && can('crm-attachments.view') && (
                                        <Tabs.Trigger value="files">Файлы</Tabs.Trigger>
                                    )}
                                </Tabs.List>

                                <Tabs.Content value="main">
                                    <VStack align="stretch" gap={4} pt={2}>
                                        <Field label="Имя или организация" required invalid={!! err('name')} errorText={err('name')}>
                                            <Input
                                                value={form.name}
                                                onChange={(event) => set({ name: event.target.value })}
                                                disabled={! canEdit}
                                            />
                                        </Field>

                                        <SimpleGrid columns={{ base: 1, md: 2 }} gap={3}>
                                            <Field label="Стадия воронки">
                                                <NativeSelectRoot disabled={! canEdit}>
                                                    <NativeSelectField
                                                        value={form.stage_id}
                                                        onChange={(event) => set({ stage_id: event.target.value })}
                                                    >
                                                        <option value="">Без стадии</option>
                                                        {stages.map((stage) => (
                                                            <option key={stage.id} value={stage.id}>{stage.name}</option>
                                                        ))}
                                                    </NativeSelectField>
                                                </NativeSelectRoot>
                                            </Field>

                                            <Field
                                                label="Ответственный менеджер"
                                                invalid={!! err('manager_id')}
                                                errorText={err('manager_id')}
                                            >
                                                {canAssignAny ? (
                                                    <NativeSelectRoot disabled={! canEdit}>
                                                        <NativeSelectField
                                                            value={form.manager_id}
                                                            onChange={(event) => set({ manager_id: event.target.value })}
                                                        >
                                                            <option value="">Ничей — разобрать</option>
                                                            {managers.map((manager) => (
                                                                <option key={manager.id} value={manager.id}>
                                                                    {manager.name}
                                                                </option>
                                                            ))}
                                                        </NativeSelectField>
                                                    </NativeSelectRoot>
                                                ) : (
                                                    <HStack gap={2}>
                                                        <Text fontSize="sm" color={lead?.manager ? 'fg' : 'fg.muted'}>
                                                            {lead?.manager?.name ?? 'Ничей — разобрать'}
                                                        </Text>
                                                        {canTakeOwnership && (
                                                            <Button size="xs" variant="outline" onClick={takeOwnership} loading={busy}>
                                                                <LuUserCheck /> Взять себе
                                                            </Button>
                                                        )}
                                                    </HStack>
                                                )}
                                            </Field>
                                        </SimpleGrid>

                                        <SimpleGrid columns={{ base: 1, md: 3 }} gap={3}>
                                            <Field label="Телефон" invalid={!! err('phone')} errorText={err('phone')}>
                                                <Input
                                                    value={form.phone}
                                                    onChange={(event) => set({ phone: event.target.value })}
                                                    disabled={! canEdit}
                                                />
                                            </Field>
                                            <Field label="Email" invalid={!! err('email')} errorText={err('email')}>
                                                <Input
                                                    value={form.email}
                                                    onChange={(event) => set({ email: event.target.value })}
                                                    disabled={! canEdit}
                                                />
                                            </Field>
                                            <Field label="Мессенджер">
                                                <Input
                                                    value={form.messenger}
                                                    onChange={(event) => set({ messenger: event.target.value })}
                                                    disabled={! canEdit}
                                                />
                                            </Field>
                                        </SimpleGrid>

                                        <SimpleGrid columns={{ base: 1, md: 2 }} gap={3}>
                                            <Field label="Организация">
                                                <Input
                                                    value={form.company_name}
                                                    onChange={(event) => set({ company_name: event.target.value })}
                                                    disabled={! canEdit}
                                                />
                                            </Field>
                                            <Field label="Откуда пришёл">
                                                <Input
                                                    value={form.source}
                                                    onChange={(event) => set({ source: event.target.value })}
                                                    placeholder="Выставка, сайт, рекомендация..."
                                                    disabled={! canEdit}
                                                />
                                            </Field>
                                        </SimpleGrid>

                                        <SimpleGrid columns={{ base: 1, md: 2 }} gap={3}>
                                            <Field label="Оценка сделки, ₽">
                                                <Input
                                                    type="number"
                                                    min={0}
                                                    value={form.qualified_amount}
                                                    onChange={(event) => set({ qualified_amount: event.target.value })}
                                                    disabled={! canEdit}
                                                />
                                            </Field>
                                            <Field label="Ожидаемое закрытие">
                                                <Input
                                                    type="date"
                                                    value={form.expected_close_at}
                                                    onChange={(event) => set({ expected_close_at: event.target.value })}
                                                    disabled={! canEdit}
                                                />
                                            </Field>
                                        </SimpleGrid>

                                        <Field label="ЛПР — кто принимает решение">
                                            <Input
                                                value={form.decision_maker}
                                                onChange={(event) => set({ decision_maker: event.target.value })}
                                                disabled={! canEdit}
                                            />
                                        </Field>

                                        <Field label="Что интересует">
                                            <Textarea
                                                rows={2}
                                                value={form.interests}
                                                onChange={(event) => set({ interests: event.target.value })}
                                                disabled={! canEdit}
                                            />
                                        </Field>

                                        <Field label="Заметки">
                                            <Textarea
                                                rows={4}
                                                value={form.notes}
                                                onChange={(event) => set({ notes: event.target.value })}
                                                disabled={! canEdit}
                                            />
                                        </Field>

                                        {saved && (
                                            <Field label="Причина проигрыша">
                                                <Input
                                                    value={form.lost_reason}
                                                    onChange={(event) => set({ lost_reason: event.target.value })}
                                                    disabled={! canEdit}
                                                />
                                            </Field>
                                        )}

                                        {saved && canEdit && (
                                            <Box pt={3} borderTopWidth="1px">
                                                <Text fontSize="sm" fontWeight="600" mb={2}>Перевод в партнёры</Text>

                                                {lead.converted_user ? (
                                                    <Text fontSize="sm" color="fg.muted">
                                                        Лид уже связан с партнёром{' '}
                                                        <a
                                                            href={route('crm.clients.show', lead.converted_user.id)}
                                                            style={{ textDecoration: 'underline' }}
                                                        >
                                                            {lead.converted_user.name}
                                                        </a>.
                                                    </Text>
                                                ) : (
                                                    <VStack align="stretch" gap={2}>
                                                        <Text fontSize="xs" color="fg.muted">
                                                            Партнёра заводит 1С. Выберите того, кем стал этот лид, —
                                                            он перейдёт в выигрышную стадию.
                                                        </Text>
                                                        <EntitySelector
                                                            value={partner}
                                                            onChange={setPartner}
                                                            searchUrl={route('crm.tasks.entities')}
                                                            searchParams={{ type: 'client' }}
                                                            placeholder="Имя, email или телефон партнёра"
                                                        />
                                                        <Box>
                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                onClick={convert}
                                                                disabled={! partner || busy}
                                                            >
                                                                Перевести в партнёры
                                                            </Button>
                                                        </Box>
                                                    </VStack>
                                                )}
                                            </Box>
                                        )}
                                    </VStack>
                                </Tabs.Content>

                                {saved && can('crm-comments.view') && (
                                    <Tabs.Content value="comments">
                                        <Box pt={2}>
                                            <CommentThread
                                                entityType="lead"
                                                entityId={lead.id}
                                                canCreate={can('crm-comments.create')}
                                            />
                                        </Box>
                                    </Tabs.Content>
                                )}

                                {saved && can('crm-tasks.view') && (
                                    <Tabs.Content value="tasks">
                                        <Box pt={2}>
                                            <TaskPanel entityType="lead" entityId={lead.id} />
                                        </Box>
                                    </Tabs.Content>
                                )}

                                {saved && can('crm-attachments.view') && (
                                    <Tabs.Content value="files">
                                        <VStack align="stretch" gap={4} pt={2}>
                                            <AttachmentPanel
                                                entityType="lead"
                                                entityId={lead.id}
                                                canUpload={can('crm-attachments.create')}
                                                label="Файлы лида"
                                            />
                                            <Box pt={2} borderTopWidth="1px">
                                                <Text fontSize="xs" color="fg.muted" mb={2}>Голосом</Text>
                                                <VoiceNotes
                                                    entityType="lead"
                                                    entityId={lead.id}
                                                    canCreate={can('crm-attachments.create')}
                                                    compact
                                                />
                                            </Box>
                                        </VStack>
                                    </Tabs.Content>
                                )}
                            </Tabs.Root>
                        </Dialog.Body>

                        <Dialog.Footer>
                            <HStack gap={2} justify="space-between" w="full">
                                <Box>
                                    {saved && canDelete && (
                                        <Button
                                            variant="outline"
                                            colorPalette="red"
                                            onClick={() => setConfirmDelete(true)}
                                            disabled={busy}
                                        >
                                            <LuTrash2 /> Удалить
                                        </Button>
                                    )}
                                </Box>

                                <HStack gap={2}>
                                    <Button variant="ghost" onClick={onClose} disabled={busy}>Закрыть</Button>
                                    {canEdit && (
                                        <Button onClick={() => save()} loading={busy}>Сохранить</Button>
                                    )}
                                </HStack>
                            </HStack>
                        </Dialog.Footer>
                    </Dialog.Content>
                </Dialog.Positioner>
            </Portal>

            <ConfirmDialog
                open={confirmDelete}
                onClose={() => setConfirmDelete(false)}
                onConfirm={remove}
                title="Удалить лида?"
                description={`Лид «${lead?.name ?? ''}» и его переписка исчезнут с доски. Вернуть его сможет только администратор базы.`}
                confirmLabel="Удалить"
            />
        </Dialog.Root>
    );
}
