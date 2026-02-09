import { useState, useMemo, useRef } from 'react';
import { useForm } from '@inertiajs/react';
import { useSlugField } from '@/Admin/hooks/useSlugField';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, SelectRelation, CategoryTreeSelector } from '@/Admin/Components';
import { Box, Card, SimpleGrid, Input, Stack, Button, HStack, Text, IconButton, Fieldset, Tabs, Dialog, Portal, CloseButton } from '@chakra-ui/react';
import { Switch } from '@/components/ui/switch';
import { toaster } from '@/components/ui/toaster';
import { LuPlus, LuTrash2, LuGripVertical, LuFileText, LuFolderTree, LuBookOpen } from 'react-icons/lu';
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

export default function Edit({ attribute, types, categoryTree, attributeGroups }) {
    const originalType = useRef(attribute.type);
    const [convertDialogOpen, setConvertDialogOpen] = useState(false);
    const [pendingType, setPendingType] = useState(null);

    const { data, setData, put, processing, errors, transform } = useForm({
        name: attribute.name || '',
        slug: attribute.slug || '',
        type: attribute.type || 'string',
        unit: attribute.unit || '',
        is_filterable: !!attribute.is_filterable,
        sort_order: attribute.sort_order || 0,
        values: attribute.values || [],
        category_ids: (attribute.categories || []).map(c => c.id),
        attribute_group_id: attribute.attribute_group_id || null,
        _convert_to_select: false,
        _convert_to_boolean: false,
    });

    const closeAfterSaveRef = useRef(false);

    transform((data) => ({
        ...data,
        _close: closeAfterSaveRef.current ? 1 : 0,
    }));

    const { handleSourceChange, handleSlugChange } = useSlugField({
        data, setData, sourceField: 'name', isEditing: true,
    });

    const sensors = useSensors(
        useSensor(PointerSensor),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        })
    );

    // Обработка смены типа — если меняем на select или boolean из другого типа, показать диалог
    const handleTypeChange = (newType) => {
        if (newType === 'select' && originalType.current !== 'select') {
            setPendingType(newType);
            setConvertDialogOpen(true);
        } else if (newType === 'boolean' && originalType.current !== 'boolean') {
            setPendingType(newType);
            setConvertDialogOpen(true);
        } else {
            setData(prev => ({
                ...prev,
                type: newType,
                _convert_to_select: false,
                _convert_to_boolean: false,
            }));
        }
    };

    // Подтверждение конвертации
    const handleConfirmConvert = () => {
        if (pendingType === 'select') {
            setData(prev => ({
                ...prev,
                type: 'select',
                _convert_to_select: true,
                _convert_to_boolean: false,
            }));
        } else if (pendingType === 'boolean') {
            setData(prev => ({
                ...prev,
                type: 'boolean',
                _convert_to_boolean: true,
                _convert_to_select: false,
            }));
        }
        setConvertDialogOpen(false);
        setPendingType(null);
    };

    // Отмена конвертации
    const handleCancelConvert = () => {
        setConvertDialogOpen(false);
        setPendingType(null);
    };

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;
        put(route('admin.attributes.update', attribute.id), {
            onSuccess: () => {
                // Обновляем originalType после успешного сохранения
                originalType.current = data.type;
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

    const handleSaveAndClose = (e) => {
        handleSubmit(e, true);
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
                                            onChange={(val) => handleTypeChange(val)}
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

                                    <SelectRelation
                                        label="Группа атрибутов"
                                        value={data.attribute_group_id}
                                        onChange={(val) => setData('attribute_group_id', val)}
                                        options={attributeGroups.map(g => ({ value: g.id, label: g.name }))}
                                        placeholder="Без группы"
                                        error={errors.attribute_group_id}
                                    />

                                    {/* Уведомление о конвертации */}
                                    {data._convert_to_select && (
                                        <Box
                                            p={4}
                                            bg="blue.50"
                                            borderRadius="md"
                                            borderWidth="1px"
                                            borderColor="blue.200"
                                        >
                                            <HStack gap={2}>
                                                <LuBookOpen size={18} color="var(--chakra-colors-blue-500)" />
                                                <Text color="blue.700" fontWeight="medium">
                                                    При сохранении все существующие значения товаров будут преобразованы в варианты справочника.
                                                </Text>
                                            </HStack>
                                        </Box>
                                    )}

                                    {data._convert_to_boolean && (
                                        <Box
                                            p={4}
                                            bg="orange.50"
                                            borderRadius="md"
                                            borderWidth="1px"
                                            borderColor="orange.200"
                                        >
                                            <HStack gap={2}>
                                                <LuBookOpen size={18} color="var(--chakra-colors-orange-500)" />
                                                <Text color="orange.700" fontWeight="medium">
                                                    При сохранении все существующие значения товаров будут преобразованы в Да/Нет.
                                                </Text>
                                            </HStack>
                                        </Box>
                                    )}

                                    {data.type === 'select' && !data._convert_to_select && (
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
                            submitLabel="Сохранить изменения"
                        />
                    </Card.Footer>
                </Card.Root>
            </form>

            {/* Диалог подтверждения конвертации в справочник */}
            <Dialog.Root
                role="alertdialog"
                open={convertDialogOpen}
                onOpenChange={(e) => {
                    if (!e.open) handleCancelConvert();
                }}
            >
                <Portal>
                    <Dialog.Backdrop />
                    <Dialog.Positioner>
                        <Dialog.Content>
                            <Dialog.Header>
                                <Dialog.Title>
                                    {pendingType === 'select'
                                        ? 'Конвертировать в справочник?'
                                        : 'Конвертировать в логический тип?'}
                                </Dialog.Title>
                            </Dialog.Header>
                            <Dialog.Body>
                                {pendingType === 'select' ? (
                                    <>
                                        <Text>
                                            Все уникальные значения товаров для этого атрибута будут автоматически
                                            добавлены как варианты выбора справочника.
                                        </Text>
                                        <Text mt={2} color="fg.muted" fontSize="sm">
                                            После конвертации при импорте товаров новые значения будут автоматически
                                            добавляться в справочник.
                                        </Text>
                                    </>
                                ) : (
                                    <>
                                        <Text>
                                            Все существующие значения товаров будут преобразованы в Да/Нет.
                                        </Text>
                                        <Text mt={2} color="fg.muted" fontSize="sm">
                                            Значения «Да», «Yes», «true», «1» будут считаться как «Да».
                                            Все остальные значения станут «Нет».
                                        </Text>
                                    </>
                                )}
                            </Dialog.Body>
                            <Dialog.Footer>
                                <Button variant="outline" onClick={handleCancelConvert}>
                                    Отмена
                                </Button>
                                <Button colorPalette="blue" onClick={handleConfirmConvert}>
                                    Конвертировать
                                </Button>
                            </Dialog.Footer>
                            <Dialog.CloseTrigger asChild>
                                <CloseButton size="sm" />
                            </Dialog.CloseTrigger>
                        </Dialog.Content>
                    </Dialog.Positioner>
                </Portal>
            </Dialog.Root>
        </>
    );
}

Edit.layout = (page) => <AdminLayout>{page}</AdminLayout>;
