import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, SelectRelation } from '@/Admin/Components';
import { Box, Card, SimpleGrid, Input, Stack, Button, HStack, Text, IconButton, Fieldset } from '@chakra-ui/react';
import { Switch } from '@/components/ui/switch';
import { toaster } from '@/components/ui/toaster';
import { LuPlus, LuTrash2, LuGripVertical } from 'react-icons/lu';
import {
    DndContext,
    closestCenter,
    KeyboardSensor,
    PointerSensor,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import {
    arrayMove,
    SortableContext,
    sortableKeyboardCoordinates,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';

// Компонент для одного значения атрибута с drag-and-drop
function AttributeValueItem({ value, index, onUpdate, onRemove }) {
    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id: value.id || `value-${index}` });

    const style = {
        transform: CSS.Transform.toString(transform),
        transition,
    };

    return (
        <HStack
            ref={setNodeRef}
            style={style}
            gap={2}
            bg={isDragging ? 'blue.50' : 'white'}
            p={2}
            borderRadius="md"
            borderWidth="1px"
            borderColor={isDragging ? 'blue.200' : 'gray.200'}
            opacity={isDragging ? 0.5 : 1}
        >
            <Box
                {...attributes}
                {...listeners}
                cursor="grab"
                color="gray.400"
                _hover={{ color: 'gray.600' }}
                px={1}
            >
                <LuGripVertical size={18} />
            </Box>

            <Input
                value={value.value}
                onChange={(e) => onUpdate(index, e.target.value)}
                placeholder="Значение"
                flex={1}
            />

            <IconButton
                size="sm"
                variant="ghost"
                colorPalette="red"
                onClick={() => onRemove(index)}
                aria-label="Удалить"
            >
                <LuTrash2 />
            </IconButton>
        </HStack>
    );
}

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

    const sensors = useSensors(
        useSensor(PointerSensor),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        })
    );

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
        // Пересчитываем sort_order после удаления
        const reorderedValues = newValues.map((v, idx) => ({ ...v, sort_order: idx }));
        setData('values', reorderedValues);
    };

    const updateValue = (index, val) => {
        const newValues = [...data.values];
        newValues[index].value = val;
        setData('values', newValues);
    };

    const handleDragEnd = (event) => {
        const { active, over } = event;

        if (active.id !== over.id) {
            setData('values', (items) => {
                const oldIndex = items.findIndex(
                    (item, idx) => (item.id || `value-${idx}`) === active.id
                );
                const newIndex = items.findIndex(
                    (item, idx) => (item.id || `value-${idx}`) === over.id
                );

                const newItems = arrayMove(items, oldIndex, newIndex);

                // Обновляем sort_order для всех элементов
                return newItems.map((item, index) => ({
                    ...item,
                    sort_order: index,
                }));
            });
        }
    };

    return (
        <>
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

                                            <DndContext
                                                sensors={sensors}
                                                collisionDetection={closestCenter}
                                                onDragEnd={handleDragEnd}
                                            >
                                                <SortableContext
                                                    items={data.values.map((v, idx) => v.id || `value-${idx}`)}
                                                    strategy={verticalListSortingStrategy}
                                                >
                                                    <Stack gap={2}>
                                                        {data.values.map((v, index) => (
                                                            <AttributeValueItem
                                                                key={v.id || `value-${index}`}
                                                                value={v}
                                                                index={index}
                                                                onUpdate={updateValue}
                                                                onRemove={removeValue}
                                                            />
                                                        ))}
                                                        {data.values.length === 0 && (
                                                            <Text color="fg.muted" textAlign="center" py={4} border="1px dashed" borderColor="border.muted" borderRadius="md">
                                                                Список значений пуст. Нажмите "Добавить значение".
                                                            </Text>
                                                        )}
                                                    </Stack>
                                                </SortableContext>
                                            </DndContext>
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
        </>
    );
}

Edit.layout = (page) => <AdminLayout>{page}</AdminLayout>;
