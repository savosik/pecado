import { useCallback, useEffect, useRef, useState } from 'react';
import { Box, Flex, HStack, NativeSelect, Spinner, Text } from '@chakra-ui/react';
import { LuCamera, LuCameraOff, LuRefreshCw } from 'react-icons/lu';
import { Button } from '@/components/ui/button';

/**
 * WebcamPhotoCapture — съёмка фото подключённой веб-камерой прямо на странице.
 *
 * На складе к компьютеру подключена USB-камера: кладовщику нужно сфотографировать
 * товар, не отходя к телефону. Камера поднимается сама при монтировании
 * (autoStart), кадр снимается в canvas и отдаётся наверх готовым File — дальше
 * он идёт тем же путём, что и файл с диска.
 *
 * По умолчанию компонент показывается только на «мышиных» устройствах
 * (pointer: fine): на телефоне для этого есть кнопка съёмки в загрузчике файлов.
 *
 * @param {(file: File) => void} onCapture — колбэк со снятым кадром.
 * @param {boolean} [autoStart=true] — поднимать камеру сразу при открытии страницы.
 * @param {boolean} [desktopOnly=true] — не показывать на тач-устройствах.
 * @param {boolean} [disabled=false] — заблокировать съёмку (например, идёт сохранение).
 * @param {string|number} [height='240px'] — высота видео-области.
 */
export default function WebcamPhotoCapture({
    onCapture,
    autoStart = true,
    desktopOnly = true,
    disabled = false,
    height = '240px',
}) {
    const videoRef = useRef(null);
    const streamRef = useRef(null);
    const onCaptureRef = useRef(onCapture);
    onCaptureRef.current = onCapture;

    const [supported, setSupported] = useState(false);
    const [visible, setVisible] = useState(false);
    const [status, setStatus] = useState('idle'); // 'idle' | 'starting' | 'live' | 'error'
    const [error, setError] = useState('');
    const [devices, setDevices] = useState([]);
    const [deviceId, setDeviceId] = useState('');
    const [shot, setShot] = useState(0);

    // Проверки среды делаем в эффекте: getUserMedia есть только в secure context
    // (https/localhost), а matchMedia — только в браузере.
    useEffect(() => {
        const hasApi = typeof navigator !== 'undefined'
            && !!navigator.mediaDevices?.getUserMedia;
        const isDesktop = !desktopOnly
            || (typeof window !== 'undefined' && window.matchMedia?.('(pointer: fine)').matches);

        setSupported(hasApi);
        setVisible(hasApi && isDesktop);
    }, [desktopOnly]);

    const stop = useCallback(() => {
        streamRef.current?.getTracks().forEach((track) => track.stop());
        streamRef.current = null;
        if (videoRef.current) {
            videoRef.current.srcObject = null;
        }
        setStatus('idle');
    }, []);

    const start = useCallback(async (preferredDeviceId = '') => {
        if (!navigator.mediaDevices?.getUserMedia) return;

        setStatus('starting');
        setError('');

        try {
            // Прошлый поток гасим: две сессии одной камеры браузер не отдаст.
            streamRef.current?.getTracks().forEach((track) => track.stop());

            const stream = await navigator.mediaDevices.getUserMedia({
                video: preferredDeviceId
                    ? { deviceId: { exact: preferredDeviceId } }
                    : { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } },
                audio: false,
            });

            streamRef.current = stream;
            if (videoRef.current) {
                videoRef.current.srcObject = stream;
            }
            setStatus('live');

            // Метки устройств доступны только после выданного разрешения.
            const all = await navigator.mediaDevices.enumerateDevices();
            const cams = all.filter((device) => device.kind === 'videoinput');
            setDevices(cams);

            const activeId = stream.getVideoTracks()[0]?.getSettings?.().deviceId;
            setDeviceId(preferredDeviceId || activeId || cams[0]?.deviceId || '');
        } catch (err) {
            setStatus('error');
            if (err?.name === 'NotAllowedError' || err?.name === 'SecurityError') {
                setError('Доступ к камере запрещён. Разрешите его в настройках браузера.');
            } else if (err?.name === 'NotFoundError' || err?.name === 'OverconstrainedError') {
                setError('Камера не найдена.');
            } else if (err?.name === 'NotReadableError') {
                setError('Камера занята другим приложением или вкладкой.');
            } else {
                setError('Не удалось запустить камеру.');
            }
        }
    }, []);

    // Автостарт на открытии страницы + гарантированная остановка при уходе:
    // иначе индикатор камеры останется гореть после перехода на другую страницу.
    useEffect(() => {
        if (visible && autoStart) {
            start();
        }

        return () => {
            streamRef.current?.getTracks().forEach((track) => track.stop());
            streamRef.current = null;
        };
    }, [visible, autoStart, start]);

    const capture = () => {
        const video = videoRef.current;
        if (!video || status !== 'live' || disabled) return;

        const width = video.videoWidth;
        const height2 = video.videoHeight;
        if (!width || !height2) return;

        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height2;
        canvas.getContext('2d').drawImage(video, 0, 0, width, height2);

        canvas.toBlob((blob) => {
            if (!blob) return;
            const stamp = new Date().toISOString().replace(/[-:T.]/g, '').slice(0, 14);
            const file = new File([blob], `defect-${stamp}-${Math.random().toString(36).slice(2, 6)}.jpg`, {
                type: 'image/jpeg',
                lastModified: Date.now(),
            });
            onCaptureRef.current?.(file);
            setShot((n) => n + 1);
        }, 'image/jpeg', 0.9);
    };

    if (!visible) {
        return null;
    }

    return (
        <Box borderWidth="1px" borderColor="border.muted" borderRadius="md" overflow="hidden" mb={4}>
            <Box position="relative" bg="black" h={height}>
                {/* Субтитров нет намеренно: это живой поток камеры, а не медиаконтент. */}
                <video
                    ref={videoRef}
                    autoPlay
                    playsInline
                    muted
                    style={{ width: '100%', height: '100%', objectFit: 'contain' }}
                />

                {status === 'starting' && (
                    <Flex position="absolute" inset="0" align="center" justify="center" bg="blackAlpha.600" gap={2}>
                        <Spinner color="white" size="sm" />
                        <Text color="white" fontSize="sm">Запуск камеры…</Text>
                    </Flex>
                )}

                {status === 'idle' && (
                    <Flex position="absolute" inset="0" align="center" justify="center" bg="blackAlpha.600">
                        <Button size="sm" onClick={() => start(deviceId)}>
                            <LuCamera /> Включить камеру
                        </Button>
                    </Flex>
                )}

                {status === 'error' && (
                    <Flex position="absolute" inset="0" align="center" justify="center" direction="column" gap={2} bg="blackAlpha.700" p={4}>
                        <Text color="red.300" fontSize="sm" textAlign="center">{error}</Text>
                        <Button size="xs" variant="outline" colorPalette="gray" onClick={() => start(deviceId)}>
                            <LuRefreshCw /> Повторить
                        </Button>
                    </Flex>
                )}
            </Box>

            <Box p={2}>
                <HStack gap={2} flexWrap="wrap">
                    <Button
                        size="sm"
                        onClick={capture}
                        disabled={status !== 'live' || disabled}
                        flexShrink={0}
                    >
                        <LuCamera /> Сфотографировать
                    </Button>

                    {status === 'live' && (
                        <Button size="sm" variant="outline" onClick={stop} flexShrink={0}>
                            <LuCameraOff /> Выключить
                        </Button>
                    )}

                    {devices.length > 1 && (
                        <NativeSelect.Root size="sm" maxW="220px">
                            <NativeSelect.Field
                                value={deviceId}
                                onChange={(event) => {
                                    setDeviceId(event.target.value);
                                    start(event.target.value);
                                }}
                            >
                                {devices.map((device, index) => (
                                    <option key={device.deviceId} value={device.deviceId}>
                                        {device.label || `Камера ${index + 1}`}
                                    </option>
                                ))}
                            </NativeSelect.Field>
                            <NativeSelect.Indicator />
                        </NativeSelect.Root>
                    )}

                    {shot > 0 && (
                        <Text fontSize="xs" color="fg.muted">
                            Снято кадров: {shot}
                        </Text>
                    )}
                </HStack>

                {!supported && (
                    <Text fontSize="xs" color="fg.muted" mt={1}>
                        Браузер не даёт доступ к камере — загрузите фото файлом.
                    </Text>
                )}
            </Box>
        </Box>
    );
}
