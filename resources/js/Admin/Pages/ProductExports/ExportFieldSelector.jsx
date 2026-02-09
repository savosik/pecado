import { useState, useMemo, useRef, useEffect } from 'react';
import {
    Box, HStack, Stack, Text, Input, IconButton, Button, Badge,
} from '@chakra-ui/react';
import { Tooltip } from '@/components/ui/tooltip';
import { LuGripVertical, LuX, LuPlus, LuSettings, LuChevronDown } from 'react-icons/lu';
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

// ─── Modifier presets ──────────────────────────────────────────────────────────


const BOOLEAN_PRESETS = [
    { true_value: 'Да', false_value: 'Нет', label: 'Да / Нет' },
    { true_value: '1', false_value: '0', label: '1 / 0' },
    { true_value: 'true', false_value: 'false', label: 'true / false' },
    { true_value: '+', false_value: '-', label: '+ / −' },
    { true_value: 'Есть', false_value: 'Нет', label: 'Есть / Нет' },
];

const SEPARATOR_OPTIONS = [
    { value: ', ', label: 'Запятая  ( , )' },
    { value: '; ', label: 'Точка с запятой  ( ; )' },
    { value: ' | ', label: 'Вертикальная черта  ( | )' },
    { value: '\n', label: 'Новая строка  (↵)' },
    { value: ' / ', label: 'Слеш  ( / )' },
];

// ─── Inline modifier controls ─────────────────────────────────────────────────
function ModifierControls({ modifierType, modifiers, onModifiersChange, currencies = [] }) {
    if (!modifierType) return null;

    if (modifierType === 'price' && currencies.length > 0) {
        const currencyId = modifiers?.currency_id || null;
        return (
            <HStack gap={1} flexWrap="wrap" mt={1}>
                <Text fontSize="xs" color="gray.500" flexShrink={0}>Валюта:</Text>
                <Badge
                    size="sm"
                    cursor="pointer"
                    variant={!currencyId ? 'solid' : 'outline'}
                    colorPalette={!currencyId ? 'purple' : 'gray'}
                    onClick={() => onModifiersChange({ ...modifiers, currency_id: null })}
                    _hover={{ opacity: 0.8 }}
                >
                    Базовая ({currencies.find(c => c.is_base)?.code || 'RUB'})
                </Badge>
                {currencies.filter(c => !c.is_base).map(c => (
                    <Badge
                        key={c.id}
                        size="sm"
                        cursor="pointer"
                        variant={currencyId === c.id ? 'solid' : 'outline'}
                        colorPalette={currencyId === c.id ? 'purple' : 'gray'}
                        onClick={() => onModifiersChange({ ...modifiers, currency_id: c.id })}
                        _hover={{ opacity: 0.8 }}
                    >
                        {c.symbol} {c.name}
                    </Badge>
                ))}
            </HStack>
        );
    }

    if (modifierType === 'boolean') {
        const trueVal = modifiers?.true_value || 'Да';
        const falseVal = modifiers?.false_value || 'Нет';
        const isCustom = !BOOLEAN_PRESETS.some(p => p.true_value === trueVal && p.false_value === falseVal);

        return (
            <Stack gap={1} mt={1}>
                <HStack gap={1} flexWrap="wrap">
                    <Text fontSize="xs" color="gray.500" flexShrink={0}>Формат:</Text>
                    {BOOLEAN_PRESETS.map((preset, i) => {
                        const active = preset.true_value === trueVal && preset.false_value === falseVal;
                        return (
                            <Badge
                                key={i}
                                size="sm"
                                cursor="pointer"
                                variant={active ? 'solid' : 'outline'}
                                colorPalette={active ? 'purple' : 'gray'}
                                onClick={() => onModifiersChange({
                                    ...modifiers,
                                    true_value: preset.true_value,
                                    false_value: preset.false_value,
                                })}
                                _hover={{ opacity: 0.8 }}
                            >
                                {preset.label}
                            </Badge>
                        );
                    })}
                    <Badge
                        size="sm"
                        cursor="pointer"
                        variant={isCustom ? 'solid' : 'outline'}
                        colorPalette={isCustom ? 'purple' : 'gray'}
                        _hover={{ opacity: 0.8 }}
                    >
                        Свои
                    </Badge>
                </HStack>
                {isCustom && (
                    <HStack gap={2}>
                        <Input
                            size="xs"
                            w="80px"
                            value={trueVal}
                            onChange={(e) => onModifiersChange({ ...modifiers, true_value: e.target.value })}
                            placeholder="Истина"
                        />
                        <Text fontSize="xs" color="gray.400">/</Text>
                        <Input
                            size="xs"
                            w="80px"
                            value={falseVal}
                            onChange={(e) => onModifiersChange({ ...modifiers, false_value: e.target.value })}
                            placeholder="Ложь"
                        />
                    </HStack>
                )}
            </Stack>
        );
    }

    if (modifierType === 'multi_value') {
        const separator = modifiers?.separator || ', ';
        return (
            <HStack gap={1} flexWrap="wrap" mt={1}>
                <Text fontSize="xs" color="gray.500" flexShrink={0}>Разделитель:</Text>
                {SEPARATOR_OPTIONS.map(opt => (
                    <Badge
                        key={opt.value}
                        size="sm"
                        cursor="pointer"
                        variant={separator === opt.value ? 'solid' : 'outline'}
                        colorPalette={separator === opt.value ? 'purple' : 'gray'}
                        onClick={() => onModifiersChange({ ...modifiers, separator: opt.value })}
                        _hover={{ opacity: 0.8 }}
                    >
                        {opt.label}
                    </Badge>
                ))}
            </HStack>
        );
    }

    return null;
}

// ─── Sortable row for a selected field ─────────────────────────────────────────
function SortableFieldRow({ item, index, defaultLabel, description, modifierType, currencies, onLabelChange, onModifiersChange, onRemove }) {
    const [showModifiers, setShowModifiers] = useState(
        // Auto-open if modifiers are already set
        item.modifiers && Object.keys(item.modifiers).length > 0
    );

    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id: item.key });

    const style = {
        transform: CSS.Transform.toString(transform),
        transition,
    };

    return (
        <Box
            ref={setNodeRef}
            style={style}
            bg={isDragging ? 'purple.50' : 'white'}
            py={2}
            px={2}
            borderRadius="md"
            borderWidth="1px"
            borderColor={isDragging ? 'purple.200' : 'gray.200'}
            opacity={isDragging ? 0.5 : 1}
        >
            <HStack gap={2}>
                {/* Drag handle */}
                <Box
                    {...attributes}
                    {...listeners}
                    cursor="grab"
                    color="gray.400"
                    _hover={{ color: 'gray.600' }}
                    px={1}
                    flexShrink={0}
                >
                    <LuGripVertical size={18} />
                </Box>

                {/* Original field name (read-only) */}
                <Box flex={1}>
                    <Tooltip content={description || defaultLabel} openDelay={300}>
                        <Text fontSize="sm" color="fg.muted" cursor="default" py={1}>
                            {defaultLabel}
                        </Text>
                    </Tooltip>
                </Box>

                {/* Custom label (editable) */}
                <Box flex={1}>
                    <Input
                        value={item.label}
                        onChange={(e) => onLabelChange(index, e.target.value)}
                        size="sm"
                        placeholder={defaultLabel}
                    />
                </Box>

                {/* Modifier toggle */}
                {modifierType && (
                    <IconButton
                        size="xs"
                        variant="ghost"
                        color={showModifiers ? 'purple.500' : 'gray.400'}
                        _hover={{ color: 'purple.600', bg: 'purple.50' }}
                        onClick={() => setShowModifiers(!showModifiers)}
                        aria-label="Настройки поля"
                        flexShrink={0}
                    >
                        <LuSettings />
                    </IconButton>
                )}

                {/* Remove button */}
                <IconButton
                    size="xs"
                    variant="ghost"
                    colorPalette="red"
                    onClick={() => onRemove(index)}
                    aria-label="Удалить"
                    flexShrink={0}
                >
                    <LuX />
                </IconButton>
            </HStack>

            {/* Modifier controls (collapsible) */}
            {showModifiers && modifierType && (
                <Box pl={8} pr={2} pb={1}>
                    <ModifierControls
                        modifierType={modifierType}
                        modifiers={item.modifiers || {}}
                        onModifiersChange={(newMods) => onModifiersChange(index, newMods)}
                        currencies={currencies}
                    />
                </Box>
            )}
        </Box>
    );
}

/**
 * Grouped field picker popover.
 * Left column: groups, right column: fields of selected group.
 */
function FieldPicker({ availableFields, selectedKeys, onSelect, onClose }) {
    const [activeGroup, setActiveGroup] = useState(
        availableFields.length > 0 ? availableFields[0].group : ''
    );
    const pickerRef = useRef(null);

    // Close on outside click
    useEffect(() => {
        const handler = (e) => {
            if (pickerRef.current && !pickerRef.current.contains(e.target)) {
                onClose();
            }
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, [onClose]);

    const activeFields = useMemo(() => {
        const group = availableFields.find(g => g.group === activeGroup);
        return group ? group.fields : [];
    }, [availableFields, activeGroup]);

    return (
        <Box
            ref={pickerRef}
            position="absolute"
            zIndex={10}
            left={0}
            right={0}
            mt={1}
            bg="white"
            borderWidth="1px"
            borderColor="gray.200"
            borderRadius="lg"
            boxShadow="lg"
            overflow="hidden"
        >
            <HStack align="stretch" gap={0} minH="320px">
                {/* Left: groups */}
                <Box
                    w="200px"
                    borderRightWidth="1px"
                    borderColor="gray.100"
                    bg="gray.50"
                    overflowY="auto"
                    maxH="400px"
                    py={1}
                    flexShrink={0}
                >
                    {availableFields.map(group => (
                        <Box
                            key={group.group}
                            px={4}
                            py={2}
                            cursor="pointer"
                            bg={activeGroup === group.group ? 'white' : 'transparent'}
                            color={activeGroup === group.group ? 'blue.600' : 'fg'}
                            fontWeight={activeGroup === group.group ? 'bold' : 'normal'}
                            fontSize="sm"
                            _hover={{ bg: activeGroup === group.group ? 'white' : 'gray.100' }}
                            onClick={() => setActiveGroup(group.group)}
                            borderRightWidth={activeGroup === group.group ? '2px' : '0'}
                            borderRightColor="blue.500"
                        >
                            {group.group}
                        </Box>
                    ))}
                </Box>

                {/* Right: fields */}
                <Box flex={1} overflowY="auto" maxH="400px" py={1}>
                    {activeFields.map(field => {
                        const isAlreadySelected = selectedKeys.includes(field.key);
                        return (
                            <Box
                                key={field.key}
                                px={4}
                                py={2}
                                cursor={isAlreadySelected ? 'default' : 'pointer'}
                                opacity={isAlreadySelected ? 0.4 : 1}
                                _hover={isAlreadySelected ? {} : { bg: 'gray.50' }}
                                onClick={() => {
                                    if (!isAlreadySelected) {
                                        onSelect(field);
                                    }
                                }}
                            >
                                <HStack gap={2}>
                                    <Text fontSize="sm" fontWeight="medium">{field.label}</Text>
                                    {field.modifier_type && (
                                        <Badge size="xs" variant="subtle" colorPalette={
                                            field.modifier_type === 'price' ? 'green' :
                                                field.modifier_type === 'boolean' ? 'blue' : 'orange'
                                        }>
                                            {field.modifier_type === 'price' ? '₽' :
                                                field.modifier_type === 'boolean' ? '✓/✗' : '⋯'}
                                        </Badge>
                                    )}
                                </HStack>
                                {field.description && (
                                    <Text fontSize="xs" color="fg.muted" mt={0.5}>{field.description}</Text>
                                )}
                            </Box>
                        );
                    })}
                    {activeFields.length === 0 && (
                        <Text px={4} py={4} color="fg.muted" fontSize="sm">
                            В этой группе нет полей
                        </Text>
                    )}
                </Box>
            </HStack>
        </Box>
    );
}


/**
 * ExportFieldSelector
 *
 * Props:
 *   availableFields: [{group, fields: [{key, label, modifier_type?}]}]
 *   selectedFields: [{key, label, modifiers?}]
 *   onChange: (fields: {key, label, modifiers?}[]) => void
 */
export default function ExportFieldSelector({ availableFields, selectedFields, onChange, currencies = [] }) {
    const [pickerOpen, setPickerOpen] = useState(false);

    // Build maps from key -> default label, description, and modifier_type
    const { labelMap, descriptionMap, modifierTypeMap } = useMemo(() => {
        const lm = {};
        const dm = {};
        const mm = {};
        availableFields.forEach(group => {
            group.fields.forEach(f => {
                lm[f.key] = f.label;
                if (f.description) dm[f.key] = f.description;
                if (f.modifier_type) mm[f.key] = f.modifier_type;
            });
        });
        return { labelMap: lm, descriptionMap: dm, modifierTypeMap: mm };
    }, [availableFields]);

    const selectedKeys = useMemo(() => selectedFields.map(f => f.key), [selectedFields]);

    const sensors = useSensors(
        useSensor(PointerSensor),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        })
    );

    const handleDragEnd = (event) => {
        const { active, over } = event;
        if (active.id !== over?.id) {
            const oldIndex = selectedFields.findIndex(f => f.key === active.id);
            const newIndex = selectedFields.findIndex(f => f.key === over.id);
            onChange(arrayMove(selectedFields, oldIndex, newIndex));
        }
    };

    const handleLabelChange = (index, newLabel) => {
        const updated = [...selectedFields];
        updated[index] = { ...updated[index], label: newLabel };
        onChange(updated);
    };

    const handleModifiersChange = (index, newModifiers) => {
        const updated = [...selectedFields];
        updated[index] = { ...updated[index], modifiers: newModifiers };
        onChange(updated);
    };

    const handleRemove = (index) => {
        onChange(selectedFields.filter((_, i) => i !== index));
    };

    const handleSelect = (field) => {
        onChange([...selectedFields, { key: field.key, label: field.label }]);
        setPickerOpen(false);
    };

    return (
        <Stack gap={2}>
            {/* Header */}
            <HStack justify="space-between">
                <Text fontSize="xs" color="fg.muted">
                    Выбрано полей: {selectedFields.length}
                </Text>
            </HStack>

            {/* Sortable list */}
            <DndContext
                sensors={sensors}
                collisionDetection={closestCenter}
                onDragEnd={handleDragEnd}
            >
                <SortableContext
                    items={selectedFields.map(f => f.key)}
                    strategy={verticalListSortingStrategy}
                >
                    <Stack gap={1}>
                        {selectedFields.map((item, index) => (
                            <SortableFieldRow
                                key={item.key}
                                item={item}
                                index={index}
                                defaultLabel={labelMap[item.key] || item.key}
                                description={descriptionMap[item.key]}
                                modifierType={modifierTypeMap[item.key]}
                                currencies={currencies}
                                onLabelChange={handleLabelChange}
                                onModifiersChange={handleModifiersChange}
                                onRemove={handleRemove}
                            />
                        ))}
                    </Stack>
                </SortableContext>
            </DndContext>

            {selectedFields.length === 0 && (
                <Text
                    color="fg.muted"
                    textAlign="center"
                    py={6}
                    border="1px dashed"
                    borderColor="border.muted"
                    borderRadius="md"
                    fontSize="sm"
                >
                    Нет выбранных полей. Нажмите «Добавить поле» ниже.
                </Text>
            )}

            {/* Add field button + picker */}
            <Box position="relative">
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => setPickerOpen(!pickerOpen)}
                >
                    <LuPlus /> Добавить поле
                </Button>

                {pickerOpen && (
                    <FieldPicker
                        availableFields={availableFields}
                        selectedKeys={selectedKeys}
                        onSelect={handleSelect}
                        onClose={() => setPickerOpen(false)}
                    />
                )}
            </Box>
        </Stack>
    );
}
