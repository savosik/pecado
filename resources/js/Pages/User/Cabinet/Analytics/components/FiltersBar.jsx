import { useMemo } from 'react';
import {
    Box, Flex, HStack, VStack, Text, Input, Button, Wrap, WrapItem,
    Popover, Portal,
} from '@chakra-ui/react';
import { LuFilter, LuRotateCcw, LuChevronDown, LuDownload } from 'react-icons/lu';
import { Checkbox } from '@/components/ui/checkbox';

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

function MultiSelect({ label, options, selectedIds: selectedIdsProp, onChange, idKey = 'id', labelKey = 'name' }) {
    // Нормализация обязательна: пресет или initial.filters могут прислать null
    // вместо массива (дефолт параметра ловит только undefined), и без неё
    // падает вся панель фильтров.
    const selectedIds = selectedIdsProp ?? [];
    const selectedSet = useMemo(() => new Set((selectedIdsProp ?? []).map(String)), [selectedIdsProp]);
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
                    <Button
                        variant="outline"
                        size="sm"
                        justifyContent="space-between"
                        fontWeight="500"
                        bg="bg"
                    >
                        <Text lineClamp={1}>{summary}</Text>
                        <LuChevronDown />
                    </Button>
                </Popover.Trigger>
                <Portal>
                    <Popover.Positioner>
                        <Popover.Content>
                            <Popover.Body p={2} maxH="320px" overflowY="auto">
                                {options.length === 0 ? (
                                    <Text fontSize="sm" color="fg.muted" p={2}>Нет вариантов</Text>
                                ) : (
                                    <VStack align="stretch" gap={1}>
                                        {selectedIds.length > 0 && (
                                            <Button
                                                size="xs"
                                                variant="ghost"
                                                onClick={() => onChange([])}
                                                justifyContent="flex-start"
                                            >
                                                Сбросить выбор
                                            </Button>
                                        )}
                                        {options.map((opt) => (
                                            <Checkbox
                                                key={opt[idKey]}
                                                checked={selectedSet.has(String(opt[idKey]))}
                                                onCheckedChange={() => toggle(opt[idKey])}
                                            >
                                                <Text fontSize="sm" lineClamp={2}>
                                                    {opt[labelKey]}
                                                </Text>
                                            </Checkbox>
                                        ))}
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
}) {
    const update = (patch) => onChange({ ...filters, ...patch });

    const applyPreset = (key) => {
        const [from, to] = presetRange(key);
        update({ date_from: from, date_to: to });
    };

    return (
        <Box bg="bg.panel" borderRadius="xl" borderWidth="1px" borderColor="border" p={4}>
            <VStack align="stretch" gap={3}>
                <HStack justify="space-between" wrap="wrap">
                    <HStack gap={2}>
                        <LuFilter size={16} />
                        <Text fontWeight="600">Фильтры</Text>
                    </HStack>
                    <HStack gap={2}>
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
                            <Button
                                size="xs"
                                variant="surface"
                                onClick={() => applyPreset(p.key)}
                                disabled={loading}
                            >
                                {p.label}
                            </Button>
                        </WrapItem>
                    ))}
                </Wrap>

                <Flex gap={3} wrap="wrap">
                    <VStack align="stretch" gap={1} flex="1" minW="160px">
                        <Text fontSize="xs" color="fg.muted" fontWeight="500">С</Text>
                        <Input
                            type="date"
                            size="sm"
                            value={filters.date_from || ''}
                            onChange={(e) => update({ date_from: e.target.value })}
                            bg="bg"
                        />
                    </VStack>
                    <VStack align="stretch" gap={1} flex="1" minW="160px">
                        <Text fontSize="xs" color="fg.muted" fontWeight="500">По</Text>
                        <Input
                            type="date"
                            size="sm"
                            value={filters.date_to || ''}
                            onChange={(e) => update({ date_to: e.target.value })}
                            bg="bg"
                        />
                    </VStack>

                    <MultiSelect
                        label="Контрагент"
                        options={filterOptions.companies}
                        selectedIds={filters.company_ids}
                        onChange={(ids) => update({ company_ids: ids })}
                    />
                    <MultiSelect
                        label="Бренд"
                        options={filterOptions.brands}
                        selectedIds={filters.brand_ids}
                        onChange={(ids) => update({ brand_ids: ids })}
                    />
                    <MultiSelect
                        label="Категория"
                        options={filterOptions.categories}
                        selectedIds={filters.category_ids}
                        onChange={(ids) => update({ category_ids: ids })}
                    />

                    <VStack align="stretch" gap={1} flex="1" minW="160px">
                        <Text fontSize="xs" color="fg.muted" fontWeight="500">Артикул</Text>
                        <Input
                            size="sm"
                            placeholder="SKU"
                            value={filters.sku || ''}
                            onChange={(e) => update({ sku: e.target.value })}
                            bg="bg"
                        />
                    </VStack>
                </Flex>
            </VStack>
        </Box>
    );
}
