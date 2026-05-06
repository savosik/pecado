import { useCallback, useState } from 'react';
import {
    Box, Flex, Text, Card, HStack, VStack, Badge, Button, Spinner,
    IconButton, SimpleGrid,
} from '@chakra-ui/react';
import { Head } from '@inertiajs/react';
import CabinetLayout from '../CabinetLayout';
import {
    LuFileCode, LuShoppingBag, LuGlobe, LuMessageCircle, LuSearch,
    LuPenTool, LuShoppingCart, LuStore, LuFileDown,
    LuCopy, LuCheck, LuLink, LuX, LuBraces, LuFileSpreadsheet,
    LuRefreshCw, LuTriangleAlert,
} from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';
import axios from 'axios';
import useExportStatus from '@/hooks/useExportStatus';

const presetIcons = {
    LuFileCode, LuShoppingBag, LuGlobe, LuMessageCircle,
    LuSearch, LuPenTool, LuShoppingCart, LuStore,
    LuBraces, LuFileSpreadsheet,
};

const isInProgress = (status) => status === 'queued' || status === 'generating';

function PresetCard({ preset, onGenerate, onDelete, onStatusUpdate, loadingKey }) {
    const IconComponent = presetIcons[preset.icon] || LuFileDown;
    const isLoading = loadingKey === preset.key;
    const inProgress = isInProgress(preset.status);
    const failed = preset.status === 'failed';
    const isReady = preset.generated && preset.download_url && preset.status === 'ready';

    const handleStatusUpdate = useCallback(
        (data) => onStatusUpdate(preset.key, data),
        [preset.key, onStatusUpdate],
    );

    useExportStatus(preset.key, inProgress, handleStatusUpdate);

    return (
        <Card.Root
            bg="bg"
            borderRadius="xl"
            border="1px solid"
            borderColor="border.muted"
            overflow="hidden"
            transition="all 0.2s"
            _hover={{ shadow: 'md', borderColor: '#9e1b32' }}
        >
            <Card.Body p="5">
                <VStack align="stretch" gap="3">
                    {/* Header */}
                    <HStack>
                        <Flex
                            align="center" justify="center" w="10" h="10" borderRadius="lg"
                            bg={`${preset.color}.50`} _dark={{ bg: `${preset.color}.900` }}
                            flexShrink="0"
                        >
                            <IconComponent size={20} color={`var(--chakra-colors-${preset.color}-500)`} />
                        </Flex>
                        <Box flex="1" minW="0">
                            <Text fontWeight="700" fontSize="sm" lineClamp={1}>{preset.name}</Text>
                            <Badge colorPalette={preset.color} variant="subtle" size="sm" mt="0.5">
                                .{preset.extension}
                            </Badge>
                        </Box>
                    </HStack>

                    {/* Description */}
                    <Text fontSize="xs" color="gray.500" lineHeight="1.5">
                        {preset.description}
                    </Text>

                    {/* Состояние: генерация */}
                    {inProgress && (
                        <HStack
                            bg="blue.50" _dark={{ bg: 'blue.900' }}
                            borderRadius="lg" px="3" py="2" gap="2"
                        >
                            <Spinner size="xs" color="blue.500" />
                            <Text fontSize="xs" color="blue.700" _dark={{ color: 'blue.200' }}>
                                Генерация в фоне…
                            </Text>
                        </HStack>
                    )}

                    {/* Состояние: ошибка */}
                    {failed && (
                        <VStack
                            align="stretch" gap="2"
                            bg="red.50" _dark={{ bg: 'red.900' }}
                            borderRadius="lg" px="3" py="2"
                        >
                            <HStack gap="2">
                                <LuTriangleAlert size={14} color="var(--chakra-colors-red-500)" />
                                <Text fontSize="xs" color="red.700" _dark={{ color: 'red.200' }} fontWeight="600">
                                    Не удалось сгенерировать
                                </Text>
                            </HStack>
                            {preset.last_run?.error_message && (
                                <Text fontSize="2xs" color="red.600" _dark={{ color: 'red.300' }} lineClamp={3}>
                                    {preset.last_run.error_message}
                                </Text>
                            )}
                        </VStack>
                    )}

                    {/* Состояние: готов */}
                    {isReady && (
                        <VStack align="stretch" gap="2">
                            <HStack
                                bg="green.50" _dark={{ bg: 'green.900' }}
                                borderRadius="lg" px="3" py="2"
                            >
                                <LuCheck size={14} color="green" />
                                <Text fontSize="xs" color="green.600" _dark={{ color: 'green.300' }} flex="1" truncate>
                                    {preset.download_url}
                                </Text>
                                <IconButton
                                    size="xs" variant="ghost" colorPalette="green"
                                    onClick={() => {
                                        navigator.clipboard.writeText(preset.download_url);
                                        toaster.create({ title: 'Ссылка скопирована', type: 'success' });
                                    }}
                                    aria-label="Копировать"
                                >
                                    <LuCopy />
                                </IconButton>
                            </HStack>
                            <HStack gap="2">
                                <Button
                                    flex="1" size="xs" variant="outline" colorPalette="green"
                                    onClick={() => window.open(preset.download_url, '_blank')}
                                >
                                    <LuFileDown /> Скачать
                                </Button>
                                <IconButton
                                    size="xs" variant="ghost" colorPalette="blue"
                                    onClick={() => onGenerate(preset.key)}
                                    aria-label="Пересобрать"
                                    title="Пересобрать выгрузку"
                                >
                                    <LuRefreshCw />
                                </IconButton>
                                <IconButton
                                    size="xs" variant="ghost" colorPalette="red"
                                    onClick={() => onDelete(preset.key)}
                                    aria-label="Удалить выгрузку"
                                >
                                    <LuX />
                                </IconButton>
                            </HStack>
                            {preset.cached_at && (
                                <Text fontSize="2xs" color="gray.400" textAlign="center">
                                    Обновлено: {new Date(preset.cached_at).toLocaleString('ru-RU')}
                                </Text>
                            )}
                        </VStack>
                    )}

                    {/* Состояние: idle (нет ссылки или ошибка) */}
                    {!inProgress && !isReady && (
                        <Button
                            w="full" size="sm"
                            bg="#9e1b32" color="white"
                            _hover={{ bg: '#7a1527' }}
                            onClick={() => onGenerate(preset.key)}
                            loading={isLoading}
                            loadingText="Постановка в очередь…"
                        >
                            <LuLink /> {failed ? 'Повторить' : 'Получить ссылку'}
                        </Button>
                    )}
                </VStack>
            </Card.Body>
        </Card.Root>
    );
}

export default function Index({ presets: initialPresets }) {
    const [presets, setPresets] = useState(initialPresets);
    const [generatingKey, setGeneratingKey] = useState(null);

    const handleGeneratePreset = async (key) => {
        try {
            setGeneratingKey(key);
            const res = await axios.post(`/cabinet/export-presets/${key}/generate`);
            setPresets((prev) => prev.map((p) =>
                p.key === key
                    ? {
                        ...p,
                        generated: true,
                        download_url: res.data.download_url,
                        export_id: res.data.export_id,
                        status: res.data.status || 'queued',
                    }
                    : p
            ));
            toaster.create({ title: 'Выгрузка поставлена в очередь', type: 'success' });
        } catch {
            toaster.create({ title: 'Ошибка постановки в очередь', type: 'error' });
        } finally {
            setGeneratingKey(null);
        }
    };

    const handleDeletePreset = async (key) => {
        try {
            await axios.delete(`/cabinet/export-presets/${key}`);
            setPresets((prev) => prev.map((p) =>
                p.key === key
                    ? {
                        ...p,
                        generated: false,
                        download_url: null,
                        export_id: null,
                        cached_at: null,
                        status: 'idle',
                        last_run: null,
                    }
                    : p
            ));
            toaster.create({ title: 'Выгрузка удалена', type: 'success' });
        } catch {
            toaster.create({ title: 'Ошибка удаления', type: 'error' });
        }
    };

    const handleStatusUpdate = useCallback((key, data) => {
        setPresets((prev) => prev.map((p) =>
            p.key === key
                ? {
                    ...p,
                    status: data.status,
                    cached_at: data.cached_at,
                    last_downloaded_at: data.last_downloaded_at,
                    download_url: data.download_url || p.download_url,
                    last_run: data.last_run,
                }
                : p
        ));
    }, []);

    return (
        <CabinetLayout title="Стандартные выгрузки">
            <Head title="Стандартные выгрузки — Pecado" />

            <Text fontSize="sm" color="gray.500" mb="6">
                Готовые выгрузки каталога для популярных интернет-магазинов и площадок.
                Нажмите «Получить ссылку» — и вставьте URL в настройки импорта вашего движка.
                Цены и остатки формируются индивидуально для вашего аккаунта.
            </Text>

            <SimpleGrid columns={{ base: 1, sm: 2, lg: 3 }} gap="4">
                {presets.map((preset) => (
                    <PresetCard
                        key={preset.key}
                        preset={preset}
                        onGenerate={handleGeneratePreset}
                        onDelete={handleDeletePreset}
                        onStatusUpdate={handleStatusUpdate}
                        loadingKey={generatingKey}
                    />
                ))}
            </SimpleGrid>
        </CabinetLayout>
    );
}
