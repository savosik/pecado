import { useState, useRef, useCallback } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, EntitySelector } from '@/Admin/Components';
import {
    Box, Card, Input, Stack, Tabs, HStack, Text, Badge,
    Button, Table, Spinner,
} from '@chakra-ui/react';
import { Switch } from '@/components/ui/switch';
import { toaster } from '@/components/ui/toaster';
import {
    LuFilter, LuColumns3, LuSettings, LuEye,
} from 'react-icons/lu';
import axios from 'axios';
import FilterBuilder from './FilterBuilder';
import ExportFieldSelector from './ExportFieldSelector';

export default function Create({ availableFilters, availableFields, formats, currencies }) {
    const { data, setData, post, processing, errors, transform } = useForm({
        name: '',
        format: 'json',
        filters: { logic: 'and', conditions: [] },
        fields: [
            { key: 'id', label: 'ID' },
            { key: 'name', label: 'Название' },
            { key: 'base_price', label: 'Базовая цена' },
            { key: 'sku', label: 'Артикул' },
        ],
        is_active: true,
        client_user_id: '',
    });

    const closeAfterSaveRef = useRef(false);
    const [preview, setPreview] = useState(null);
    const [previewLoading, setPreviewLoading] = useState(false);

    transform((data) => ({
        ...data,
        _close: closeAfterSaveRef.current ? 1 : 0,
    }));

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;
        post(route('admin.product-exports.store'), {
            onSuccess: () => {
                toaster.create({
                    title: 'Выгрузка успешно создана',
                    type: 'success',
                });
            },
            onError: () => {
                toaster.create({
                    title: 'Ошибка при создании выгрузки',
                    description: 'Проверьте правильность заполнения полей',
                    type: 'error',
                });
            },
        });
    };

    const handleSaveAndClose = (e) => handleSubmit(e, true);

    // Preview
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
            const resp = await axios.post(route('admin.product-exports.preview'), {
                filters: data.filters,
                fields: data.fields,
                client_user_id: data.client_user_id || null,
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
    }, [data.filters, data.fields, data.client_user_id]);

    return (
        <>
            <PageHeader title="Создать выгрузку" />

            <form onSubmit={handleSubmit} noValidate>
                <Card.Root>
                    <Card.Body>
                        <Tabs.Root defaultValue="filters" colorPalette="blue">
                            <Tabs.List>
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
                                <Tabs.Trigger value="settings">
                                    <LuSettings /> Настройки
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

                                    {/* Preview results */}
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
                                    <FormField label="Клиент (пользователь)" error={errors.client_user_id} required>
                                        <Text fontSize="xs" color="fg.muted" mb={2}>
                                            Цены рассчитываются по скидкам клиента, остатки — по его региону
                                        </Text>
                                        <EntitySelector
                                            value={data.client_user_id}
                                            onChange={(user) => {
                                                setData('client_user_id', user?.id || '');
                                            }}
                                            searchUrl="admin.users.search"
                                            placeholder="Выберите клиента..."
                                            displayField="name"
                                            error={errors.client_user_id}
                                        />
                                    </FormField>

                                    <FormField label="Название выгрузки" error={errors.name} required>
                                        <Input
                                            value={data.name}
                                            onChange={(e) => setData('name', e.target.value)}
                                            placeholder="Например: Кроссовки Nike для маркетплейсов"
                                        />
                                    </FormField>

                                    <FormField label="Формат выгрузки" error={errors.format} required>
                                        <HStack gap={3} flexWrap="wrap">
                                            {formats.map((f) => (
                                                <Box
                                                    key={f.value}
                                                    px={5} py={3}
                                                    borderRadius="lg"
                                                    border="2px solid"
                                                    borderColor={data.format === f.value ? 'blue.500' : 'border.muted'}
                                                    bg={data.format === f.value ? 'blue.50' : 'bg'}
                                                    cursor="pointer"
                                                    onClick={() => setData('format', f.value)}
                                                    _hover={{ borderColor: 'blue.300' }}
                                                    transition="all 0.2s"
                                                >
                                                    <Text fontWeight="bold" fontSize="sm">{f.label}</Text>
                                                </Box>
                                            ))}
                                        </HStack>
                                    </FormField>

                                    <FormField label="Активность" error={errors.is_active}>
                                        <HStack gap={3} mt={1}>
                                            <Switch
                                                checked={data.is_active}
                                                onCheckedChange={(e) => setData('is_active', e.checked)}
                                                colorPalette="blue"
                                            />
                                            <Text fontSize="sm">
                                                {data.is_active ? 'Выгрузка активна — файл доступен для скачивания' : 'Выгрузка неактивна — ссылка не будет работать'}
                                            </Text>
                                        </HStack>
                                    </FormField>
                                </Stack>
                            </Tabs.Content>
                        </Tabs.Root>
                    </Card.Body>

                    <Card.Footer>
                        <FormActions
                            onSaveAndClose={handleSaveAndClose}
                            loading={processing}
                            onCancel={() => window.history.back()}
                            submitLabel="Создать выгрузку"
                        />
                    </Card.Footer>
                </Card.Root>
            </form>
        </>
    );
}

Create.layout = (page) => <AdminLayout>{page}</AdminLayout>;
