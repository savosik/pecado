import { useRef, useState } from 'react';
import axios from 'axios';
import { usePage } from '@inertiajs/react';
import { Box, HStack, Input, Text, VStack } from '@chakra-ui/react';
import { LuPaperclip, LuSend, LuSettings2 } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';
import { SegmentedControl } from '@/components/ui/segmented-control';
import VoiceTextarea from '@/shared/voice/VoiceTextarea';
import { usePermission } from '@/shared/Panel/usePermission';
import { useTaskOptions } from '@/Crm/Components/useTaskOptions';
import { toastError, toastSuccess } from '@/utils/toast';
import { entryFromTask } from './timelineEntry';

/**
 * Быстрые сроки задачи.
 *
 * Календарь в поле ввода ленты — это три клика на действие, которое в 90 %
 * случаев означает «сегодня» или «завтра». Точная дата остаётся в полном диалоге.
 */
const DUE_PRESETS = [
    { key: 'today', label: 'Сегодня', hours: 0 },
    { key: 'tomorrow', label: 'Завтра', hours: 24 },
    { key: 'in3', label: '+3 дня', hours: 72 },
    { key: 'week', label: 'Через неделю', hours: 168 },
];

function dueFromPreset(key) {
    const preset = DUE_PRESETS.find((item) => item.key === key);
    if (!preset) return '';

    const date = new Date();
    date.setHours(date.getHours() + preset.hours);
    if (preset.key === 'today') {
        date.setHours(18, 0, 0, 0);
    } else {
        date.setHours(12, 0, 0, 0);
    }

    // Формат, который ждёт бэкенд от полей datetime-local.
    const pad = (n) => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/**
 * Поле ввода ленты: комментарий, задача, письмо, файл.
 *
 * Каждый режим уходит в свой существующий эндпоинт — общего «feed.store»
 * с полиморфным диспатчем нет намеренно: он неизбежно разошёлся бы в правах
 * и валидации с теми четырьмя, что уже работают.
 *
 * @param {number} clientId
 * @param {Function} onCreated — готовая запись ленты для мгновенной вставки
 * @param {Function} onCreateComment — создание комментария через ленту (умеет закреплённые)
 * @param {Function} onCompose — открыть полный диалог письма
 * @param {Function} onFullTask — открыть полный диалог задачи
 * @param {Function} onCall — открыть диалог звонка (появится вместе с crm-18)
 */
export default function FeedComposer({
    clientId,
    onCreated,
    onCreateComment,
    onCompose,
    onFullTask,
    onCall = null,
    busy = false,
}) {
    const { can } = usePermission();
    const { auth } = usePage().props;
    const [mode, setMode] = useState('comment');
    const [text, setText] = useState('');
    const [pinned, setPinned] = useState(false);
    const [due, setDue] = useState('today');
    const [assignee, setAssignee] = useState('');
    const [sending, setSending] = useState(false);
    const fileInput = useRef(null);

    const canComment = can('crm-comments.create');
    const canTask = can('crm-tasks.create');
    const canEmail = can('crm-emails.create');
    const canFile = can('crm-attachments.create') && canComment;
    const canCall = onCall !== null && can('crm-calls.create');

    // Список исполнителей нужен только в режиме задачи — грузим по факту переключения.
    const taskOptions = useTaskOptions(mode === 'task' && canTask);

    const modes = [
        ...(canComment ? [{ value: 'comment', label: 'Комментарий' }] : []),
        ...(canTask ? [{ value: 'task', label: 'Задача' }] : []),
        ...(canEmail ? [{ value: 'email', label: 'Письмо' }] : []),
        ...(canCall ? [{ value: 'call', label: 'Звонок' }] : []),
        ...(canFile ? [{ value: 'file', label: 'Файл' }] : []),
    ];

    if (modes.length === 0) {
        return null;
    }

    const submitComment = async () => {
        const body = text.trim();
        if (!body) return;

        const ok = await onCreateComment({
            entity_type: 'client',
            entity_id: clientId,
            body,
            is_pinned: pinned,
        });

        if (ok) {
            setText('');
            setPinned(false);
        }
    };

    const submitTask = async () => {
        const title = text.trim();
        if (!title) return;

        setSending(true);
        try {
            const { data } = await axios.post(route('crm.tasks.store'), {
                entity_type: 'client',
                entity_id: clientId,
                title,
                due_at: dueFromPreset(due),
                // Исполнитель обязателен на бэкенде: по умолчанию задача остаётся
                // за тем, кто её пишет — из ленты чаще всего ставят себе.
                assignee_id: assignee || auth?.user?.id,
                priority: 'normal',
            });
            onCreated(entryFromTask(data));
            setText('');
            toastSuccess('Задача поставлена');
        } catch (e) {
            const message = e?.response?.data?.message || 'Проверьте текст и попробуйте ещё раз.';
            toastError('Задача не создана', message);
        } finally {
            setSending(false);
        }
    };

    /**
     * Файл вешается на комментарий-подпись.
     *
     * MediaService не умеет загружать «в никуда», поэтому сначала запись, потом
     * файл. Если загрузка упадёт, комментарий остаётся — к нему можно приложить
     * файл повторно, а не терять уже написанный текст.
     */
    const submitFile = async (files) => {
        if (!files?.length) return;

        setSending(true);
        try {
            const caption = text.trim() || (files.length === 1 ? files[0].name : `Файлов: ${files.length}`);
            const { data: comment } = await axios.post('/crm/comments', {
                entity_type: 'client',
                entity_id: clientId,
                body: caption,
            });

            let uploaded = 0;

            for (const file of files) {
                const form = new FormData();
                form.append('entity_type', 'comment');
                form.append('entity_id', comment.id);
                form.append('file', file);

                try {
                    await axios.post('/crm/attachments', form, {
                        headers: { 'Content-Type': 'multipart/form-data' },
                    });
                    uploaded++;
                } catch (e) {
                    const message = e?.response?.data?.message
                        || e?.response?.data?.errors?.file?.[0]
                        || 'Попробуйте приложить его к записи ещё раз.';
                    toastError(`Файл «${file.name}» не загружен`, message);
                }
            }

            onCreated({ ...comment, attachments_count: uploaded });
            setText('');
        } catch (e) {
            const message = e?.response?.data?.message || 'Попробуйте ещё раз.';
            toastError('Запись не создана', message);
        } finally {
            setSending(false);
            if (fileInput.current) {
                fileInput.current.value = '';
            }
        }
    };

    const handleModeChange = (value) => {
        setMode(value);

        // Письмо и звонок — полноценные формы, инлайн их не втиснуть.
        if (value === 'email') {
            onCompose();
            setMode('comment');
        }
        if (value === 'call') {
            onCall?.();
            setMode('comment');
        }
        if (value === 'file') {
            fileInput.current?.click();
        }
    };

    const placeholder = mode === 'task'
        ? 'Что нужно сделать? Enter — поставить задачу'
        : 'Написать в ленту клиента… Ctrl+Enter — отправить';

    const submit = mode === 'task' ? submitTask : submitComment;

    return (
        <Box
            borderTopWidth="1px"
            borderColor="border"
            bg="bg.panel"
            px={3}
            py={3}
            position="sticky"
            bottom={0}
        >
            <VStack align="stretch" gap={2}>
                <HStack justify="space-between" gap={2} flexWrap="wrap">
                    <SegmentedControl
                        size="xs"
                        value={mode}
                        onValueChange={(event) => handleModeChange(event.value)}
                        items={modes}
                    />

                    {mode === 'task' && (
                        <HStack gap={2} flexWrap="wrap">
                            <NativeSelectRoot size="xs" width="150px">
                                <NativeSelectField value={due} onChange={(e) => setDue(e.target.value)}>
                                    {DUE_PRESETS.map((preset) => (
                                        <option key={preset.key} value={preset.key}>{preset.label}</option>
                                    ))}
                                    <option value="">Без срока</option>
                                </NativeSelectField>
                            </NativeSelectRoot>

                            <NativeSelectRoot size="xs" width="180px">
                                <NativeSelectField
                                    value={assignee}
                                    onChange={(e) => setAssignee(e.target.value)}
                                >
                                    <option value="">Исполнитель: я</option>
                                    {(taskOptions?.assignees || []).map((user) => (
                                        <option key={user.id} value={user.id}>{user.name}</option>
                                    ))}
                                </NativeSelectField>
                            </NativeSelectRoot>

                            <Button size="xs" variant="ghost" onClick={onFullTask} title="Все поля задачи">
                                <LuSettings2 />
                            </Button>
                        </HStack>
                    )}

                    {mode === 'comment' && (
                        <Checkbox
                            size="sm"
                            checked={pinned}
                            onCheckedChange={(e) => setPinned(!!e.checked)}
                        >
                            <Text fontSize="xs">Закрепить</Text>
                        </Checkbox>
                    )}
                </HStack>

                <HStack align="end" gap={2}>
                    <Box flex="1">
                        <VoiceTextarea
                            value={text}
                            onChange={setText}
                            rows={mode === 'task' ? 1 : 2}
                            placeholder={placeholder}
                            onKeyDown={(event) => {
                                const hotkey = mode === 'task'
                                    ? event.key === 'Enter' && !event.shiftKey
                                    : event.key === 'Enter' && (event.ctrlKey || event.metaKey);

                                if (hotkey) {
                                    event.preventDefault();
                                    submit();
                                }
                            }}
                        />
                    </Box>

                    {canFile && (
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => fileInput.current?.click()}
                            title="Приложить файл"
                            disabled={sending}
                        >
                            <LuPaperclip />
                        </Button>
                    )}

                    <Button
                        size="sm"
                        onClick={submit}
                        loading={sending || busy}
                        disabled={!text.trim()}
                    >
                        <LuSend /> {mode === 'task' ? 'Поставить' : 'Отправить'}
                    </Button>
                </HStack>
            </VStack>

            <input
                ref={fileInput}
                type="file"
                multiple
                hidden
                onChange={(event) => submitFile(Array.from(event.target.files || []))}
            />
        </Box>
    );
}
