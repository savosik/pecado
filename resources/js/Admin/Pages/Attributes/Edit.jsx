import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, SelectRelation } from '@/Admin/Components';
import { Box, Card, SimpleGrid, Input, Stack, Button, HStack, Text, IconButton, Fieldset } from '@chakra-ui/react';
import { Switch } from '@/components/ui/switch';
import { toaster } from '@/components/ui/toaster';
import { LuPlus, LuTrash2 } from 'react-icons/lu';

export default function Edit({ attribute, types }) {
    const { data, setData, put, processing, errors } = useForm({
        name: attribute.name || '',
        slug: attribute.slug || '',
        type: attribute.type || 'string',
        unit: attribute.unit || '',
        is_filterable: !!attribute.is_filterable,
        sort_order: attribute.sort_order || 0,
        values: attribute.values || [],
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        put(route('admin.attributes.update', attribute.id), {
            onSuccess: () => {
                toaster.create({
                    title: 'Атрибут обновлен',
                    description: 'Изменения успешно сохранены',
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

    return (
        <AdminLayout
            breadcrumbs={[
                { label: 'Главная', href: route('admin.dashboard') },
                { label: 'Атрибуты', href: route('admin.attributes.index') },
                { label: 'Редактировать' },
            ]}
        >
            <Box p={6}>
                <PageHeader
                    title={`Редактировать атрибут: ${attribute.name}`}
                    description="Изменение характеристик и значений"
                />

                <form onSubmit={handleSubmit}>
                    <Card.Root>
                        <Card.Body>
                            <Stack gap={6}>
                                <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                    <FormField label="Название" required error={errors.name}>
                                        <Input
                                            value={data.name}
                                            onChange={(e) => setData('name', e.target.value)}
                                            placeholder="Например: Объем"
                                        />
                                    </FormField>

                                    <FormField label="Slug" error={errors.slug} helperText="Оставьте пустым для автогенерации">
                                        <Input
                                            value={data.slug}
                                            onChange={(e) => setData('slug', e.target.value)}
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
                                                    <HStack key={v.id || index} gap={2}>
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
                        </Card.Body>

                        <Card.Footer>
                            <FormActions
                                loading={processing}
                                onCancel={() => window.history.back()}
                                submitLabel="Сохранить изменения"
                            />
                        </Card.Footer>
                    </Card.Root>
                </form>
            </Box>
        </AdminLayout>
    );
}
