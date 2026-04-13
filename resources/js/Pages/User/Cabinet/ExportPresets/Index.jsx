import { useState } from 'react';
import {
    Box, Flex, Text, Card, HStack, VStack, Badge, Button,
    IconButton, SimpleGrid, Heading,
} from '@chakra-ui/react';
import { Head } from '@inertiajs/react';
import CabinetLayout from '../CabinetLayout';
import {
    LuFileCode, LuShoppingBag, LuGlobe, LuMessageCircle, LuSearch,
    LuPenTool, LuShoppingCart, LuStore, LuFileDown,
    LuCopy, LuCheck, LuLink, LuX,
} from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';
import axios from 'axios';

const presetIcons = {
    LuFileCode, LuShoppingBag, LuGlobe, LuMessageCircle,
    LuSearch, LuPenTool, LuShoppingCart, LuStore,
};

function PresetCard({ preset, onGenerate, onDelete, loadingKey }) {
    const IconComponent = presetIcons[preset.icon] || LuFileDown;
    const isLoading = loadingKey === preset.key;
    const isGenerated = preset.generated && preset.download_url;

    return (
        <Card.Root
            bg={{ base: 'white', _dark: 'gray.800' }}
            borderRadius="xl"
            border="1px solid"
            borderColor={{ base: 'gray.100', _dark: 'gray.700' }}
            _dark={{ bg: 'gray.800', borderColor: 'gray.700' }}
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
                    <Text fontSize="xs" color="gray.500" lineClamp={2} minH="32px">
                        {preset.description}
                    </Text>

                    {/* Link & Actions */}
                    {isGenerated ? (
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
                    ) : (
                        <Button
                            w="full" size="sm"
                            bg="#9e1b32" color="white"
                            _hover={{ bg: '#7a1527' }}
                            onClick={() => onGenerate(preset.key)}
                            loading={isLoading}
                            loadingText="Генерация..."
                        >
                            <LuLink /> Получить ссылку
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
            // Обновляем пресет в локальном состоянии
            setPresets(prev => prev.map(p =>
                p.key === key
                    ? { ...p, generated: true, download_url: res.data.download_url, export_id: res.data.export_id }
                    : p
            ));
            toaster.create({ title: 'Ссылка на выгрузку создана!', type: 'success' });
        } catch (err) {
            toaster.create({ title: 'Ошибка генерации', type: 'error' });
        } finally {
            setGeneratingKey(null);
        }
    };

    const handleDeletePreset = async (key) => {
        try {
            await axios.delete(`/cabinet/export-presets/${key}`);
            setPresets(prev => prev.map(p =>
                p.key === key
                    ? { ...p, generated: false, download_url: null, export_id: null, cached_at: null }
                    : p
            ));
            toaster.create({ title: 'Выгрузка удалена', type: 'success' });
        } catch (err) {
            toaster.create({ title: 'Ошибка удаления', type: 'error' });
        }
    };

    return (
        <CabinetLayout title="Стандартные выгрузки">
            <Head title="Стандартные выгрузки — Pecado" />

            <Text fontSize="sm" color="gray.500" mb="6">
                Готовые выгрузки каталога для популярных интернет-магазинов и площадок.
                Нажмите «Получить ссылку» — и вставьте URL в настройки импорта вашего движка.
                Цены и остатки формируются индивидуально для вашего аккаунта.
            </Text>

            <SimpleGrid columns={{ base: 1, sm: 2, lg: 3, xl: 4 }} gap="4">
                {presets.map((preset) => (
                    <PresetCard
                        key={preset.key}
                        preset={preset}
                        onGenerate={handleGeneratePreset}
                        onDelete={handleDeletePreset}
                        loadingKey={generatingKey}
                    />
                ))}
            </SimpleGrid>
        </CabinetLayout>
    );
}
