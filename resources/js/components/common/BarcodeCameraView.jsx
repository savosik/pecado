import { useEffect, useRef, useState } from 'react';
import { Box, Flex, Spinner, Stack, Text } from '@chakra-ui/react';

/**
 * BarcodeCameraView — общий компонент сканера штрихкодов через камеру устройства.
 *
 * Использует lazy-import @zxing/browser, чтобы не утяжелять основной бандл.
 * При успешном распознавании вызывает onScan(text). Один и тот же штрихкод
 * не отдаётся чаще, чем раз в DEDUP_WINDOW_MS.
 *
 * @param {object} props
 * @param {(text: string) => void} props.onScan — колбэк с распознанным штрихкодом.
 * @param {boolean} [props.paused=false] — приостановить распознавание (например, на время запроса).
 * @param {string|number} [props.height='100%'] — высота видео-области.
 */
const DEDUP_WINDOW_MS = 1500;

export default function BarcodeCameraView({ onScan, paused = false, height = '100%' }) {
    const videoRef = useRef(null);
    const onScanRef = useRef(onScan);
    const lastScanRef = useRef({ text: '', at: 0 });
    const [status, setStatus] = useState('starting'); // 'starting' | 'scanning' | 'error'
    const [error, setError] = useState('');

    // Держим актуальный колбэк без перезапуска камеры при смене ссылки
    onScanRef.current = onScan;

    useEffect(() => {
        if (paused) return undefined;

        let cancelled = false;
        let controls = null;

        const start = async () => {
            try {
                const { BrowserMultiFormatReader } = await import('@zxing/browser');
                if (cancelled) return;

                const reader = new BrowserMultiFormatReader();

                controls = await reader.decodeFromVideoDevice(
                    undefined,
                    videoRef.current,
                    (result) => {
                        if (!result) return;
                        const text = typeof result.getText === 'function'
                            ? result.getText()
                            : String(result);
                        const now = Date.now();
                        if (
                            text === lastScanRef.current.text
                            && now - lastScanRef.current.at < DEDUP_WINDOW_MS
                        ) {
                            return;
                        }
                        lastScanRef.current = { text, at: now };
                        onScanRef.current?.(text);
                    },
                );

                if (cancelled) {
                    controls.stop();
                    return;
                }
                setStatus('scanning');
            } catch (err) {
                if (cancelled) return;
                console.error('BarcodeCameraView error:', err);
                setStatus('error');
                if (err?.name === 'NotAllowedError' || err?.name === 'SecurityError') {
                    setError('Доступ к камере запрещён. Разрешите его в настройках браузера.');
                } else if (err?.name === 'NotFoundError' || err?.name === 'OverconstrainedError') {
                    setError('Камера не найдена.');
                } else if (err?.name === 'NotReadableError') {
                    setError('Камера занята другим приложением.');
                } else {
                    setError('Не удалось запустить камеру.');
                }
            }
        };

        start();

        return () => {
            cancelled = true;
            if (controls?.stop) controls.stop();
            controls = null;
        };
    }, [paused]);

    return (
        <Box position="relative" w="100%" h={height} bg="black" overflow="hidden">
            {/* eslint-disable-next-line jsx-a11y/media-has-caption */}
            <video
                ref={videoRef}
                autoPlay
                playsInline
                muted
                style={{ width: '100%', height: '100%', objectFit: 'cover' }}
            />

            {status === 'starting' && (
                <Flex position="absolute" inset="0" align="center" justify="center" bg="blackAlpha.500">
                    <Stack align="center" gap="2">
                        <Spinner color="white" />
                        <Text color="white" fontSize="sm">Запуск камеры…</Text>
                    </Stack>
                </Flex>
            )}

            {status === 'scanning' && (
                <Flex position="absolute" inset="0" align="center" justify="center" pointerEvents="none">
                    <Box
                        borderWidth="2px"
                        borderColor="green.300"
                        rounded="lg"
                        w={{ base: '85%', md: '60%' }}
                        h={{ base: '40%', md: '35%' }}
                        position="relative"
                        boxShadow="0 0 0 9999px rgba(0,0,0,0.35)"
                    >
                        <Box
                            position="absolute"
                            left="0"
                            right="0"
                            top="50%"
                            h="2px"
                            bg="green.300"
                            opacity="0.85"
                        />
                    </Box>
                </Flex>
            )}

            {status === 'error' && (
                <Flex position="absolute" inset="0" align="center" justify="center" bg="blackAlpha.700" p="4">
                    <Text color="red.300" fontSize="sm" textAlign="center">{error}</Text>
                </Flex>
            )}
        </Box>
    );
}
