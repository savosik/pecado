import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { Box, HStack, IconButton, Text, VStack } from '@chakra-ui/react';
import { LuMic, LuSquare, LuTrash2 } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { Tooltip } from '@/components/ui/tooltip';
import { toastError, toastSuccess } from '@/utils/toast';

/** Предел длительности — тот же, что в CrmAttachments::VOICE_MAX_SECONDS. */
const MAX_SECONDS = 300;

const clock = (seconds) => `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;

/**
 * Голосовое досье: надиктовать и тут же прослушать.
 *
 * Появилось потому, что браузерное распознавание речи на живой речи с товарными
 * наименованиями и жаргоном отдела даёт текст, который дольше править, чем
 * набрать. Микрофоны при этом остались — там, где надиктовывается короткая
 * фраза, распознавание работает; здесь другой инструмент, а не замена.
 *
 * Длительность ограничивается до отправки, а не после: отказ после
 * десятиминутной записи по мобильному интернету — худший из возможных.
 */
export default function VoiceNotes({ entityType, entityId, canCreate = true, compact = false }) {
    const [notes, setNotes] = useState([]);
    const [recording, setRecording] = useState(false);
    const [seconds, setSeconds] = useState(0);
    const [busy, setBusy] = useState(false);
    const [supported, setSupported] = useState(true);

    const recorder = useRef(null);
    const chunks = useRef([]);
    const ticker = useRef(null);
    const stream = useRef(null);
    // Длительность — в ref, а не только в состоянии: обработчик onstop
    // назначается один раз при старте и замыкает upload из того рендера,
    // где seconds ещё равен нулю. Из состояния уходил бы всегда 0.
    const secondsRef = useRef(0);

    const load = async () => {
        try {
            const { data } = await axios.get(route('crm.attachments.index'), {
                params: { entity_type: entityType, entity_id: entityId, kind: 'voice' },
            });
            setNotes(data.data ?? []);
        } catch {
            // Молча: пустой блок голосовых — не повод для красной плашки
            // поверх карточки партнёра.
        }
    };

    useEffect(() => {
        setSupported(typeof window !== 'undefined'
            && !! navigator?.mediaDevices?.getUserMedia
            && typeof window.MediaRecorder !== 'undefined');
        load();

        return () => stopTracks();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [entityType, entityId]);

    const stopTracks = () => {
        if (ticker.current) window.clearInterval(ticker.current);
        stream.current?.getTracks().forEach((track) => track.stop());
        stream.current = null;
    };

    const start = async () => {
        try {
            stream.current = await navigator.mediaDevices.getUserMedia({ audio: true });
        } catch {
            toastError('Не удалось получить доступ к микрофону. Проверьте разрешения браузера.');

            return;
        }

        chunks.current = [];
        // Контейнер выбирает браузер: Chromium пишет webm/opus, Safari — mp4.
        // Навязывать тип нельзя, иначе на маке запись не стартует вовсе.
        recorder.current = new MediaRecorder(stream.current);
        recorder.current.ondataavailable = (event) => {
            if (event.data.size > 0) chunks.current.push(event.data);
        };
        recorder.current.onstop = () => upload(recorder.current?.mimeType);
        recorder.current.start();

        secondsRef.current = 0;
        setSeconds(0);
        setRecording(true);

        ticker.current = window.setInterval(() => {
            setSeconds((prev) => {
                const next = prev + 1;

                secondsRef.current = Math.min(next, MAX_SECONDS);

                if (next >= MAX_SECONDS) {
                    stop();

                    return MAX_SECONDS;
                }

                return next;
            });
        }, 1000);
    };

    const stop = () => {
        if (recorder.current?.state === 'recording') {
            recorder.current.stop();
        }

        setRecording(false);
        stopTracks();
    };

    const upload = async (mimeType) => {
        const blob = new Blob(chunks.current, { type: mimeType || 'audio/webm' });

        if (blob.size === 0) {
            return;
        }

        const extension = (mimeType || 'audio/webm').includes('mp4') ? 'mp4' : 'webm';
        const form = new FormData();

        form.append('entity_type', entityType);
        form.append('entity_id', entityId);
        form.append('kind', 'voice');
        form.append('duration_seconds', String(secondsRef.current));
        form.append('file', blob, `voice-${Date.now()}.${extension}`);

        setBusy(true);

        try {
            await axios.post(route('crm.attachments.store'), form);
            toastSuccess('Голосовая заметка сохранена.');
            await load();
        } catch (error) {
            toastError(error.response?.data?.errors?.file?.[0] ?? 'Не удалось сохранить запись.');
        } finally {
            setBusy(false);
        }
    };

    const remove = async (note) => {
        try {
            await axios.delete(route('crm.attachments.destroy', note.id));
            setNotes((prev) => prev.filter((item) => item.id !== note.id));
        } catch {
            toastError('Не удалось удалить запись.');
        }
    };

    if (! supported && notes.length === 0) {
        return null;
    }

    return (
        <VStack align="stretch" gap={2}>
            {canCreate && supported && (
                <HStack gap={2}>
                    {recording ? (
                        <>
                            <Button size="xs" colorPalette="red" onClick={stop}>
                                <LuSquare /> Остановить
                            </Button>
                            <Text fontSize="xs" color="fg.muted" fontFamily="mono">
                                {clock(seconds)} / {clock(MAX_SECONDS)}
                            </Text>
                        </>
                    ) : (
                        <Tooltip content="Надиктовать заметку о клиенте" openDelay={400}>
                            <Button size="xs" variant="outline" onClick={start} loading={busy}>
                                <LuMic /> Записать голосом
                            </Button>
                        </Tooltip>
                    )}
                </HStack>
            )}

            {notes.map((note) => (
                <HStack key={note.id} gap={2} align="center">
                    {/* Проигрывается тут же: скачивать заметку, чтобы её послушать,
                        никто не будет.

                        MediaRecorder пишет WebM потоком и не проставляет в заголовок
                        длительность — браузер показывает 0:00 и не даёт перемотку.
                        Лечится принудительной перемоткой в заведомо недостижимую
                        точку: браузер досчитывает длину сам и возвращает корректный
                        duration. Приём применяем один раз на загрузку метаданных. */}
                    <Box
                        as="audio"
                        controls
                        preload="metadata"
                        src={note.url}
                        flex="1"
                        maxW={compact ? '260px' : '420px'}
                        h="32px"
                        onLoadedMetadata={(event) => {
                            const el = event.currentTarget;

                            if (el.duration !== Infinity && ! Number.isNaN(el.duration)) {
                                return;
                            }

                            const restore = () => {
                                el.removeEventListener('timeupdate', restore);
                                el.currentTime = 0;
                            };

                            el.addEventListener('timeupdate', restore);
                            el.currentTime = 1e101;
                        }}
                    />
                    <VStack align="start" gap={0} minW="120px">
                        <Text fontSize="10px" color="fg.muted">
                            {note.uploaded_by} · {note.uploaded_at}
                        </Text>
                        {note.duration_label && (
                            <Text fontSize="10px" color="fg.muted" fontFamily="mono">{note.duration_label}</Text>
                        )}
                    </VStack>
                    {note.can_delete && (
                        <IconButton
                            size="xs"
                            variant="ghost"
                            aria-label="Удалить запись"
                            onClick={() => remove(note)}
                        >
                            <LuTrash2 />
                        </IconButton>
                    )}
                </HStack>
            ))}
        </VStack>
    );
}
