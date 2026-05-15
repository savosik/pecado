import { useState, useMemo, useRef, useEffect } from 'react';
import {
    Box, HStack, Stack, Text, Input, IconButton, Button, Badge,
} from '@chakra-ui/react';
import { Tooltip } from '@/components/ui/tooltip';
import { LuGripVertical, LuX, LuPlus, LuSettings, LuChevronDown, LuCopy, LuSquareDashed } from 'react-icons/lu';
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

// Шлём код, а не сырой символ: Laravel TrimStrings обрезает пробелы и `\n`
// до того как значение дойдёт до контроллера, и разделители ломаются.
const SEPARATOR_OPTIONS = [
    { value: 'comma',           label: 'Запятая  ( , )' },
    { value: 'comma_tight',     label: 'Запятая без пробела  (,)' },
    { value: 'semicolon',       label: 'Точка с запятой  ( ; )' },
    { value: 'semicolon_tight', label: 'Точка с запятой без пробела  (;)' },
    { value: 'pipe',            label: 'Вертикальная черта  ( | )' },
    { value: 'newline',         label: 'Новая строка  (↵)' },
    { value: 'slash',           label: 'Слеш  ( / )' },
    { value: 'slash_tight',     label: 'Слеш без пробела  (/)' },
];

// Обёртка для строки модификатора: лейбл слева + контролы справа.
function ModifierRow({ label, hint, children }) {
    return (
        <HStack gap={3} align="center" flexWrap="wrap">
            <Text fontSize="xs" color="fg.muted" minW="140px" flexShrink={0}>
                {label}
            </Text>
            <HStack gap={2} align="center" flexWrap="wrap" flex={1}>
                {children}
            </HStack>
            {hint && <Text fontSize="xs" color="fg.subtle">{hint}</Text>}
        </HStack>
    );
}

// Тонкий разделитель между группами модификаторов в подложке.
function ModifierSep() {
    return <Box height="1px" bg="border.muted" my={2} />;
}

// Подблок для арифметических модификаторов: × multiply, + add,
// + флаг integer_if_whole (выводить целое если математика сошлась к целому).
// Используется и в `price`, и в `numeric` модификаторах.
function ArithmeticControls({ modifiers, onModifiersChange }) {
    const multiply = modifiers?.multiply ?? '';
    const add = modifiers?.add ?? '';
    const integerIfWhole = !!modifiers?.integer_if_whole;
    return (
        <Stack gap={2}>
            <ModifierRow
                label="Арифметика"
                hint="итог = (значение × умножитель) + прибавка"
            >
                <Text fontSize="xs" color="fg.subtle">×</Text>
                <Input
                    size="xs"
                    w="80px"
                    type="number"
                    step="any"
                    value={multiply}
                    placeholder="1"
                    onChange={(e) => onModifiersChange({
                        ...modifiers,
                        multiply: e.target.value === '' ? null : e.target.value,
                    })}
                />
                <Text fontSize="xs" color="fg.subtle">+</Text>
                <Input
                    size="xs"
                    w="80px"
                    type="number"
                    step="any"
                    value={add}
                    placeholder="0"
                    onChange={(e) => onModifiersChange({
                        ...modifiers,
                        add: e.target.value === '' ? null : e.target.value,
                    })}
                />
            </ModifierRow>
            <ModifierRow
                label="Округление"
                hint="980.00 → 980, но 980.50 без изменений"
            >
                <Badge
                    size="sm"
                    cursor="pointer"
                    variant={integerIfWhole ? 'solid' : 'outline'}
                    colorPalette={integerIfWhole ? 'pecado' : 'gray'}
                    onClick={() => onModifiersChange({ ...modifiers, integer_if_whole: !integerIfWhole })}
                    _hover={{ opacity: 0.8 }}
                >
                    Целое если без копеек
                </Badge>
            </ModifierRow>
        </Stack>
    );
}

// Substring — пост-обработка строкового значения (для любого modifierType).
// Полезен партнёрам, которым нужен UUID обрезанный до фиксированной длины.
function SubstringControls({ modifiers, onModifiersChange }) {
    const start = modifiers?.substring_start ?? '';
    const length = modifiers?.substring_length ?? '';
    return (
        <ModifierRow
            label="Обрезка строки"
            hint={length ? `с ${start || 0}-го символа, длина ${length}` : 'не обрезать'}
        >
            <Text fontSize="xs" color="fg.subtle">с</Text>
            <Input
                size="xs"
                w="60px"
                type="number"
                min="0"
                value={start}
                placeholder="0"
                onChange={(e) => onModifiersChange({
                    ...modifiers,
                    substring_start: e.target.value === '' ? null : parseInt(e.target.value),
                })}
            />
            <Text fontSize="xs" color="fg.subtle">длина</Text>
            <Input
                size="xs"
                w="80px"
                type="number"
                min="1"
                value={length}
                placeholder="—"
                onChange={(e) => onModifiersChange({
                    ...modifiers,
                    substring_length: e.target.value === '' ? null : parseInt(e.target.value),
                })}
            />
        </ModifierRow>
    );
}

// Date format — для полей с modifierType='date'.
const DATE_FORMAT_PRESETS = [
    { value: 'd.m.Y H:i:s', label: '08.04.2026 13:21:49' },
    { value: 'Y-m-d H:i:s', label: '2026-04-08 13:21:49' },
    { value: 'd.m.Y',       label: '08.04.2026' },
    { value: 'Y-m-d',       label: '2026-04-08' },
    { value: 'd/m/Y',       label: '08/04/2026' },
    { value: 'c',           label: 'ISO 8601 (2026-04-08T13:21:49+00:00)' },
];

function DateControls({ modifiers, onModifiersChange }) {
    const format = modifiers?.format || '';
    const isCustom = format && !DATE_FORMAT_PRESETS.some(p => p.value === format);
    return (
        <Stack gap={2}>
            <ModifierRow label="Формат даты">
                {DATE_FORMAT_PRESETS.map(p => (
                    <Badge
                        key={p.value}
                        size="sm"
                        cursor="pointer"
                        variant={format === p.value ? 'solid' : 'outline'}
                        colorPalette={format === p.value ? 'pecado' : 'gray'}
                        onClick={() => onModifiersChange({ ...modifiers, format: p.value })}
                        _hover={{ opacity: 0.8 }}
                    >
                        {p.label}
                    </Badge>
                ))}
                <Badge
                    size="sm"
                    cursor="pointer"
                    variant={isCustom ? 'solid' : 'outline'}
                    colorPalette={isCustom ? 'pecado' : 'gray'}
                    _hover={{ opacity: 0.8 }}
                    onClick={() => {
                        if (!isCustom) onModifiersChange({ ...modifiers, format: 'd.m.Y' });
                    }}
                >
                    Свой
                </Badge>
            </ModifierRow>
            {isCustom && (
                <ModifierRow
                    label="Шаблон формата"
                    hint="PHP date(): d=день, m=месяц, Y=год, H:i:s=ЧЧ:ММ:СС"
                >
                    <Input
                        size="xs"
                        w="200px"
                        value={format}
                        onChange={(e) => onModifiersChange({ ...modifiers, format: e.target.value })}
                        placeholder="d.m.Y H:i:s"
                    />
                </ModifierRow>
            )}
        </Stack>
    );
}

// ─── Inline modifier controls ─────────────────────────────────────────────────
function ModifierControls({ modifierType, modifiers, onModifiersChange, currencies = [] }) {
    // substring и null-modifierType: всё равно показываем SubstringControls,
    // т.к. это пост-модификатор поверх любого значения.
    if (!modifierType) {
        return <SubstringControls modifiers={modifiers || {}} onModifiersChange={onModifiersChange} />;
    }

    if (modifierType === 'price') {
        const currencyId = modifiers?.currency_id || null;
        return (
            <Stack gap={2}>
                {currencies.length > 0 && (
                    <ModifierRow label="Валюта">
                        <Badge
                            size="sm"
                            cursor="pointer"
                            variant={!currencyId ? 'solid' : 'outline'}
                            colorPalette={!currencyId ? 'pecado' : 'gray'}
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
                                colorPalette={currencyId === c.id ? 'pecado' : 'gray'}
                                onClick={() => onModifiersChange({ ...modifiers, currency_id: c.id })}
                                _hover={{ opacity: 0.8 }}
                            >
                                {c.symbol} {c.name}
                            </Badge>
                        ))}
                    </ModifierRow>
                )}
                <ArithmeticControls modifiers={modifiers} onModifiersChange={onModifiersChange} />
            </Stack>
        );
    }

    if (modifierType === 'numeric') {
        return (
            <ArithmeticControls modifiers={modifiers} onModifiersChange={onModifiersChange} />
        );
    }

    if (modifierType === 'boolean') {
        const trueVal = modifiers?.true_value ?? '';
        const falseVal = modifiers?.false_value ?? '';
        const isCustom = !BOOLEAN_PRESETS.some(p => p.true_value === trueVal && p.false_value === falseVal);

        return (
            <Stack gap={2}>
                <ModifierRow label="Формат значения">
                    {BOOLEAN_PRESETS.map((preset, i) => {
                        const active = preset.true_value === trueVal && preset.false_value === falseVal;
                        return (
                            <Badge
                                key={i}
                                size="sm"
                                cursor="pointer"
                                variant={active ? 'solid' : 'outline'}
                                colorPalette={active ? 'pecado' : 'gray'}
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
                        colorPalette={isCustom ? 'pecado' : 'gray'}
                        _hover={{ opacity: 0.8 }}
                        onClick={() => {
                            if (!isCustom) {
                                // Заходим в кастом-режим: «1 / пусто» — частый кейс
                                // партнёрских CSV (sex-opt пишет new=1, иначе пусто).
                                onModifiersChange({
                                    ...modifiers,
                                    true_value: trueVal === '' ? '1' : trueVal,
                                    false_value: '',
                                });
                            }
                        }}
                    >
                        Свои
                    </Badge>
                </ModifierRow>
                {isCustom && (
                    <ModifierRow label="Свои значения" hint="пустое поле = пустое значение в CSV">
                        <Text fontSize="xs" color="fg.subtle">истина</Text>
                        <Input
                            size="xs"
                            w="100px"
                            value={trueVal}
                            onChange={(e) => onModifiersChange({ ...modifiers, true_value: e.target.value })}
                            placeholder="(пусто)"
                        />
                        <Text fontSize="xs" color="fg.subtle">ложь</Text>
                        <Input
                            size="xs"
                            w="100px"
                            value={falseVal}
                            onChange={(e) => onModifiersChange({ ...modifiers, false_value: e.target.value })}
                            placeholder="(пусто)"
                        />
                    </ModifierRow>
                )}
            </Stack>
        );
    }

    if (modifierType === 'multi_value') {
        const separator = modifiers?.separator || 'comma';
        const sourceSep = modifiers?.source_separator || 'comma';
        return (
            <Stack gap={2}>
                <ModifierRow label="Разделитель на выходе">
                    {SEPARATOR_OPTIONS.map(opt => (
                        <Badge
                            key={opt.value}
                            size="sm"
                            cursor="pointer"
                            variant={separator === opt.value ? 'solid' : 'outline'}
                            colorPalette={separator === opt.value ? 'pecado' : 'gray'}
                            onClick={() => onModifiersChange({ ...modifiers, separator: opt.value })}
                            _hover={{ opacity: 0.8 }}
                        >
                            {opt.label}
                        </Badge>
                    ))}
                </ModifierRow>
                <ModifierRow label="Разделитель на входе" hint="как поле возвращает значения; по умолчанию — запятая">
                    {SEPARATOR_OPTIONS.filter(o => !o.value.endsWith('_tight')).map(opt => (
                        <Badge
                            key={opt.value}
                            size="sm"
                            cursor="pointer"
                            variant={sourceSep === opt.value ? 'solid' : 'outline'}
                            colorPalette={sourceSep === opt.value ? 'pecado' : 'gray'}
                            onClick={() => onModifiersChange({ ...modifiers, source_separator: opt.value })}
                            _hover={{ opacity: 0.8 }}
                        >
                            {opt.label.split(' ')[0]}
                        </Badge>
                    ))}
                </ModifierRow>
                <ModifierSep />
                <SubstringControls modifiers={modifiers || {}} onModifiersChange={onModifiersChange} />
            </Stack>
        );
    }

    if (modifierType === 'date') {
        return (
            <Stack gap={2}>
                <DateControls modifiers={modifiers || {}} onModifiersChange={onModifiersChange} />
                <ModifierSep />
                <SubstringControls modifiers={modifiers || {}} onModifiersChange={onModifiersChange} />
            </Stack>
        );
    }

    // Для price/numeric/boolean — добавим substring в конце
    return null;
}

// ─── Sortable row for a selected field ─────────────────────────────────────────
function SortableFieldRow({ item, index, defaultLabel, description, modifierType, currencies, onLabelChange, onModifiersChange, onRemove, onDuplicate }) {
    const hasModifiers = item.modifiers && Object.keys(item.modifiers).filter(k => {
        const v = item.modifiers[k];
        return v !== null && v !== undefined && v !== '' && v !== false;
    }).length > 0;
    const [showModifiers, setShowModifiers] = useState(hasModifiers);

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
            bg={isDragging ? 'pecado.subtle' : 'bg'}
            py={2}
            px={2}
            borderRadius="md"
            borderWidth="1px"
            borderColor={isDragging ? 'pecado.muted' : 'border.muted'}
            opacity={isDragging ? 0.5 : 1}
        >
            <HStack gap={2}>
                {/* Drag handle */}
                <Box
                    {...attributes}
                    {...listeners}
                    cursor="grab"
                    color="fg.subtle"
                    _hover={{ color: 'fg.muted' }}
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

                {/* Modifier toggle — активна когда панель открыта ИЛИ есть значения */}
                <Tooltip
                    content={hasModifiers ? 'Модификаторы заданы' : 'Настройки поля и модификаторы'}
                    openDelay={300}
                >
                    <IconButton
                        size="xs"
                        variant={hasModifiers || showModifiers ? 'subtle' : 'ghost'}
                        colorPalette={hasModifiers || showModifiers ? 'pecado' : 'gray'}
                        onClick={() => setShowModifiers(!showModifiers)}
                        aria-label="Настройки поля"
                        flexShrink={0}
                    >
                        <LuSettings />
                    </IconButton>
                </Tooltip>

                {/* Duplicate field (create alias copy) */}
                {onDuplicate && (
                    <Tooltip content="Скопировать (та же колонка с другим названием)" openDelay={300}>
                        <IconButton
                            size="xs"
                            variant="ghost"
                            colorPalette="gray"
                            onClick={() => onDuplicate(index)}
                            aria-label="Скопировать"
                            flexShrink={0}
                        >
                            <LuCopy />
                        </IconButton>
                    </Tooltip>
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

            {/* Modifier controls (collapsible) — рендерим всегда когда открыт */}
            {showModifiers && (
                <Box
                    mt={2}
                    ml={8}
                    mr={2}
                    p={3}
                    bg="bg.subtle"
                    borderRadius="md"
                    borderWidth="1px"
                    borderColor="border.muted"
                >
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
            bg="bg"
            borderWidth="1px"
            borderColor="border.muted"
            borderRadius="lg"
            boxShadow="lg"
            overflow="hidden"
        >
            <HStack align="stretch" gap={0} minH="320px">
                {/* Left: groups */}
                <Box
                    w="200px"
                    borderRightWidth="1px"
                    borderColor="border.muted"
                    bg="bg.subtle"
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
                            bg={activeGroup === group.group ? 'bg' : 'transparent'}
                            color={activeGroup === group.group ? 'pecado.fg' : 'fg'}
                            fontWeight={activeGroup === group.group ? 'bold' : 'normal'}
                            fontSize="sm"
                            _hover={{ bg: activeGroup === group.group ? 'bg' : 'bg.muted' }}
                            onClick={() => setActiveGroup(group.group)}
                            borderRightWidth={activeGroup === group.group ? '2px' : '0'}
                            borderRightColor="pecado.solid"
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
                                _hover={isAlreadySelected ? {} : { bg: 'pecado.subtle' }}
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
                                                field.modifier_type === 'boolean' ? 'pecado' :
                                                    field.modifier_type === 'numeric' ? 'blue' : 'orange'
                                        }>
                                            {field.modifier_type === 'price' ? '₽' :
                                                field.modifier_type === 'boolean' ? '✓/✗' :
                                                    field.modifier_type === 'numeric' ? '× +' : '⋯'}
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

    // Дубль поля: тот же базовый ключ + уникальный суффикс #N. Backend в
    // FieldRegistry::resolve() отрезает всё после `#` и резолвит базовое поле —
    // в итоге пользователь получает отдельную колонку с теми же данными
    // и (опционально) другим лейблом / модификатором.
    const handleDuplicate = (index) => {
        const source = selectedFields[index];
        const baseKey = source.key.split('#')[0];
        // Уникальный суффикс — короткий random чтобы не плодить #1/#2 коллизии
        const suffix = `alt${Math.random().toString(36).slice(2, 7)}`;
        const copy = {
            ...source,
            key: `${baseKey}#${suffix}`,
            label: source.label ? `${source.label} (копия)` : '',
        };
        const updated = [...selectedFields];
        updated.splice(index + 1, 0, copy);
        onChange(updated);
    };

    // Пустая колонка. Бэкенд резолвит placeholder.{slug} в EmptyPlaceholderField,
    // который всегда возвращает пустую строку. Полезно когда нужно сохранить
    // структуру колонок партнёрского CSV, но конкретное значение у нас отсутствует
    // в системе.
    const handleAddPlaceholder = () => {
        const slug = `col${Math.random().toString(36).slice(2, 7)}`;
        onChange([...selectedFields, { key: `placeholder.${slug}`, label: '' }]);
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
                        {selectedFields.map((item, index) => {
                            // Резолвим metaданные по базовому ключу (без алиас-суффикса)
                            const baseKey = item.key.split('#')[0];
                            const isPlaceholder = baseKey.startsWith('placeholder.');
                            const fallbackLabel = isPlaceholder ? 'Пустая колонка' : (labelMap[baseKey] || item.key);
                            return (
                                <SortableFieldRow
                                    key={item.key}
                                    item={item}
                                    index={index}
                                    defaultLabel={fallbackLabel}
                                    description={descriptionMap[baseKey]}
                                    modifierType={modifierTypeMap[baseKey]}
                                    currencies={currencies}
                                    onLabelChange={handleLabelChange}
                                    onModifiersChange={handleModifiersChange}
                                    onRemove={handleRemove}
                                    onDuplicate={isPlaceholder ? null : handleDuplicate}
                                />
                            );
                        })}
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

            {/* Add field / Empty column buttons + picker */}
            <Box position="relative">
                <HStack gap={2}>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setPickerOpen(!pickerOpen)}
                    >
                        <LuPlus /> Добавить поле
                    </Button>
                    <Tooltip
                        content="Добавляет колонку без данных — полезно когда нужно сохранить структуру CSV под партнёра, но конкретного значения у нас нет."
                        openDelay={300}
                    >
                        <Button
                            size="sm"
                            variant="outline"
                            colorPalette="gray"
                            onClick={handleAddPlaceholder}
                        >
                            <LuSquareDashed /> Пустая колонка
                        </Button>
                    </Tooltip>
                </HStack>

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
