import { useForm } from '@inertiajs/react';
import { useSlugField } from '@/Admin/hooks/useSlugField';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, SelectRelation, CategoryTreeSelector } from '@/Admin/Components';
import { Box, Card, SimpleGrid, Input, Stack, Button, HStack, Text, IconButton, Fieldset, Tabs } from '@chakra-ui/react';
import { Switch } from '@/components/ui/switch';
import { toaster } from '@/components/ui/toaster';
import { LuPlus, LuTrash2, LuFileText, LuFolderTree } from 'react-icons/lu';

import { useMemo, useRef } from 'react';

export default function Create({ types, categoryTree }) {
    const { data, setData, post, processing, errors, transform } = useForm({
        name: '',
        slug: '',
        type: 'string',
        unit: '',
        is_filterable: false,
        sort_order: 0,
        values: [],
        category_ids: [],
    });

    const closeAfterSaveRef = useRef(false);

    transform((data) => ({
        ...data,
        _close: closeAfterSaveRef.current ? 1 : 0,
    }));

    const { handleSourceChange, handleSlugChange } = useSlugField({
        data, setData, sourceField: 'name',
    });

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;
        post(route('admin.attributes.store'), {
            onSuccess: () => {
                toaster.create({
                    title: 'Атрибут создан',
                    description: 'Атрибут успешно добавлен в систему',
                    type: 'success',
                });
            },
        });
    };

    const addValue = () => {
        setData('values', [...data.values, { value: '', sort_order: data.values.length }]);
    };

    const removeValue = (index) => {
        const newValues = [...data.values];
        newValues.splice(index, 1);
        setData('values', newValues);
    };

    const updateValue = (index, val) => {
        const newValues = [...data.values];
        newValues[index].value = val;
        setData('values', newValues);
    };

    const handleSaveAndClose = (e) => {
        handleSubmit(e, true);
    };

    return (
        <>
            <PageHeader
                title="Создать атрибут"
                description="Добавление новой характеристики товара"
            />

            <form onSubmit={handleSubmit}>
                <Card.Root>
                    <Card.Body>
                        <Tabs.Root defaultValue="general" colorPalette="blue">
                            <Tabs.List>
                                <Tabs.Trigger value="general">
                                    <LuFileText /> Основная информация
                                </Tabs.Trigger>
                                <Tabs.Trigger value="categories">
                                    <LuFolderTree /> Категории
                                </Tabs.Trigger>
                            </Tabs.List>

                            {/* Таб 1: Основная информация */}
                            <Tabs.Content value="general">
                                <Stack gap={6} mt={6}>
                                    <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                        <FormField label="Название" required error={errors.name}>
                                            <Input
                                                value={data.name}
                                                onChange={(e) => handleSourceChange(e.target.value)}
                                                placeholder="Например: Объем"
                                            />
                                        </FormField>

                                        <FormField label="Slug" error={errors.slug} helperText="Оставьте пустым для автогенерации">
                                            <Input
                                                value={data.slug}
                                                onChange={(e) => handleSlugChange(e.target.value)}
                                                placeholder="volume"
                                            />
                                        </FormField>

                                        <SelectRelation
                                            label="Тип данных"
                                            required
                                            value={data.type}
                                            onChange={(val) => setData('type', val)}
                                            options={types}
                                            error={errors.type}
                                        />

                                        <FormField label="Единица измерения" error={errors.unit} helperText="Например: мл, см, кг">
                                            <Input
                                                value={data.unit}
                                                onChange={(e) => setData('unit', e.target.value)}
                                                placeholder="мл"
                                            />
                                        </FormField>

                                        <FormField label="Порядок сортировки" error={errors.sort_order}>
                                            <Input
                                                type="number"
                                                value={data.sort_order}
                                                onChange={(e) => setData('sort_order', e.target.value)}
                                            />
                                        </FormField>

                                        <FormField label="Использовать в фильтрах" error={errors.is_filterable}>
                                            <HStack gap={4} mt={2}>
                                                <Switch
                                                    checked={data.is_filterable}
                                                    onCheckedChange={(e) => setData('is_filterable', e.checked)}
                                                    colorPalette="blue"
                                                />
                                                <Text size="sm">{data.is_filterable ? 'Да' : 'Нет'}</Text>
                                            </HStack>
                                        </FormField>
                                    </SimpleGrid>

                                    {data.type === 'select' && (
                                        <Fieldset.Root>
                                            <Stack gap={4}>
                                                <HStack justify="space-between">
                                                    <Fieldset.Legend fontSize="lg">Значения атрибута</Fieldset.Legend>
                                                    <Button size="sm" variant="outline" onClick={addValue}>
                                                        <LuPlus /> Добавить значение
                                                    </Button>
                                                </HStack>

                                                {errors.values && (
                                                    <Text color="red.500" fontSize="sm">{errors.values}</Text>
                                                )}

                                                <Stack gap={2}>
                                                    {data.values.map((v, index) => (
                                                        <HStack key={index} gap={2}>
                                                            <Input
                                                                value={v.value}
                                                                onChange={(e) => updateValue(index, e.target.value)}
                                                                placeholder="Значение"
                                                            />
                                                            <IconButton
                                                                size="sm"
                                                                variant="ghost"
                                                                colorPalette="red"
                                                                onClick={() => removeValue(index)}
                                                                aria-label="Удалить"
                                                            >
                                                                <LuTrash2 />
                                                            </IconButton>
                                                        </HStack>
                                                    ))}
                                                    {data.values.length === 0 && (
                                                        <Text color="fg.muted" textAlign="center" py={4} border="1px dashed" borderColor="border.muted" borderRadius="md">
                                                            Список значений пуст. Нажмите "Добавить значение".
                                                        </Text>
                                                    )}
                                                </Stack>
                                            </Stack>
                                        </Fieldset.Root>
                                    )}
                                </Stack>
                            </Tabs.Content>

                            {/* Таб 2: Категории */}
                            <Tabs.Content value="categories">
                                <Stack gap={6} mt={6}>
                                    <CategoryTreeSelector
                                        categoryTree={categoryTree}
                                        value={data.category_ids}
                                        onChange={(ids) => setData('category_ids', ids)}
                                    />
                                </Stack>
                            </Tabs.Content>
                        </Tabs.Root>
                    </Card.Body>

                    <Card.Footer>
                        <FormActions
                            onSaveAndClose={handleSaveAndClose}
                            loading={processing}
                            onCancel={() => window.history.back()}
                            submitLabel="Создать атрибут"
                        />
                    </Card.Footer>
                </Card.Root>
            </form>
        </>
    );
}

Create.layout = (page) => <AdminLayout>{page}</AdminLayout>;
