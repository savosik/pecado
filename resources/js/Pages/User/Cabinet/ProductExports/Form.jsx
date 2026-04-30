import { useState, useCallback, useMemo } from 'react';
import { useForm } from '@inertiajs/react';
import {
    Box, Card, Input, Stack, Accordion, HStack, Text, Badge,
    Button, IconButton, Table, Spinner, Span,
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

function countConditions(group) {
    if (!group || !Array.isArray(group.conditions)) return 0;
    return group.conditions.reduce((acc, c) => {
        if (c?.type === 'group') return acc + countConditions(c);
        return acc + 1;
    }, 0);
}

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

    const initialOpen = useMemo(() => {
        const ids = ['settings', 'fields', 'filters'];
        if (isEditing) ids.push('download');
        return ids;
    }, [isEditing]);

    const [openItems, setOpenItems] = useState(initialOpen);

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
                title: 'Сначала выберите поля в разделе «Поля для выгрузки»',
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
            setOpenItems((prev) => prev.includes('preview') ? prev : [...prev, 'preview']);
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
    const filtersCount = countConditions(data.filters);

    const sectionTrigger = (icon, label, badge = null) => (
        <HStack flex="1" gap={3} align="center">
            <Box color="pecado.fg" flexShrink={0}>{icon}</Box>
            <Text fontWeight="600" fontSize="md">{label}</Text>
            {badge}
        </HStack>
    );

    return (
        <CabinetLayout title={pageTitle}>
            <Head title={`${pageTitle} — Pecado`} />

            <form onSubmit={handleSubmit} noValidate>
                <Card.Root bg="bg" borderRadius="xl" border="1px solid" borderColor="border.muted" overflow="visible">
                    <Card.Body p={0} overflow="visible">
                        <Accordion.Root
                            multiple
                            collapsible
                            value={openItems}
                            onValueChange={(e) => setOpenItems(e.value)}
                        >
                            {/* 1. Основные настройки */}
                            <Accordion.Item value="settings">
                                <Accordion.ItemTrigger px={6} py={4}>
                                    {sectionTrigger(<LuSettings size={18} />, 'Основные настройки')}
                                    <Accordion.ItemIndicator />
                                </Accordion.ItemTrigger>
                                <Accordion.ItemContent css={{ overflow: 'visible' }}>
                                    <Accordion.ItemBody px={6} pb={6} overflow="visible">
                                        <Stack gap={6}>
                                            <Box>
                                                <Text fontSize="sm" fontWeight="600" mb={1}>
                                                    Название выгрузки <Text as="span" color="red.500">*</Text>
                                                </Text>
                                                <Input
                                                    value={data.name}
                                                    onChange={(e) => setData('name', e.target.value)}
                                                    placeholder="Например: Кроссовки Nike для маркетплейсов"
                                                />
                                                {errors.name && <Text color="red.500" fontSize="xs" mt={1}>{errors.name}</Text>}
                                            </Box>

                                            <Box>
                                                <Text fontSize="sm" fontWeight="600" mb={2}>
                                                    Формат выгрузки <Text as="span" color="red.500">*</Text>
                                                </Text>
                                                <HStack gap={3} flexWrap="wrap">
                                                    {formats.map((f) => {
                                                        const active = data.format === f.value;
                                                        return (
                                                            <Box
                                                                key={f.value}
                                                                px={5} py={3}
                                                                borderRadius="lg"
                                                                border="2px solid"
                                                                borderColor={active ? 'pecado.solid' : 'border.muted'}
                                                                bg={active ? 'pecado.solid' : 'bg.subtle'}
                                                                color={active ? 'pecado.contrast' : 'fg'}
                                                                cursor="pointer"
                                                                onClick={() => setData('format', f.value)}
                                                                _hover={active ? undefined : { borderColor: 'pecado.solid' }}
                                                                transition="all 0.2s"
                                                            >
                                                                <Text fontWeight="bold" fontSize="sm">{f.label}</Text>
                                                            </Box>
                                                        );
                                                    })}
                                                </HStack>
                                                {errors.format && <Text color="red.500" fontSize="xs" mt={1}>{errors.format}</Text>}
                                            </Box>

                                            <Box>
                                                <Text fontSize="sm" fontWeight="600" mb={1}>Активность</Text>
                                                <HStack gap={3} mt={1}>
                                                    <Switch
                                                        checked={data.is_active}
                                                        onCheckedChange={(e) => setData('is_active', e.checked)}
                                                        colorPalette="pecado"
                                                    />
                                                    <Text fontSize="sm" color="fg.muted">
                                                        {data.is_active
                                                            ? 'Выгрузка активна — файл доступен для скачивания'
                                                            : 'Выгрузка неактивна — ссылка не будет работать'}
                                                    </Text>
                                                </HStack>
                                            </Box>
                                        </Stack>
                                    </Accordion.ItemBody>
                                </Accordion.ItemContent>
                            </Accordion.Item>

                            {/* 2. Поля для выгрузки */}
                            <Accordion.Item value="fields">
                                <Accordion.ItemTrigger px={6} py={4}>
                                    {sectionTrigger(
                                        <LuColumns3 size={18} />,
                                        'Поля для выгрузки',
                                        data.fields.length > 0 && (
                                            <Badge colorPalette="pecado" variant="subtle" size="sm">
                                                {data.fields.length}
                                            </Badge>
                                        ),
                                    )}
                                    <Accordion.ItemIndicator />
                                </Accordion.ItemTrigger>
                                <Accordion.ItemContent css={{ overflow: 'visible' }}>
                                    <Accordion.ItemBody px={6} pb={6} overflow="visible">
                                        <Stack gap={3}>
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
                                    </Accordion.ItemBody>
                                </Accordion.ItemContent>
                            </Accordion.Item>

                            {/* 3. Условия отбора */}
                            <Accordion.Item value="filters">
                                <Accordion.ItemTrigger px={6} py={4}>
                                    {sectionTrigger(
                                        <LuFilter size={18} />,
                                        'Условия отбора',
                                        filtersCount > 0 && (
                                            <Badge colorPalette="pecado" variant="subtle" size="sm">
                                                {filtersCount}
                                            </Badge>
                                        ),
                                    )}
                                    <Span color="fg.muted" fontSize="xs" mr={2}>
                                        {filtersCount === 0 ? 'все товары' : `${filtersCount} усл.`}
                                    </Span>
                                    <Accordion.ItemIndicator />
                                </Accordion.ItemTrigger>
                                <Accordion.ItemContent css={{ overflow: 'visible' }}>
                                    <Accordion.ItemBody px={6} pb={6} overflow="visible">
                                        <FilterBuilder
                                            filters={data.filters}
                                            availableFilters={availableFilters}
                                            onChange={(filters) => setData('filters', filters)}
                                        />
                                    </Accordion.ItemBody>
                                </Accordion.ItemContent>
                            </Accordion.Item>

                            {/* 4. Предпросмотр результатов */}
                            <Accordion.Item value="preview">
                                <Accordion.ItemTrigger px={6} py={4}>
                                    {sectionTrigger(
                                        <LuEye size={18} />,
                                        'Предпросмотр результатов',
                                        preview && (
                                            <Badge colorPalette="pecado" variant="subtle" size="sm">
                                                {preview.total}
                                            </Badge>
                                        ),
                                    )}
                                    <Accordion.ItemIndicator />
                                </Accordion.ItemTrigger>
                                <Accordion.ItemContent css={{ overflow: 'visible' }}>
                                    <Accordion.ItemBody px={6} pb={6} overflow="visible">
                                        <Stack gap={3}>
                                            <HStack justify="space-between">
                                                <Text fontSize="sm" color="fg.muted">
                                                    Покажет первые строки выгрузки по текущим фильтрам и полям.
                                                </Text>
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={loadPreview}
                                                    disabled={previewLoading}
                                                >
                                                    {previewLoading ? <Spinner size="sm" /> : <LuEye />}
                                                    {' '}{preview ? 'Обновить' : 'Загрузить'}
                                                </Button>
                                            </HStack>

                                            {preview && (
                                                <Box
                                                    p={4}
                                                    bg="bg.subtle"
                                                    borderRadius="md"
                                                    border="1px solid"
                                                    borderColor="border.muted"
                                                >
                                                    <HStack justify="space-between" mb={3}>
                                                        <Text fontWeight="bold">
                                                            Найдено товаров:{' '}
                                                            <Badge colorPalette="pecado" variant="subtle">
                                                                {preview.total}
                                                            </Badge>
                                                        </Text>
                                                        <Button size="xs" variant="ghost" onClick={() => setPreview(null)}>
                                                            Скрыть
                                                        </Button>
                                                    </HStack>
                                                    {preview.data.length > 0 ? (
                                                        <Box overflowX="auto" maxH="400px" overflowY="auto">
                                                            <Table.Root bg="bg" size="sm" variant="outline">
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
                                    </Accordion.ItemBody>
                                </Accordion.ItemContent>
                            </Accordion.Item>

                            {/* 5. Ссылка для скачивания (только при редактировании) */}
                            {isEditing && (
                                <Accordion.Item value="download">
                                    <Accordion.ItemTrigger px={6} py={4}>
                                        {sectionTrigger(<LuLink size={18} />, 'Ссылка для скачивания')}
                                        <Accordion.ItemIndicator />
                                    </Accordion.ItemTrigger>
                                    <Accordion.ItemContent css={{ overflow: 'visible' }}>
                                        <Accordion.ItemBody px={6} pb={6} overflow="visible">
                                            <Box
                                                p={4}
                                                bg="pecado.subtle"
                                                borderRadius="lg"
                                                border="1px solid"
                                                borderColor="pecado.muted"
                                            >
                                                <HStack justify="space-between" align="start" gap={3}>
                                                    <Box minW={0} flex="1">
                                                        <Text fontWeight="bold" fontSize="sm" color="pecado.fg" mb={1}>
                                                            Прямая ссылка
                                                        </Text>
                                                        <Text fontSize="sm" color="fg.muted" wordBreak="break-all">
                                                            {exportData.download_url}
                                                        </Text>
                                                    </Box>
                                                    <IconButton
                                                        size="sm"
                                                        variant="ghost"
                                                        colorPalette="pecado"
                                                        onClick={copyUrl}
                                                        aria-label="Копировать ссылку"
                                                        flexShrink={0}
                                                    >
                                                        <LuCopy />
                                                    </IconButton>
                                                </HStack>
                                            </Box>
                                        </Accordion.ItemBody>
                                    </Accordion.ItemContent>
                                </Accordion.Item>
                            )}
                        </Accordion.Root>
                    </Card.Body>

                    <Card.Footer
                        position="sticky"
                        bottom={0}
                        bg="bg"
                        borderTop="1px solid"
                        borderColor="border.muted"
                        zIndex={1}
                        py={4}
                        borderBottomRadius="xl"
                    >
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
                                colorPalette="pecado"
                                variant="solid"
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
