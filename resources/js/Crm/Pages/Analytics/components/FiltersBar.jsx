import { useMemo, useState } from 'react';
import {
    Box, Flex, HStack, VStack, Text, Input, Button, IconButton, Wrap, WrapItem,
    Popover, Portal,
} from '@chakra-ui/react';
import { LuRotateCcw, LuChevronDown, LuChevronLeft, LuDownload, LuSearch, LuFilter } from 'react-icons/lu';
import { Checkbox } from '@/components/ui/checkbox';
import { ProductSelector } from '@/Admin/Components/ProductSelector';
import CategoryTreeSelect from './CategoryTreeSelect';

const COMPARE_MODES = [
    { value: 'none', label: 'Не сравнивать' },
    { value: 'prev_period', label: 'Предыдущий период' },
    { value: 'month', label: 'Прошлый месяц (те же даты)' },
    { value: 'quarter', label: 'Прошлый квартал' },
    { value: 'year', label: 'Прошлый год' },
];

function CompareControl({ mode, offset, onModeChange, onOffsetChange, loading }) {
    const current = COMPARE_MODES.find((m) => m.value === mode) ?? COMPARE_MODES[0];

    return (
        <HStack gap={2} align="end">
            <VStack align="stretch" gap={1} minW="210px">
                <Text fontSize="xs" color="fg.muted" fontWeight="500">Сравнение</Text>
                <Popover.Root positioning={{ sameWidth: true, placement: 'bottom-start' }}>
                    <Popover.Trigger asChild>
                        <Button variant="outline" size="sm" justifyContent="space-between" fontWeight="500" bg="bg" disabled={loading}>
                            <Text lineClamp={1}>{current.label}</Text>
                            <LuChevronDown />
                        </Button>
                    </Popover.Trigger>
                    <Portal>
                        <Popover.Positioner>
                            <Popover.Content>
                                <Popover.Body p={1}>
                                    <VStack align="stretch" gap={0}>
                                        {COMPARE_MODES.map((m) => (
                                            <Popover.CloseTrigger asChild key={m.value}>
                                                <Button
                                                    size="sm"
                                                    variant={m.value === mode ? 'subtle' : 'ghost'}
                                                    justifyContent="flex-start"
                                                    onClick={() => onModeChange(m.value)}
                                                >
                                                    {m.label}
                                                </Button>
                                            </Popover.CloseTrigger>
                                        ))}
                                    </VStack>
                                </Popover.Body>
                            </Popover.Content>
                        </Popover.Positioner>
                    </Portal>
                </Popover.Root>
            </VStack>
            {mode !== 'none' && (
                <VStack align="stretch" gap={1} w="92px">
                    <Text fontSize="xs" color="fg.muted" fontWeight="500">Назад</Text>
                    <Input
                        type="number"
                        size="sm"
                        min={1}
                        max={12}
                        value={offset}
                        onChange={(e) => {
                            const v = parseInt(e.target.value || '1', 10);
                            onOffsetChange(Number.isFinite(v) ? Math.max(1, Math.min(12, v)) : 1);
                        }}
                        bg="bg"
                    />
                </VStack>
            )}
        </HStack>
    );
}

const PRESETS = [
    { key: 'this-month', label: 'Текущий месяц' },
    { key: 'last-month', label: 'Прошлый месяц' },
    { key: '30d', label: '30 дней' },
    { key: 'quarter', label: 'Квартал' },
    { key: 'year', label: 'Год' },
    { key: 'all', label: 'Всё время' },
];

function presetRange(key) {
    const today = new Date();
    const fmt = (d) => d.toISOString().slice(0, 10);
    switch (key) {
        case 'this-month': {
            const from = new Date(today.getFullYear(), today.getMonth(), 1);
            return [fmt(from), fmt(today)];
        }
        case 'last-month': {
            const from = new Date(today.getFullYear(), today.getMonth() - 1, 1);
            const to = new Date(today.getFullYear(), today.getMonth(), 0);
            return [fmt(from), fmt(to)];
        }
        case '30d': {
            const from = new Date(today);
            from.setDate(today.getDate() - 29);
            return [fmt(from), fmt(today)];
        }
        case 'quarter': {
            const from = new Date(today);
            from.setDate(today.getDate() - 89);
            return [fmt(from), fmt(today)];
        }
        case 'year': {
            const from = new Date(today);
            from.setFullYear(today.getFullYear() - 1);
            return [fmt(from), fmt(today)];
        }
        case 'all':
        default:
            return ['2020-01-01', fmt(today)];
    }
}

function MultiSelect({ label, options, selectedIds, onChange, idKey = 'id', labelKey = 'name' }) {
    const [query, setQuery] = useState('');
    const selectedSet = useMemo(() => new Set(selectedIds.map(String)), [selectedIds]);
    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (!q) return options;
        return options.filter((o) => String(o[labelKey] ?? '').toLowerCase().includes(q));
    }, [options, query, labelKey]);

    const summary = selectedIds.length === 0
        ? 'Все'
        : selectedIds.length === 1
            ? (options.find((o) => String(o[idKey]) === String(selectedIds[0]))?.[labelKey] ?? '1')
            : `${selectedIds.length} выбрано`;

    const toggle = (id) => {
        const sid = String(id);
        const next = selectedSet.has(sid)
            ? selectedIds.filter((x) => String(x) !== sid)
            : [...selectedIds, id];
        onChange(next);
    };

    return (
        <VStack align="stretch" gap={1} flex="1" minW="200px">
            <Text fontSize="xs" color="fg.muted" fontWeight="500">{label}</Text>
            <Popover.Root positioning={{ sameWidth: true, placement: 'bottom-start' }}>
                <Popover.Trigger asChild>
                    <Button variant="outline" size="sm" justifyContent="space-between" fontWeight="500" bg="bg">
                        <Text lineClamp={1}>{summary}</Text>
                        <LuChevronDown />
                    </Button>
                </Popover.Trigger>
                <Portal>
                    <Popover.Positioner>
                        <Popover.Content>
                            <Popover.Body p={2}>
                                {options.length === 0 ? (
                                    <Text fontSize="sm" color="fg.muted" p={2}>Нет вариантов</Text>
                                ) : (
                                    <VStack align="stretch" gap={2}>
                                        <HStack gap={2} px={1}>
                                            <LuSearch size={14} />
                                            <Input
                                                size="xs"
                                                variant="flushed"
                                                placeholder="Поиск…"
                                                value={query}
                                                onChange={(e) => setQuery(e.target.value)}
                                            />
                                        </HStack>
                                        {selectedIds.length > 0 && (
                                            <Button size="xs" variant="ghost" onClick={() => onChange([])} justifyContent="flex-start">
                                                Сбросить выбор
                                            </Button>
                                        )}
                                        <Box maxH="300px" overflowY="auto">
                                            <VStack align="stretch" gap={1}>
                                                {filtered.length === 0 ? (
                                                    <Text fontSize="sm" color="fg.muted" p={2}>Ничего не найдено</Text>
                                                ) : filtered.map((opt) => (
                                                    <Checkbox
                                                        key={opt[idKey]}
                                                        checked={selectedSet.has(String(opt[idKey]))}
                                                        onCheckedChange={() => toggle(opt[idKey])}
                                                        size="sm"
                                                    >
                                                        <Text fontSize="sm" lineClamp={2}>{opt[labelKey]}</Text>
                                                    </Checkbox>
                                                ))}
                                            </VStack>
                                        </Box>
                                    </VStack>
                                )}
                            </Popover.Body>
                        </Popover.Content>
                    </Popover.Positioner>
                </Portal>
            </Popover.Root>
        </VStack>
    );
}

export default function FiltersBar({
    filters,
    filterOptions,
    onChange,
    onReset,
    onExport,
    loading,
    seesAll,
    products,
    onProductsChange,
    compareMode,
    compareOffset,
    onCompareModeChange,
    onCompareOffsetChange,
    onCollapse,
}) {
    const update = (patch) => onChange({ ...filters, ...patch });

    const applyPreset = (key) => {
        const [from, to] = presetRange(key);
        update({ date_from: from, date_to: to });
    };

    // Вертикальная панель фильтров для постоянной левой колонки: шапка со
    // сворачиванием, прокручиваемое тело с контролами и закреплённый низ с
    // действиями. Позиционирование (sticky/высоту) задаёт родитель в Index.
    return (
        <Box
            h="100%"
            display="flex"
            flexDirection="column"
            bg="bg.panel"
            borderRadius="xl"
            borderWidth="1px"
            borderColor="border"
            overflow="hidden"
        >
            <HStack
                justify="space-between"
                px={3}
                py={2}
                borderBottomWidth="1px"
                borderColor="border"
                flexShrink={0}
            >
                <HStack gap={2}>
                    <LuFilter size={16} />
                    <Text fontWeight="600">Фильтры</Text>
                </HStack>
                {onCollapse && (
                    <IconButton size="xs" variant="ghost" onClick={onCollapse} aria-label="Свернуть фильтры">
                        <LuChevronLeft />
                    </IconButton>
                )}
            </HStack>

            <Box flex="1" overflowY="auto" px={3} py={3}>
                <VStack align="stretch" gap={3}>
                    <Wrap gap={1}>
                        {PRESETS.map((p) => (
                            <WrapItem key={p.key}>
                                <Button size="xs" variant="surface" onClick={() => applyPreset(p.key)} disabled={loading}>
                                    {p.label}
                                </Button>
                            </WrapItem>
                        ))}
                    </Wrap>

                    <Flex gap={2} wrap="wrap">
                        <VStack align="stretch" gap={1} flex="1" minW="120px">
                            <Text fontSize="xs" color="fg.muted" fontWeight="500">С</Text>
                            <Input type="date" size="sm" value={filters.date_from || ''} onChange={(e) => update({ date_from: e.target.value })} bg="bg" />
                        </VStack>
                        <VStack align="stretch" gap={1} flex="1" minW="120px">
                            <Text fontSize="xs" color="fg.muted" fontWeight="500">По</Text>
                            <Input type="date" size="sm" value={filters.date_to || ''} onChange={(e) => update({ date_to: e.target.value })} bg="bg" />
                        </VStack>
                    </Flex>

                    {seesAll && (
                        <MultiSelect
                            label="Менеджер"
                            options={filterOptions.managers || []}
                            selectedIds={filters.manager_ids || []}
                            onChange={(ids) => update({ manager_ids: ids })}
                        />
                    )}
                    <MultiSelect
                        label="Контрагент"
                        options={filterOptions.companies || []}
                        selectedIds={filters.company_ids || []}
                        onChange={(ids) => update({ company_ids: ids })}
                    />
                    <MultiSelect
                        label="Бренд"
                        options={filterOptions.brands || []}
                        selectedIds={filters.brand_ids || []}
                        onChange={(ids) => update({ brand_ids: ids })}
                    />
                    <CategoryTreeSelect
                        tree={filterOptions.categories || []}
                        selectedIds={filters.category_ids || []}
                        onChange={(ids) => update({ category_ids: ids })}
                    />
                    <CompareControl
                        mode={compareMode}
                        offset={compareOffset}
                        onModeChange={onCompareModeChange}
                        onOffsetChange={onCompareOffsetChange}
                        loading={loading}
                    />
                    <VStack align="stretch" gap={1}>
                        <Text fontSize="xs" color="fg.muted" fontWeight="500">
                            Товары{products.length > 0 ? ` — выбрано ${products.length}` : ''}
                        </Text>
                        <ProductSelector
                            mode="multi"
                            value={products}
                            onChange={onProductsChange}
                            searchRoute="crm.products.search"
                        />
                    </VStack>
                </VStack>
            </Box>

            <HStack
                justify="space-between"
                px={3}
                py={2}
                borderTopWidth="1px"
                borderColor="border"
                flexShrink={0}
            >
                <Button size="xs" variant="ghost" onClick={onReset} disabled={loading}>
                    <LuRotateCcw /> Сбросить
                </Button>
                <Button size="xs" variant="outline" onClick={onExport} disabled={loading}>
                    <LuDownload /> XLSX
                </Button>
            </HStack>
        </Box>
    );
}
