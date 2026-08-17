import { useEffect, useState } from 'react';
import axios from 'axios';
import { usePage } from '@inertiajs/react';
import {
    Box,
    Dialog,
    HStack,
    Input,
    NativeSelectField,
    NativeSelectRoot,
    Portal,
    SimpleGrid,
    Text,
    VStack,
} from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field } from '@/components/ui/field';
import VoiceInput from '@/shared/voice/VoiceInput';
import VoiceTextarea from '@/shared/voice/VoiceTextarea';
import { EntitySelector } from '@/Admin/Components/EntitySelector';
import { useTaskOptions } from '@/Crm/Components/useTaskOptions';
import CommentThread from '@/Crm/Components/CommentThread';
import TaskChecklist from '@/Crm/Components/TaskChecklist';
import AttachmentPanel from '@/Crm/Components/AttachmentPanel';
import VoiceNotes from '@/Crm/Components/VoiceNotes';
import { usePermission } from '@/shared/Panel/usePermission';
import { LuEye } from 'react-icons/lu';
import { toastError, toastSuccess } from '@/utils/toast';

const EMPTY = {
    title: '',
    description: '',
    assignee_id: '',
    co_assignee_ids: [],
    status: 'open',
    priority: 'normal',
    due_at: '',
    estimate_minutes: '',
};

// Пресеты плановой трудоёмкости; своё значение из БД добавляется на лету.
const ESTIMATE_OPTIONS = [
    { value: '', label: 'Не оценена' },
    { value: '15', label: '15 минут' },
    { value: '30', label: '30 минут' },
    { value: '60', label: '1 час' },
    { value: '120', label: '2 часа' },
    { value: '240', label: '4 часа' },
    { value: '480', label: 'Рабочий день' },
];

/**
 * Создание и правка задачи.
 *
 * Живёт в Components, а не в Pages/Tasks: тот же диалог открывается из карточки партнёра
 * и из админских карточек заказа и реализации, где страницы задач нет вовсе.
 *
 * Комментарии и файлы показываются только у сохранённой задачи — `MediaService` не умеет
 * загружать «в никуда», поэтому новая задача создаётся первым шагом.
 *
 * @param {object|null} task — правим существующую или создаём новую
 * @param {{type: string, id: number}|null} entity — привязка для новой задачи
 * @param {string} initialTitle — заголовок новой задачи по умолчанию (правится вручную)
 * @param {Function} onSaved — колбэк с сохранённой задачей
 */
export default function TaskDialog({
    open,
    onClose,
    task = null,
    // Из списков приходит только идентификатор: строка таблицы знает про задачу
    // срок и заголовок, но не статус, приоритет и права. Догружаем сами, чтобы
    // каждый список не таскал полный объект ради одной кнопки.
    taskId = null,
    entity = null,
    initialTitle = '',
    // Срок новой задачи по умолчанию — календарь передаёт кликнутый день.
    initialDue = '',
    onSaved,
}) {
    const [loadedTask, setLoadedTask] = useState(null);
    const options = useTaskOptions(open);
    const { can } = usePermission();
    const { auth } = usePage().props;
    const currentUserId = auth?.user?.id ?? null;
    const [form, setForm] = useState(EMPTY);
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);
    // Привязка новой задачи: тип выбирается селектом, сама запись — поиском.
    // Когда диалог открыт из карточки сущности, привязка приходит пропсом и не выбирается.
    const [linkType, setLinkType] = useState('');
    const [linkEntity, setLinkEntity] = useState(null);
    // Личный контроль: локальное состояние, чтобы кнопка отвечала мгновенно.
    const [watched, setWatched] = useState(false);
    const [watchBusy, setWatchBusy] = useState(false);

    // Задача либо передана целиком, либо догружена по идентификатору.
    const current = task ?? loadedTask;
    const isEdit = !! current;
    const canEditFields = ! isEdit || current.can?.update;
    const canReassign = ! isEdit || current.can?.reassign;

    useEffect(() => {
        if (! open || ! taskId || task) {
            setLoadedTask(null);

            return;
        }

        let cancelled = false;

        axios.get(route('crm.tasks.show', taskId))
            .then(({ data }) => { if (! cancelled) setLoadedTask(data); })
            .catch(() => { if (! cancelled) toastError('Не удалось открыть задачу.'); });

        return () => { cancelled = true; };
    }, [open, taskId, task]);

    useEffect(() => {
        if (!open) {
            return;
        }

        setErrors({});
        setLinkType('');
        setLinkEntity(null);
        setForm(current
            ? {
                title: current.title || '',
                description: current.description || '',
                assignee_id: current.assignee?.id ?? '',
                co_assignee_ids: (current.co_assignees || []).map((user) => Number(user.id)),
                status: current.status || 'open',
                priority: current.priority || 'normal',
                due_at: current.due_at || '',
                estimate_minutes: current.estimate_minutes ? String(current.estimate_minutes) : '',
            }
            // Заголовок-заготовка от вызывающей страницы (например, «Оплата по
            // реализации …»): экономит набор текста, но остаётся обычным полем.
            : { ...EMPTY, title: initialTitle || '', due_at: initialDue || '' });
    }, [open, current, initialTitle, initialDue]);

    // Исполнитель новой задачи по умолчанию — тот, кто её ставит: себе задачи
    // ставят чаще, чем коллеге. Ждём справочник, потому что подставлять человека,
    // которого нет в списке (например, админа без доступа в CRM), нельзя —
    // такую задачу бэкенд не примет.
    useEffect(() => {
        if (!open || isEdit || !currentUserId || !options?.assignees) {
            return;
        }

        if (!options.assignees.some((user) => Number(user.id) === Number(currentUserId))) {
            return;
        }

        setForm((prev) => (prev.assignee_id ? prev : { ...prev, assignee_id: String(currentUserId) }));
    }, [open, isEdit, currentUserId, options]);

    useEffect(() => {
        setWatched(!!current?.is_watched);
    }, [current]);

    const set = (field, value) => setForm((prev) => ({ ...prev, [field]: value }));

    const toggleWatch = async () => {
        if (!current) {
            return;
        }

        setWatchBusy(true);
        try {
            const res = watched
                ? await axios.delete(`/crm/tasks/${current.id}/watch`)
                : await axios.post(`/crm/tasks/${current.id}/watch`);

            setWatched(!!res.data.is_watched);
            onSaved?.(res.data);
        } catch (e) {
            toastError('Не удалось изменить контроль', e?.response?.data?.message || 'Попробуйте ещё раз.');
        } finally {
            setWatchBusy(false);
        }
    };

    const submit = async () => {
        setBusy(true);
        setErrors({});

        try {
            const payload = {
                title: form.title,
                description: form.description || null,
                status: form.status,
                priority: form.priority,
                due_at: form.due_at || null,
                estimate_minutes: form.estimate_minutes ? Number(form.estimate_minutes) : null,
            };

            if (canReassign && form.assignee_id) {
                payload.assignee_id = form.assignee_id;
            }

            // Состав соисполнителей шлём только тому, кто вправе его менять:
            // иначе бэкенд молча проигнорирует поле, а форма соврёт, что сохранила.
            if (canReassign) {
                payload.co_assignee_ids = form.co_assignee_ids;
            }

            let saved;

            if (isEdit) {
                saved = await axios.patch(`/crm/tasks/${current.id}`, payload);
            } else {
                // Привязка из карточки сущности имеет приоритет над выбранной вручную:
                // диалог там открыт «по этой записи», и менять цель на лету незачем.
                if (entity) {
                    payload.entity_type = entity.type;
                    payload.entity_id = entity.id;
                } else if (linkType && linkEntity) {
                    payload.entity_type = linkType;
                    payload.entity_id = linkEntity.id;
                }
                saved = await axios.post('/crm/tasks', payload);
            }

            toastSuccess(isEdit ? 'Задача сохранена' : 'Задача поставлена');
            onSaved?.(saved.data);
            onClose();
        } catch (e) {
            if (e?.response?.status === 422) {
                setErrors(e.response.data.errors || {});
            } else {
                toastError('Задача не сохранена', e?.response?.data?.message || 'Попробуйте ещё раз.');
            }
        } finally {
            setBusy(false);
        }
    };

    const error = (field) => errors[field]?.[0];

    return (
        // Клик мимо диалог не закрывает: выпадашка поиска записи рисуется порталом
        // в body, то есть формально «снаружи», и выбор партнёра закрывал бы всю форму
        // вместе с набранным текстом. Escape и кнопка «Закрыть» работают как обычно.
        <Dialog.Root
            open={open}
            onOpenChange={({ open: isOpen }) => !isOpen && onClose()}
            size="lg"
            scrollBehavior="inside"
            closeOnInteractOutside={false}
        >
            <Portal>
                <Dialog.Backdrop />
                <Dialog.Positioner>
                    <Dialog.Content>
                        <Dialog.Header>
                            <Dialog.Title>{isEdit ? 'Задача' : 'Новая задача'}</Dialog.Title>
                        </Dialog.Header>

                        <Dialog.Body>
                            <VStack align="stretch" gap={4}>
                                {isEdit && (
                                    <Text fontSize="xs" color="fg.muted">
                                        {current.entity
                                            ? <>Привязана: {current.entity.label} — {current.entity.url
                                                ? <a href={current.entity.url}>{current.entity.title}</a>
                                                : current.entity.title}</>
                                            : 'Без привязки к записи'}
                                    </Text>
                                )}

                                {/* Имя партнёра тонкой строкой: диалог открывают
                                    из строки таблицы, и пока заполняешь поля,
                                    легко забыть, на ком его открыл. */}
                                {! isEdit && entity && (
                                    <Text fontSize="xs" color="fg.muted">
                                        {entity.title
                                            ? <>Будет привязана: {entity.label || 'Партнёр'} — <Text as="span" fontWeight="600">{entity.title}</Text></>
                                            : 'Задача будет привязана к текущей записи.'}
                                    </Text>
                                )}

                                {/* Привязка выбирается только у новой задачи и только когда
                                    диалог открыт не из карточки сущности. Перепривязка
                                    существующей задачи — отдельная история, её API не умеет. */}
                                {!isEdit && !entity && (
                                    <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                        <Field
                                            label="К чему привязать"
                                            helperText="Необязательно — задача может жить сама по себе"
                                            errorText={error('entity_type')}
                                            invalid={!!error('entity_type')}
                                        >
                                            <NativeSelectRoot>
                                                <NativeSelectField
                                                    value={linkType}
                                                    onChange={(e) => {
                                                        setLinkType(e.target.value);
                                                        setLinkEntity(null);
                                                    }}
                                                >
                                                    <option value="">Без привязки</option>
                                                    {(options?.entity_types || []).map((item) => (
                                                        <option key={item.value} value={item.value}>{item.label}</option>
                                                    ))}
                                                </NativeSelectField>
                                            </NativeSelectRoot>
                                        </Field>

                                        {linkType && (
                                            <Field
                                                label="Запись"
                                                errorText={error('entity_id')}
                                                invalid={!!error('entity_id')}
                                            >
                                                <EntitySelector
                                                    value={linkEntity}
                                                    onChange={setLinkEntity}
                                                    searchUrl={route('crm.tasks.entities')}
                                                    searchParams={{ type: linkType }}
                                                    placeholder={linkType === 'client'
                                                        ? 'Имя, email или телефон партнёра'
                                                        : 'Номер документа'}
                                                    renderItem={(item) => (
                                                        <Box>
                                                            <Text fontSize="sm">{item.label}</Text>
                                                            {item.sublabel && (
                                                                <Text fontSize="xs" color="fg.muted">{item.sublabel}</Text>
                                                            )}
                                                        </Box>
                                                    )}
                                                />
                                            </Field>
                                        )}
                                    </SimpleGrid>
                                )}

                                <Field label="Что сделать" required errorText={error('title')} invalid={!!error('title')}>
                                    <VoiceInput
                                        value={form.title}
                                        onChange={(value) => set('title', value)}
                                        placeholder="Например: выставить счёт по заявке"
                                        title="Надиктовать задачу"
                                        disabled={!canEditFields}
                                    />
                                </Field>

                                <Field label="Описание" errorText={error('description')} invalid={!!error('description')}>
                                    <VoiceTextarea
                                        value={form.description}
                                        onChange={(value) => set('description', value)}
                                        rows={3}
                                        placeholder="Подробности, если нужны"
                                        disabled={!canEditFields}
                                    />
                                </Field>

                                <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                    <Field label="Исполнитель" required errorText={error('assignee_id')} invalid={!!error('assignee_id')}>
                                        <NativeSelectRoot disabled={!canReassign}>
                                            <NativeSelectField
                                                value={form.assignee_id}
                                                onChange={(e) => set('assignee_id', e.target.value)}
                                            >
                                                <option value="">Выберите сотрудника</option>
                                                {(options?.assignees || []).map((user) => (
                                                    <option key={user.id} value={user.id}>{user.name}</option>
                                                ))}
                                            </NativeSelectField>
                                        </NativeSelectRoot>
                                    </Field>

                                    <Field label="Срок" errorText={error('due_at')} invalid={!!error('due_at')}>
                                        <Input
                                            type="datetime-local"
                                            value={form.due_at}
                                            onChange={(e) => set('due_at', e.target.value)}
                                            disabled={!canEditFields}
                                        />
                                    </Field>

                                    <Field label="Статус">
                                        <NativeSelectRoot disabled={!canEditFields}>
                                            <NativeSelectField
                                                value={form.status}
                                                onChange={(e) => set('status', e.target.value)}
                                            >
                                                {(options?.statuses || []).map((item) => (
                                                    <option key={item.value} value={item.value}>{item.label}</option>
                                                ))}
                                            </NativeSelectField>
                                        </NativeSelectRoot>
                                    </Field>

                                    <Field label="Приоритет">
                                        <NativeSelectRoot disabled={!canEditFields}>
                                            <NativeSelectField
                                                value={form.priority}
                                                onChange={(e) => set('priority', e.target.value)}
                                            >
                                                {(options?.priorities || []).map((item) => (
                                                    <option key={item.value} value={item.value}>{item.label}</option>
                                                ))}
                                            </NativeSelectField>
                                        </NativeSelectRoot>
                                    </Field>

                                    <Field
                                        label="Трудоёмкость"
                                        helperText="Плановая оценка, необязательно"
                                        errorText={error('estimate_minutes')}
                                        invalid={!!error('estimate_minutes')}
                                    >
                                        <NativeSelectRoot disabled={!canEditFields}>
                                            <NativeSelectField
                                                value={form.estimate_minutes}
                                                onChange={(e) => set('estimate_minutes', e.target.value)}
                                            >
                                                {estimateOptions(form.estimate_minutes).map((item) => (
                                                    <option key={item.value} value={item.value}>{item.label}</option>
                                                ))}
                                            </NativeSelectField>
                                        </NativeSelectRoot>
                                    </Field>
                                </SimpleGrid>

                                {/* Соисполнители работают вместе с ответственным, но за срок
                                    отвечает он один — поэтому отдельный блок, а не мультиселект
                                    на месте исполнителя. */}
                                {canReassign && (options?.assignees || []).length > 1 && (
                                    <Field label={`Соисполнители${form.co_assignee_ids.length ? ` (${form.co_assignee_ids.length})` : ''}`}>
                                        <Box
                                            borderWidth="1px"
                                            borderRadius="md"
                                            p={2}
                                            maxH="140px"
                                            overflowY="auto"
                                            w="100%"
                                        >
                                            <VStack align="stretch" gap={1}>
                                                {(options?.assignees || [])
                                                    .filter((user) => String(user.id) !== String(form.assignee_id))
                                                    .map((user) => (
                                                        <Checkbox
                                                            key={user.id}
                                                            size="sm"
                                                            checked={form.co_assignee_ids.includes(Number(user.id))}
                                                            onCheckedChange={(e) => set(
                                                                'co_assignee_ids',
                                                                e.checked
                                                                    ? [...form.co_assignee_ids, Number(user.id)]
                                                                    : form.co_assignee_ids.filter((id) => id !== Number(user.id)),
                                                            )}
                                                        >
                                                            {user.name}
                                                        </Checkbox>
                                                    ))}
                                            </VStack>
                                        </Box>
                                    </Field>
                                )}

                                {isEdit && !canReassign && (current.co_assignees || []).length > 0 && (
                                    <Text fontSize="xs" color="fg.muted">
                                        Соисполнители: {current.co_assignees.map((user) => user.name).join(', ')}
                                    </Text>
                                )}

                                {isEdit && (
                                    <>
                                        <Box pt={2} borderTopWidth="1px">
                                            <TaskChecklist
                                                taskId={current.id}
                                                items={current.checklist ?? null}
                                                canEdit={!!current.can?.update}
                                                onChanged={() => onSaved?.(current)}
                                            />
                                        </Box>

                                        {can('crm-comments.view') && (
                                            <Box pt={2} borderTopWidth="1px">
                                                <Text fontSize="sm" fontWeight="600" mb={2}>Обсуждение</Text>
                                                <CommentThread
                                                    entityType="task"
                                                    entityId={current.id}
                                                    canCreate={can('crm-comments.create')}
                                                />
                                            </Box>
                                        )}

                                        {can('crm-attachments.view') && (
                                            <Box pt={2} borderTopWidth="1px">
                                                <AttachmentPanel
                                                    entityType="task"
                                                    entityId={current.id}
                                                    canUpload={can('crm-attachments.create')}
                                                    label="Файлы задачи"
                                                />
                                            </Box>
                                        )}

                                        {can('crm-attachments.view') && (
                                            <Box pt={2} borderTopWidth="1px">
                                                <Text fontSize="xs" color="fg.muted" mb={2}>Голосом</Text>
                                                <VoiceNotes
                                                    entityType="task"
                                                    entityId={current.id}
                                                    canCreate={can('crm-attachments.create')}
                                                    compact
                                                />
                                            </Box>
                                        )}
                                    </>
                                )}
                            </VStack>
                        </Dialog.Body>

                        <Dialog.Footer>
                            <HStack gap={2} w="100%" justify={isEdit && current.can?.watch ? 'space-between' : 'flex-end'}>
                                {isEdit && current.can?.watch && (
                                    <Button
                                        size="sm"
                                        variant={watched ? 'solid' : 'outline'}
                                        colorPalette={watched ? 'purple' : undefined}
                                        onClick={toggleWatch}
                                        loading={watchBusy}
                                        title={watched
                                            ? 'Задача у вас на личном контроле'
                                            : 'Получать уведомления о закрытии и переносах'}
                                    >
                                        <LuEye /> {watched ? 'На контроле' : 'На контроль'}
                                    </Button>
                                )}
                                <HStack gap={2}>
                                    <Button variant="outline" onClick={onClose} disabled={busy}>Закрыть</Button>
                                    {canEditFields && (
                                        <Button onClick={submit} loading={busy}>
                                            {isEdit ? 'Сохранить' : 'Поставить задачу'}
                                        </Button>
                                    )}
                                </HStack>
                            </HStack>
                        </Dialog.Footer>
                    </Dialog.Content>
                </Dialog.Positioner>
            </Portal>
        </Dialog.Root>
    );
}

/**
 * Пресеты трудоёмкости + текущее значение из БД, если оно нестандартное:
 * иначе селект показал бы «Не оценена» при заполненном поле.
 */
function estimateOptions(currentValue) {
    if (!currentValue || ESTIMATE_OPTIONS.some((item) => item.value === String(currentValue))) {
        return ESTIMATE_OPTIONS;
    }

    return [...ESTIMATE_OPTIONS, { value: String(currentValue), label: `${currentValue} мин` }];
}
