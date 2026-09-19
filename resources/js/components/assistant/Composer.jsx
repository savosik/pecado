import { useCallback, useEffect, useRef, useState } from 'react';
import { Box, HStack, IconButton, Text, Textarea } from '@chakra-ui/react';
import { LuMic, LuMicOff, LuPaperclip, LuSend, LuX } from 'react-icons/lu';
import { useAssistantStore } from '@/stores/useAssistantStore';
import { useSpeechInput } from './useSpeechInput';

const formatSize = (bytes) => (bytes > 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} МБ` : `${Math.ceil(bytes / 1024)} КБ`);

/**
 * Поле ввода: текст, скрепка, перетаскивание, вставка скриншота, микрофон.
 * Отправка — только кнопкой или Enter; голос кладёт текст в поле.
 */
export default function Composer({ prefill = null, voice = true, attachments = {} }) {
    const [text, setText] = useState('');
    const [interim, setInterim] = useState('');
    const [dragging, setDragging] = useState(false);
    const inputRef = useRef(null);
    const fileRef = useRef(null);

    const busy = useAssistantStore((s) => s.busy);
    const quota = useAssistantStore((s) => s.quota);
    const thread = useAssistantStore((s) => s.thread);
    const pending = useAssistantStore((s) => s.pendingAttachments);
    const uploading = useAssistantStore((s) => s.uploading);
    const send = useAssistantStore((s) => s.send);
    const upload = useAssistantStore((s) => s.upload);
    const removeAttachment = useAssistantStore((s) => s.removeAttachment);
    const event = useAssistantStore((s) => s.event);

    useEffect(() => {
        if (prefill) {
            setText(prefill);
            inputRef.current?.focus();
        }
    }, [prefill]);

    const speech = useSpeechInput((chunk, final) => {
        if (final) {
            setText((t) => (t ? `${t} ${chunk}` : chunk).trim());
            setInterim('');
            event('voice_used');
        } else {
            setInterim(chunk);
        }
    });

    const closed = thread && thread.status !== 'open';
    const disabled = busy || Boolean(quota) || closed || !thread;
    const canSend = !disabled && (text.trim() !== '' || pending.length > 0) && !uploading;

    const submit = useCallback(async () => {
        if (!canSend) return;
        speech.stop();
        const value = text.trim();
        setText('');
        setInterim('');
        const ok = await send(value);
        if (!ok) setText(value);
        else inputRef.current?.focus();
    }, [canSend, send, speech, text]);

    const onKeyDown = (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            submit();
        }
    };

    const takeFiles = useCallback((list) => {
        const files = Array.from(list || []);
        const room = Math.max(0, (attachments.max_per_message || 5) - pending.length);
        files.slice(0, room).forEach((file) => {
            if (file.size > (attachments.max_size_kb || 20480) * 1024) {
                useAssistantStore.setState({ error: `Файл «${file.name}» больше ${Math.round((attachments.max_size_kb || 20480) / 1024)} МБ.` });
                return;
            }
            if (attachments.mimes?.length && file.type && !attachments.mimes.includes(file.type)) {
                const ext = file.name.includes('.') ? `.${file.name.split('.').pop()}` : file.type;
                useAssistantStore.setState({ error: `Формат ${ext} не поддерживается. Подойдут фото, PDF, Excel, CSV и Word.` });
                return;
            }
            upload(file);
        });
    }, [attachments, pending.length, upload]);

    const onPaste = (e) => {
        const items = Array.from(e.clipboardData?.items || []).filter((i) => i.kind === 'file');
        if (items.length) {
            e.preventDefault();
            takeFiles(items.map((i) => i.getAsFile()).filter(Boolean));
        }
    };

    const onDrop = (e) => {
        e.preventDefault();
        setDragging(false);
        takeFiles(e.dataTransfer?.files);
    };

    const placeholder = closed
        ? 'Разговор закрыт — начните новый'
        : quota
            ? quota.message
            : speech.listening
                ? 'Слушаю…'
                : 'Спросите о ценах, заказе, документах или долге';

    return (
        <Box
            borderTopWidth="1px"
            borderColor="border.muted"
            p="2"
            bg={dragging ? 'pecado.50' : 'bg'}
            onDragOver={(e) => { e.preventDefault(); setDragging(true); }}
            onDragLeave={() => setDragging(false)}
            onDrop={onDrop}
        >
            {pending.length > 0 && (
                <HStack gap="2" flexWrap="wrap" mb="2">
                    {pending.map((a) => (
                        <HStack key={a.id} gap="1" bg="bg.muted" borderRadius="md" px="2" py="1" fontSize="xs">
                            <LuPaperclip size={11} />
                            <Text maxW="160px" truncate>{a.name}</Text>
                            <Text color="fg.subtle">{formatSize(a.size)}</Text>
                            <IconButton size="2xs" variant="ghost" aria-label="Убрать файл" onClick={() => removeAttachment(a.id)}>
                                <LuX />
                            </IconButton>
                        </HStack>
                    ))}
                </HStack>
            )}
            <HStack align="flex-end" gap="1">
                <input
                    ref={fileRef}
                    type="file"
                    multiple
                    hidden
                    accept={(attachments.mimes || []).join(',')}
                    onChange={(e) => { takeFiles(e.target.files); e.target.value = ''; }}
                />
                <IconButton
                    variant="ghost"
                    size="sm"
                    aria-label="Прикрепить файл"
                    title="Прикрепить файл: фото, PDF, Excel, CSV"
                    onClick={() => fileRef.current?.click()}
                    disabled={disabled || uploading}
                >
                    <LuPaperclip />
                </IconButton>
                <Textarea
                    ref={inputRef}
                    value={interim ? `${text}${text ? ' ' : ''}${interim}` : text}
                    onChange={(e) => { setInterim(''); setText(e.target.value); }}
                    onKeyDown={onKeyDown}
                    onPaste={onPaste}
                    placeholder={placeholder}
                    disabled={disabled}
                    autoresize
                    maxH="140px"
                    rows={1}
                    fontSize="sm"
                    resize="none"
                />
                {voice && speech.supported && (
                    <IconButton
                        variant={speech.listening ? 'solid' : 'ghost'}
                        colorPalette={speech.listening ? 'red' : 'gray'}
                        size={{ base: 'md', md: 'sm' }}
                        aria-label={speech.listening ? 'Остановить запись' : 'Голосовой ввод'}
                        title="Скажите, что нужно — текст появится в поле"
                        onClick={speech.toggle}
                        disabled={disabled}
                    >
                        {speech.listening ? <LuMicOff /> : <LuMic />}
                    </IconButton>
                )}
                <IconButton colorPalette="pecado" size="sm" aria-label="Отправить" onClick={submit} disabled={!canSend}>
                    <LuSend />
                </IconButton>
            </HStack>
            {uploading && (
                <Text fontSize="xs" color="fg.subtle" mt="1">Загружаю файл…</Text>
            )}
        </Box>
    );
}
