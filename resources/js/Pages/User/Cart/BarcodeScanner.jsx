import { useState, useRef, useEffect, useCallback } from 'react';
import {
    Box, Flex, Button, Input, Text, Badge, Stack, Alert,
    IconButton, Code,
} from '@chakra-ui/react';
import { Checkbox } from '@/components/ui/checkbox';
import {
    LuScanBarcode, LuCamera, LuKeyboard,
    LuPlus, LuLoader, LuVolume2, LuCircleCheck, LuTrash2,
    LuZap, LuHistory, LuTriangleAlert,
} from 'react-icons/lu';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { toastSuccess, toastError, toastInfo } from '@/utils/toast';
import BarcodeCameraView from '@/components/common/BarcodeCameraView';

/**
 * Компонент сканера штрихкодов.
 * Три режима: физический сканер (keyboard), камера (camera), ручной ввод (manual).
 *
 * @param {{ onClose: () => void, onSuccess?: (data: any) => void }} props
 */
export default function BarcodeScanner({ onClose, onSuccess }) {
    const [scanMode, setScanMode] = useState('keyboard'); // 'keyboard' | 'camera' | 'manual'
    const [manualInput, setManualInput] = useState('');
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);
    const [lastScanned, setLastScanned] = useState('');
    const [continuousMode, setContinuousMode] = useState(true);
    const [soundEnabled, setSoundEnabled] = useState(true);
    const [scanHistory, setScanHistory] = useState([]);
    const [keyboardBuffer, setKeyboardBuffer] = useState('');
    const [lastKeyTime, setLastKeyTime] = useState(0);

    const keyboardInputRef = useRef(null);
    const manualInputRef = useRef(null);
    const audioContextRef = useRef(null);

    // ── Звуковые сигналы ──
    const playBeep = useCallback((type = 'success') => {
        if (!soundEnabled) return;

        try {
            if (!audioContextRef.current) {
                audioContextRef.current = new (window.AudioContext || window.webkitAudioContext)();
            }

            const ctx = audioContextRef.current;
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();

            osc.connect(gain);
            gain.connect(ctx.destination);

            if (type === 'success') {
                osc.frequency.setValueAtTime(800, ctx.currentTime);
                gain.gain.setValueAtTime(0.1, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.1);
                osc.start(ctx.currentTime);
                osc.stop(ctx.currentTime + 0.1);
            } else {
                osc.frequency.setValueAtTime(300, ctx.currentTime);
                gain.gain.setValueAtTime(0.1, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.3);
                osc.start(ctx.currentTime);
                osc.stop(ctx.currentTime + 0.3);
            }
        } catch (err) {
            console.warn('Не удалось воспроизвести звук:', err);
        }
    }, [soundEnabled]);

    // ── Обработка клавиатуры (физический сканер) ──
    const handleKeyboardInput = useCallback(
        (event) => {
            if (scanMode !== 'keyboard') return;

            const currentTime = Date.now();
            const timeDiff = currentTime - lastKeyTime;

            // Если между символами > 100мс — новый штрихкод
            if (timeDiff > 100) {
                setKeyboardBuffer('');
            }

            setLastKeyTime(currentTime);

            if (event.key === 'Enter') {
                event.preventDefault();
                const barcode = keyboardBuffer.trim();
                if (barcode) {
                    handleAddByBarcode(barcode);
                }
                setKeyboardBuffer('');
            } else if (event.key.length === 1) {
                setKeyboardBuffer((prev) => prev + event.key);
            }
        },
        [scanMode, keyboardBuffer, lastKeyTime],
    );

    // ── Добавление товара по штрихкоду ──
    const handleAddByBarcode = async (barcode) => {
        if (!barcode.trim()) {
            setError('Введите штрихкод');
            return;
        }

        setLoading(true);
        setError('');

        try {
            const { data } = await axios.post('/api/cart/add-by-barcode', {
                barcode: barcode.trim(),
                quantity: 1,
            });

            if (data.status === 'success' || data.status === 'partial') {
                playBeep('success');

                if (data.status === 'partial') {
                    toastInfo('Частично добавлено', data.message);
                } else {
                    toastSuccess(
                        'Товар добавлен',
                        data.product_name || 'Товар добавлен в корзину',
                    );
                }

                setLastScanned(barcode);

                // История
                const historyItem = {
                    barcode,
                    productName: data.product_name || 'Товар',
                    productId: data.product_id,
                    status: data.status,
                    timestamp: new Date(),
                };
                setScanHistory((prev) => [historyItem, ...prev.slice(0, 9)]);

                // Перезагрузить данные корзины
                router.reload({ only: ['cartDetails'], preserveScroll: true });

                onSuccess?.(data);
                setManualInput('');

                if (!continuousMode) {
                    setTimeout(() => onClose?.(), 2000);
                }

                // Возврат фокуса
                if (scanMode === 'manual' && manualInputRef.current) {
                    setTimeout(() => manualInputRef.current?.focus(), 100);
                }
            } else if (data.status === 'warning') {
                playBeep('error');
                setError(data.message || 'Достигнут максимум для этого товара');

                // В историю — как предупреждение
                const historyItem = {
                    barcode,
                    productName: data.product_name || 'Товар',
                    productId: data.product_id,
                    status: 'warning',
                    timestamp: new Date(),
                };
                setScanHistory((prev) => [historyItem, ...prev.slice(0, 9)]);

                setManualInput('');

                // Возврат фокуса
                if (scanMode === 'manual' && manualInputRef.current) {
                    setTimeout(() => manualInputRef.current?.focus(), 100);
                }
            } else {
                playBeep('error');
                setError(data.message || 'Ошибка при добавлении товара');
            }
        } catch (err) {
            playBeep('error');
            if (err.response?.status === 404) {
                setError(err.response.data?.message || 'Товар с таким штрихкодом не найден.');
            } else {
                setError('Произошла ошибка при добавлении товара.');
            }
        } finally {
            setLoading(false);
        }
    };

    // ── Обработка формы ──
    const handleManualSubmit = (e) => {
        e.preventDefault();
        handleAddByBarcode(manualInput);
    };

    // ── Переключение режима ──
    const setScanModeAndCleanup = (newMode) => {
        setScanMode(newMode);
        setError('');

        if (newMode === 'keyboard') {
            setTimeout(() => keyboardInputRef.current?.focus(), 100);
        }
    };

    // ── Effects ──
    useEffect(() => {
        if (scanMode === 'keyboard') {
            setTimeout(() => keyboardInputRef.current?.focus(), 100);
        }
    }, []);

    useEffect(() => {
        if (scanMode === 'keyboard') {
            document.addEventListener('keydown', handleKeyboardInput);
            return () => document.removeEventListener('keydown', handleKeyboardInput);
        }
    }, [handleKeyboardInput, scanMode]);

    useEffect(() => {
        return () => {
            if (audioContextRef.current) {
                audioContextRef.current.close();
            }
        };
    }, []);

    const modeButtons = [
        { mode: 'keyboard', icon: <LuScanBarcode size={16} />, label: 'Сканер' },
        { mode: 'camera', icon: <LuCamera size={16} />, label: 'Камера' },
        { mode: 'manual', icon: <LuKeyboard size={16} />, label: 'Ручной ввод' },
    ];

    return (
        <Stack gap="4">
            {/* Переключатель режимов */}
            <Flex gap="2" wrap="wrap">
                {modeButtons.map(({ mode, icon, label }) => (
                    <Button
                        key={mode}
                        variant={scanMode === mode ? 'solid' : 'outline'}
                        colorPalette={scanMode === mode ? 'pecado' : undefined}
                        size="sm"
                        onClick={() => setScanModeAndCleanup(mode)}
                        disabled={loading}
                    >
                        {icon}
                        {label}
                    </Button>
                ))}
            </Flex>

            {/* Настройки */}
            <Box p="3" bg="bg.muted" rounded="md">
                <Stack gap="3">
                    <Checkbox
                        checked={continuousMode}
                        onCheckedChange={(e) => setContinuousMode(!!e.checked)}
                    >
                        <Text fontSize="sm" fontWeight="medium">Непрерывное сканирование</Text>
                    </Checkbox>
                    <Checkbox
                        checked={soundEnabled}
                        onCheckedChange={(e) => setSoundEnabled(!!e.checked)}
                    >
                        <Text fontSize="sm" fontWeight="medium">Звуковые сигналы</Text>
                    </Checkbox>
                </Stack>
            </Box>

            {/* ─── Режим: физический сканер ─── */}
            {scanMode === 'keyboard' && (
                <Box>
                    <Box
                        textAlign="center"
                        p="8"
                        bg="bg.subtle"
                        rounded="md"
                        borderWidth="2px"
                        borderStyle="dashed"
                        borderColor="border.emphasized"
                    >
                        <Box mb="4" color="fg.muted" animation="pulse 2s infinite">
                            <LuScanBarcode size={64} style={{ margin: '0 auto' }} />
                        </Box>
                        <Text fontSize="lg" fontWeight="semibold" mb="2">
                            Готов к сканированию
                        </Text>
                        <Text fontSize="sm" color="fg.muted" mb="2">
                            Направьте сканер на штрихкод товара
                        </Text>
                        {keyboardBuffer && (
                            <Box mt="3" p="2" bg="bg" rounded="md" borderWidth="1px" borderColor="border">
                                <Code fontSize="sm">{keyboardBuffer}</Code>
                            </Box>
                        )}
                        <Badge mt="2" variant="subtle" colorPalette="blue">
                            <LuZap size={14} />
                            Быстрое сканирование
                        </Badge>
                    </Box>

                    {/* Скрытое поле для фокуса */}
                    <input
                        ref={keyboardInputRef}
                        type="text"
                        style={{ position: 'absolute', opacity: 0, width: 0, height: 0 }}
                        tabIndex={-1}
                        aria-hidden="true"
                    />
                </Box>
            )}

            {/* ─── Режим: камера ─── */}
            {scanMode === 'camera' && (
                <Stack gap="2">
                    <Box rounded="md" overflow="hidden" style={{ aspectRatio: '16/9' }}>
                        <BarcodeCameraView
                            onScan={handleAddByBarcode}
                            paused={loading}
                            height="100%"
                        />
                    </Box>
                    <Text fontSize="sm" color="fg.muted" textAlign="center">
                        Направьте камеру на штрихкод товара
                    </Text>
                </Stack>
            )}

            {/* ─── Режим: ручной ввод ─── */}
            {scanMode === 'manual' && (
                <form onSubmit={handleManualSubmit}>
                    <Stack gap="3">
                        <Input
                            ref={manualInputRef}
                            type="text"
                            placeholder="Введите штрихкод"
                            value={manualInput}
                            onChange={(e) => setManualInput(e.target.value)}
                            disabled={loading}
                            autoFocus
                        />
                        <Button
                            type="submit"
                            colorPalette="pecado"
                            w="100%"
                            disabled={loading || !manualInput.trim()}
                        >
                            {loading ? (
                                <>
                                    <LuLoader size={16} className="animate-spin" />
                                    Добавление...
                                </>
                            ) : (
                                <>
                                    <LuPlus size={16} />
                                    Добавить в корзину
                                </>
                            )}
                        </Button>
                    </Stack>
                </form>
            )}

            {/* Ошибка */}
            {error && (
                <Alert.Root status="error">
                    <Alert.Indicator />
                    <Alert.Title>{error}</Alert.Title>
                </Alert.Root>
            )}

            {/* История сканирования */}
            {scanHistory.length > 0 && (
                <Stack gap="2">
                    <Flex align="center" gap="2">
                        <LuHistory size={16} />
                        <Text fontSize="sm" fontWeight="medium">История сканирований</Text>
                    </Flex>
                    <Box maxH="32" overflowY="auto">
                        <Stack gap="1">
                            {scanHistory.map((item, idx) => (
                                <Flex
                                    key={idx}
                                    align="center"
                                    gap="2"
                                    p="2"
                                    bg="bg.muted"
                                    rounded="md"
                                    fontSize="xs"
                                >
                                    {item.status === 'warning' ? (
                                        <LuTriangleAlert size={14} color="orange" style={{ flexShrink: 0 }} />
                                    ) : (
                                        <LuCircleCheck size={14} color="green" style={{ flexShrink: 0 }} />
                                    )}
                                    <Code fontSize="xs" flexShrink={0}>{item.barcode}</Code>
                                    <Text truncate flex="1" minW="0">{item.productName}</Text>
                                </Flex>
                            ))}
                        </Stack>
                    </Box>
                    <Button
                        variant="ghost"
                        size="xs"
                        w="100%"
                        onClick={() => setScanHistory([])}
                    >
                        <LuTrash2 size={12} />
                        Очистить историю
                    </Button>
                </Stack>
            )}


            {/* Подсказки */}
            <Box textAlign="center" fontSize="xs" color="fg.muted">
                {scanMode === 'keyboard' && (
                    <>
                        <Text>🔫 Направьте физический сканер на штрихкод и нажмите триггер</Text>
                        <Text>⚡ В непрерывном режиме сканируйте несколько товаров подряд</Text>
                    </>
                )}
                {scanMode === 'camera' && (
                    <>
                        <Text>💡 Совет: обеспечьте хорошее освещение для лучшего распознавания</Text>
                        <Text>📱 На мобильных устройствах разрешите доступ к камере</Text>
                    </>
                )}
                {scanMode === 'manual' && (
                    <Text>⌨️ Введите штрихкод вручную и нажмите Enter или кнопку</Text>
                )}
            </Box>
        </Stack>
    );
}
