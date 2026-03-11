import { useState, useRef, useCallback, useMemo } from 'react';
import { useForm } from '@inertiajs/react';
import {
    Box, Card, Input, Stack, Tabs, HStack, Text, Badge,
    Button, IconButton, Table, Spinner,
} from '@chakra-ui/react';
import { Switch } from '@/components/ui/switch';
import { toaster } from '@/components/ui/toaster';
import { Head } from '@inertiajs/react';
import CabinetLayout from '../CabinetLayout';
import {
    LuFilter, LuColumns3, LuSettings, LuEye, LuCopy, LuLink,
} from 'react-icons/lu';
import axios from 'axios';
import FilterBuilder from '@/Admin/Pages/ProductExports/FilterBuilder';
import ExportFieldSelector from '@/Admin/Pages/ProductExports/ExportFieldSelector';

/**
 * Convert legacy string[] fields to {key, label}[] format.
 */
function normalizeFieldsFormat(fields, availableFields) {
    if (!fields || fields.length === 0) return [];
    if (typeof fields[0] === 'object' && fields[0].key) return fields;

    const labelMap = {};
    (availableFields || []).forEach(group => {
        group.fields.forEach(f => {
            labelMap[f.key] = f.label;
        });
    });

    return fields.map(key => ({ key, label: labelMap[key] || key }));
}

const defaultFields = [
    { key: 'id', label: 'ID' },
    { key: 'name', label: 'Название' },
    { key: 'base_price', label: 'Базовая цена' },
    { key: 'sku', label: 'Артикул' },
];

export default function Form({ export: exportData, availableFilters, availableFields, formats, currencies }) {
    const isEditing = !!exportData;

    const initialFilters = (exportData?.filters && exportData.filters.logic)
        ? exportData.filters
        : { logic: 'and', conditions: [] };

    const initialFields = useMemo(
        () => isEditing
            ? normalizeFieldsFormat(exportData.fields || [], availableFields)
            : defaultFields,
        // eslint-disable-next-line react-hooks/exhaustive-deps
        []
    );

    const { data, setData, post, put, processing, errors } = useForm({
        name: exportData?.name || '',
        format: exportData?.format || 'json',
        filters: initialFilters,
        fields: initialFields,
        is_active: exportData?.is_active ?? true,
    });

    const [preview, setPreview] = useState(null);
    const [previewLoading, setPreviewLoading] = useState(false);

    const handleSubmit = (e) => {
        e.preventDefault();

        const method = isEditing ? put : post;
        const url = isEditing
            ? route('cabinet.product-exports.update', exportData.id)
            : route('cabinet.product-exports.store');

        method(url, {
            onSuccess: () => {
                toaster.create({
                    title: isEditing ? 'Выгрузка успешно обновлена' : 'Выгрузка успешно создана',
                    type: 'success',
                });
            },
            onError: () => {
                toaster.create({
                    title: isEditing ? 'Ошибка при обновлении выгрузки' : 'Ошибка при создании выгрузки',
                    description: 'Проверьте правильность заполнения полей',
                    type: 'error',
                });
            },
        });
    };

    const loadPreview = useCallback(async () => {
        if (data.fields.length === 0) {
            toaster.create({
                title: 'Выберите хотя бы одно поле на вкладке «Поля»',
                type: 'warning',
            });
            return;
        }
        setPreviewLoading(true);
        try {
            const resp = await axios.post(route('cabinet.product-exports.preview'), {
                filters: data.filters,
                fields: data.fields,
            });
            setPreview(resp.data);
        } catch (e) {
            toaster.create({
                title: 'Ошибка предпросмотра',
                description: e.response?.data?.message || 'Произошла ошибка',
                type: 'error',
            });
        } finally {
            setPreviewLoading(false);
        }
    }, [data.filters, data.fields]);

    const copyUrl = () => {
        navigator.clipboard.writeText(exportData.download_url);
        toaster.create({ title: 'Ссылка скопирована в буфер обмена', type: 'success' });
    };

    const pageTitle = isEditing ? `Редактировать: ${exportData.name}` : 'Создать выгрузку';

    return (
        <CabinetLayout title={pageTitle}>
            <Head title={`${pageTitle} — Pecado`} />

            <form onSubmit={handleSubmit} noValidate>
                <Card.Root borderRadius="xl" border="1px solid" borderColor="gray.100" _dark={{ bg: 'gray.800', borderColor: 'gray.700' }}>
                    <Card.Body>
                        <Tabs.Root defaultValue="settings" colorPalette="blue">
                            <Tabs.List>
                                <Tabs.Trigger value="settings">
                                    <LuSettings /> Настройки
                                </Tabs.Trigger>
                                <Tabs.Trigger value="filters">
                                    <LuFilter /> Условия отбора
                                </Tabs.Trigger>
                                <Tabs.Trigger value="fields">
                                    <LuColumns3 /> Поля для выгрузки
                                    {data.fields.length > 0 &&
                                        <Badge ml={1} colorPalette="blue" variant="subtle" size="sm">
                                            {data.fields.length}
                                        </Badge>
                                    }
                                </Tabs.Trigger>
                            </Tabs.List>

                            {/* Таб 1: Условия отбора */}
                            <Tabs.Content value="filters">
                                <Stack gap={4} mt={6}>
                                    <HStack justify="space-between">
                                        <Text fontWeight="bold" fontSize="md">
                                            Настройка фильтров
                                        </Text>
                                        <Button size="sm" variant="outline" onClick={loadPreview} disabled={previewLoading}>
                                            {previewLoading ? <Spinner size="sm" /> : <LuEye />}
                                            {' '}Предпросмотр
                                        </Button>
                                    </HStack>

                                    <FilterBuilder
                                        filters={data.filters}
                                        availableFilters={availableFilters}
                                        onChange={(filters) => setData('filters', filters)}
                                    />

                                    {preview && (
                                        <Box mt={4} p={4} bg="bg.subtle" borderRadius="md" border="1px solid" borderColor="border.muted">
                                            <HStack justify="space-between" mb={3}>
                                                <Text fontWeight="bold">
                                                    Найдено товаров: <Badge colorPalette="blue" variant="subtle">{preview.total}</Badge>
                                                </Text>
                                                <Button size="xs" variant="ghost" onClick={() => setPreview(null)}>
                                                    Скрыть
                                                </Button>
                                            </HStack>
                                            {preview.data.length > 0 ? (
                                                <Box overflowX="auto" maxH="400px" overflowY="auto">
                                                    <Table.Root size="sm" variant="outline">
                                                        <Table.Header>
                                                            <Table.Row>
                                                                {Object.values(preview.labels).map((label, i) => (
                                                                    <Table.ColumnHeader key={i} fontSize="xs" whiteSpace="nowrap">
                                                                        {label}
                                                                    </Table.ColumnHeader>
                                                                ))}
                                                            </Table.Row>
                                                        </Table.Header>
                                                        <Table.Body>
                                                            {preview.data.map((row, ri) => (
                                                                <Table.Row key={ri}>
                                                                    {Object.keys(preview.labels).map((key, ci) => (
                                                                        <Table.Cell key={ci} fontSize="xs" whiteSpace="nowrap">
                                                                            {row[key] !== null && row[key] !== undefined
                                                                                ? String(row[key])
                                                                                : '—'}
                                                                        </Table.Cell>
                                                                    ))}
                                                                </Table.Row>
                                                            ))}
                                                        </Table.Body>
                                                    </Table.Root>
                                                </Box>
                                            ) : (
                                                <Text color="fg.muted" fontSize="sm">Нет товаров по заданным условиям.</Text>
                                            )}
                                        </Box>
                                    )}
                                </Stack>
                            </Tabs.Content>

                            {/* Таб 2: Поля для выгрузки */}
                            <Tabs.Content value="fields">
                                <Stack gap={4} mt={6}>
                                    {errors.fields && (
                                        <Text color="red.500" fontSize="sm">{errors.fields}</Text>
                                    )}
                                    <ExportFieldSelector
                                        availableFields={availableFields}
                                        selectedFields={data.fields}
                                        onChange={(fields) => setData('fields', fields)}
                                        currencies={currencies}
                                    />
                                </Stack>
                            </Tabs.Content>

                            {/* Таб 3: Настройки */}
                            <Tabs.Content value="settings">
                                <Stack gap={6} mt={6}>
                                    <Box>
                                        <Text fontSize="sm" fontWeight="600" mb={1}>Название выгрузки <Text as="span" color="red.500">*</Text></Text>
                                        <Input
                                            value={data.name}
                                            onChange={(e) => setData('name', e.target.value)}
                                            placeholder="Например: Кроссовки Nike для маркетплейсов"
                                        />
                                        {errors.name && <Text color="red.500" fontSize="xs" mt={1}>{errors.name}</Text>}
                                    </Box>

                                    <Box>
                                        <Text fontSize="sm" fontWeight="600" mb={2}>Формат выгрузки <Text as="span" color="red.500">*</Text></Text>
                                        <HStack gap={3} flexWrap="wrap">
                                            {formats.map((f) => (
                                                <Box
                                                    key={f.value}
                                                    px={5} py={3}
                                                    borderRadius="lg"
                                                    border="2px solid"
                                                    borderColor={data.format === f.value ? '#9e1b32' : 'gray.200'}
                                                    bg={data.format === f.value ? 'red.50' : 'bg'}
                                                    cursor="pointer"
                                                    onClick={() => setData('format', f.value)}
                                                    _hover={{ borderColor: '#9e1b32' }}
                                                    transition="all 0.2s"
                                                    _dark={{
                                                        bg: data.format === f.value ? 'red.900/20' : 'gray.800',
                                                        borderColor: data.format === f.value ? '#9e1b32' : 'gray.600',
                                                    }}
                                                >
                                                    <Text fontWeight="bold" fontSize="sm">{f.label}</Text>
                                                </Box>
                                            ))}
                                        </HStack>
                                        {errors.format && <Text color="red.500" fontSize="xs" mt={1}>{errors.format}</Text>}
                                    </Box>

                                    <Box>
                                        <Text fontSize="sm" fontWeight="600" mb={1}>Активность</Text>
                                        <HStack gap={3} mt={1}>
                                            <Switch
                                                checked={data.is_active}
                                                onCheckedChange={(e) => setData('is_active', e.checked)}
                                                colorPalette="red"
                                            />
                                            <Text fontSize="sm">
                                                {data.is_active
                                                    ? 'Выгрузка активна — файл доступен для скачивания'
                                                    : 'Выгрузка неактивна — ссылка не будет работать'}
                                            </Text>
                                        </HStack>
                                    </Box>

                                    {/* Download URL (only when editing) */}
                                    {isEditing && (
                                        <Box p={4} bg="red.50" borderRadius="lg" border="1px solid" borderColor="red.200"
                                            _dark={{ bg: 'red.900/20', borderColor: 'red.800' }}
                                        >
                                            <HStack justify="space-between">
                                                <Box>
                                                    <Text fontWeight="bold" fontSize="sm" color="red.700" mb={1} _dark={{ color: 'red.300' }}>
                                                        <LuLink style={{ display: 'inline', marginRight: '4px' }} />
                                                        Ссылка для скачивания
                                                    </Text>
                                                    <Text fontSize="sm" color="red.600" wordBreak="break-all" _dark={{ color: 'red.400' }}>
                                                        {exportData.download_url}
                                                    </Text>
                                                </Box>
                                                <IconButton
                                                    size="sm"
                                                    variant="ghost"
                                                    colorPalette="red"
                                                    onClick={copyUrl}
                                                    aria-label="Копировать"
                                                >
                                                    <LuCopy />
                                                </IconButton>
                                            </HStack>
                                        </Box>
                                    )}
                                </Stack>
                            </Tabs.Content>
                        </Tabs.Root>
                    </Card.Body>

                    <Card.Footer>
                        <HStack justify="space-between" w="100%">
                            <Button
                                variant="outline"
                                onClick={() => window.history.back()}
                                size="sm"
                            >
                                Отмена
                            </Button>
                            <Button
                                type="submit"
                                bg="#9e1b32"
                                color="white"
                                _hover={{ bg: '#7a1527' }}
                                loading={processing}
                                size="sm"
                            >
                                {isEditing ? 'Сохранить изменения' : 'Создать выгрузку'}
                            </Button>
                        </HStack>
                    </Card.Footer>
                </Card.Root>
            </form>
        </CabinetLayout>
    );
}
