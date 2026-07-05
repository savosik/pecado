import React, { useMemo, useState } from 'react';
import {
    Box,
    Button,
    HStack,
    VStack,
    Text,
    Badge,
    Input,
    Grid,
    createListCollection,
} from '@chakra-ui/react';
import { LuFilter, LuX } from 'react-icons/lu';
import {
    SelectRoot,
    SelectTrigger,
    SelectContent,
    SelectItem,
    SelectValueText,
} from '@/components/ui/select';
import { Checkbox } from '@/components/ui/checkbox';
import { MultiEntitySelector } from '@/Admin/Components';

const IMAGE_OPTIONS = createListCollection({
    items: [
        { label: 'Все', value: '' },
        { label: 'С фото', value: 'with' },
        { label: 'Без фото', value: 'without' },
    ],
});

const DESCRIPTION_OPTIONS = createListCollection({
    items: [
        { label: 'Все', value: '' },
        { label: 'С описанием', value: 'with' },
        { label: 'Без описания', value: 'without' },
    ],
});

const HIDDEN_OPTIONS = createListCollection({
    items: [
        { label: 'Все', value: '' },
        { label: 'Скрытые', value: 'yes' },
        { label: 'Видимые', value: 'no' },
    ],
});

const STOCK_OPTIONS = createListCollection({
    items: [
        { label: 'Все', value: '' },
        { label: 'В наличии', value: 'in' },
        { label: 'Нет в наличии', value: 'out' },
    ],
});

const FLAG_OPTIONS = [
    { value: 'is_new', label: 'Новинка' },
    { value: 'is_bestseller', label: 'Хит продаж' },
    { value: 'is_liquidation', label: 'Ликвидация' },
    { value: 'is_marked', label: 'Маркировка' },
    { value: 'for_marketplaces', label: 'Для маркетплейсов' },
];

/** Небольшая обёртка над Select для одиночного выбора значения-строки. */
function FilterSelect({ label, collection, value, onChange }) {
    return (
        <SelectRoot
            collection={collection}
            value={[value ?? '']}
            onValueChange={(e) => onChange(e.value[0] ?? '')}
            size="sm"
        >
            <Text fontSize="sm" fontWeight="medium" mb={1}>{label}</Text>
            <SelectTrigger>
                <SelectValueText placeholder="Все" />
            </SelectTrigger>
            <SelectContent>
                {collection.items.map((item) => (
                    <SelectItem key={item.value} item={item}>
                        {item.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </SelectRoot>
    );
}

/**
 * ProductsFilterPanel — сворачиваемая панель фильтров каталога в админке.
 *
 * Держит локальный черновик фильтров; применение — через onApply(changes),
 * очистка — через onClear. Кнопка-триггер показывает число активных фильтров
 * (считается по применённым filters, а не по черновику).
 *
 * @param {Object} filters - Применённые фильтры из Inertia props
 * @param {Function} onApply - Применить фильтры (получает объект параметров)
 * @param {Function} onClear - Сбросить все фильтры
 */
export function ProductsFilterPanel({ filters, onApply, onClear }) {
    const [open, setOpen] = useState(false);

    const [draft, setDraft] = useState({
        brands: filters.brands_selected || [],
        categories: filters.categories_selected || [],
        tags: filters.tags_selected || [],
        images: filters.images || '',
        description_filter: filters.description_filter || '',
        hidden: filters.hidden || '',
        price_min: filters.price_min ?? '',
        price_max: filters.price_max ?? '',
        flags: filters.flags || [],
        stock: filters.stock || '',
    });

    const set = (patch) => setDraft((d) => ({ ...d, ...patch }));

    const toggleFlag = (flag, checked) => {
        setDraft((d) => ({
            ...d,
            flags: checked ? [...d.flags, flag] : d.flags.filter((f) => f !== flag),
        }));
    };

    // Число активных (применённых) фильтров для бейджа
    const activeCount = useMemo(() => {
        let n = 0;
        if (filters.brands?.length) n++;
        if (filters.categories?.length) n++;
        if (filters.tags?.length) n++;
        if (filters.images) n++;
        if (filters.description_filter) n++;
        if (filters.hidden) n++;
        if (filters.price_min || filters.price_max) n++;
        if (filters.flags?.length) n++;
        if (filters.stock) n++;
        return n;
    }, [filters]);

    const apply = () => {
        onApply({
            brands: draft.brands.length ? draft.brands.map((b) => b.id) : undefined,
            categories: draft.categories.length ? draft.categories.map((c) => c.id) : undefined,
            tags: draft.tags.length ? draft.tags.map((t) => t.id) : undefined,
            images: draft.images || undefined,
            description_filter: draft.description_filter || undefined,
            hidden: draft.hidden || undefined,
            price_min: draft.price_min !== '' ? draft.price_min : undefined,
            price_max: draft.price_max !== '' ? draft.price_max : undefined,
            flags: draft.flags.length ? draft.flags : undefined,
            stock: draft.stock || undefined,
        });
    };

    const clear = () => {
        setDraft({
            brands: [], categories: [], tags: [],
            images: '', description_filter: '', hidden: '',
            price_min: '', price_max: '', flags: [], stock: '',
        });
        onClear();
    };

    return (
        <Box mb={4}>
            <Button variant="outline" onClick={() => setOpen((v) => !v)}>
                <LuFilter /> Фильтры
                {activeCount > 0 && (
                    <Badge colorPalette="blue" ml={2}>{activeCount}</Badge>
                )}
            </Button>

            {open && (
                <Box
                    mt={2}
                    bg="bg.subtle"
                    borderWidth="1px"
                    borderColor="border.muted"
                    borderRadius="md"
                    p={4}
                >
                    <VStack align="stretch" gap={4}>
                        <Grid templateColumns={{ base: '1fr', md: 'repeat(2, 1fr)', lg: 'repeat(3, 1fr)' }} gap={4}>
                            <Box>
                                <Text fontSize="sm" fontWeight="medium" mb={1}>Бренды</Text>
                                <MultiEntitySelector
                                    value={draft.brands}
                                    onChange={(v) => set({ brands: v })}
                                    searchUrl="admin.brands.search"
                                    placeholder="Поиск бренда..."
                                />
                            </Box>
                            <Box>
                                <Text fontSize="sm" fontWeight="medium" mb={1}>Категории</Text>
                                <MultiEntitySelector
                                    value={draft.categories}
                                    onChange={(v) => set({ categories: v })}
                                    searchUrl="admin.categories.search"
                                    placeholder="Поиск категории..."
                                />
                            </Box>
                            <Box>
                                <Text fontSize="sm" fontWeight="medium" mb={1}>Теги</Text>
                                <MultiEntitySelector
                                    value={draft.tags}
                                    onChange={(v) => set({ tags: v })}
                                    searchUrl="admin.tags.search"
                                    placeholder="Поиск тега..."
                                />
                            </Box>

                            <FilterSelect
                                label="Фото"
                                collection={IMAGE_OPTIONS}
                                value={draft.images}
                                onChange={(v) => set({ images: v })}
                            />
                            <FilterSelect
                                label="Описание"
                                collection={DESCRIPTION_OPTIONS}
                                value={draft.description_filter}
                                onChange={(v) => set({ description_filter: v })}
                            />
                            <FilterSelect
                                label="Видимость"
                                collection={HIDDEN_OPTIONS}
                                value={draft.hidden}
                                onChange={(v) => set({ hidden: v })}
                            />
                            <FilterSelect
                                label="Наличие"
                                collection={STOCK_OPTIONS}
                                value={draft.stock}
                                onChange={(v) => set({ stock: v })}
                            />

                            <Box>
                                <Text fontSize="sm" fontWeight="medium" mb={1}>Цена, ₽</Text>
                                <HStack gap={2}>
                                    <Input
                                        type="number"
                                        size="sm"
                                        placeholder="от"
                                        value={draft.price_min}
                                        onChange={(e) => set({ price_min: e.target.value })}
                                    />
                                    <Input
                                        type="number"
                                        size="sm"
                                        placeholder="до"
                                        value={draft.price_max}
                                        onChange={(e) => set({ price_max: e.target.value })}
                                    />
                                </HStack>
                            </Box>
                        </Grid>

                        <Box>
                            <Text fontSize="sm" fontWeight="medium" mb={2}>Флаги</Text>
                            <HStack gap={4} wrap="wrap">
                                {FLAG_OPTIONS.map((flag) => (
                                    <Checkbox
                                        key={flag.value}
                                        checked={draft.flags.includes(flag.value)}
                                        onCheckedChange={(e) => toggleFlag(flag.value, e.checked)}
                                    >
                                        {flag.label}
                                    </Checkbox>
                                ))}
                            </HStack>
                        </Box>

                        <HStack gap={3}>
                            <Button colorPalette="blue" onClick={apply}>
                                Применить
                            </Button>
                            <Button variant="ghost" onClick={clear}>
                                <LuX /> Очистить фильтры
                            </Button>
                        </HStack>
                    </VStack>
                </Box>
            )}
        </Box>
    );
}

export default ProductsFilterPanel;
