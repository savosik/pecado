import { useMemo, useState } from 'react';
import {
    Box, Flex, HStack, VStack, Text, Input, Button, Wrap, WrapItem,
    Popover, Portal,
} from '@chakra-ui/react';
import { LuFilter, LuRotateCcw, LuChevronDown, LuDownload, LuSearch, LuPackageSearch } from 'react-icons/lu';
import { Checkbox } from '@/components/ui/checkbox';
import { Switch } from '@/components/ui/switch';
import { ProductSelector } from '@/Admin/Components/ProductSelector';
import CategoryTreeSelect from './CategoryTreeSelect';

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
    compare,
    onCompareChange,
}) {
    const update = (patch) => onChange({ ...filters, ...patch });

    const applyPreset = (key) => {
        const [from, to] = presetRange(key);
        update({ date_from: from, date_to: to });
    };

    return (
        <Box bg="bg.panel" borderRadius="xl" borderWidth="1px" borderColor="border" p={4}>
            <VStack align="stretch" gap={3}>
                <HStack justify="space-between" wrap="wrap" gap={2}>
                    <HStack gap={2}>
                        <LuFilter size={16} />
                        <Text fontWeight="600">Фильтры</Text>
                    </HStack>
                    <HStack gap={3} wrap="wrap">
                        <Switch checked={compare} onCheckedChange={(e) => onCompareChange(e.checked)} disabled={loading}>
                            <Text fontSize="sm">Сравнить с прошлым периодом</Text>
                        </Switch>
                        <Button size="xs" variant="ghost" onClick={onReset} disabled={loading}>
                            <LuRotateCcw /> Сбросить
                        </Button>
                        <Button size="xs" variant="outline" onClick={onExport} disabled={loading}>
                            <LuDownload /> XLSX
                        </Button>
                    </HStack>
                </HStack>

                <Wrap gap={2}>
                    {PRESETS.map((p) => (
                        <WrapItem key={p.key}>
                            <Button size="xs" variant="surface" onClick={() => applyPreset(p.key)} disabled={loading}>
                                {p.label}
                            </Button>
                        </WrapItem>
                    ))}
                </Wrap>

                <Flex gap={3} wrap="wrap">
                    <VStack align="stretch" gap={1} flex="1" minW="150px">
                        <Text fontSize="xs" color="fg.muted" fontWeight="500">С</Text>
                        <Input type="date" size="sm" value={filters.date_from || ''} onChange={(e) => update({ date_from: e.target.value })} bg="bg" />
                    </VStack>
                    <VStack align="stretch" gap={1} flex="1" minW="150px">
                        <Text fontSize="xs" color="fg.muted" fontWeight="500">По</Text>
                        <Input type="date" size="sm" value={filters.date_to || ''} onChange={(e) => update({ date_to: e.target.value })} bg="bg" />
                    </VStack>

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
                </Flex>

                <Box borderTopWidth="1px" borderColor="border" pt={3}>
                    <HStack gap={2} mb={2} color="fg.muted">
                        <LuPackageSearch size={16} />
                        <Text fontSize="sm" fontWeight="500">Товары</Text>
                        {products.length > 0 && (
                            <Text fontSize="xs">— выбрано {products.length}</Text>
                        )}
                    </HStack>
                    <ProductSelector
                        mode="multi"
                        value={products}
                        onChange={onProductsChange}
                        searchRoute="crm.products.search"
                    />
                    <Text fontSize="xs" color="fg.muted" mt={1}>
                        Начните вводить название или артикул и выберите товары — отчёт отфильтруется по ним.
                    </Text>
                </Box>
            </VStack>
        </Box>
    );
}
