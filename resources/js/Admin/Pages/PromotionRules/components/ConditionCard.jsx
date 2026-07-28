import { useEffect, useMemo, useState } from 'react';
import axios from 'axios';
import { Box, Card, HStack, VStack, Text, Input, IconButton, Badge, SimpleGrid } from '@chakra-ui/react';
import { LuTrash2, LuChevronDown, LuChevronRight, LuX } from 'react-icons/lu';
import { FormField, ProductSelector, MultiEntitySelector, TagSelector } from '@/Admin/Components';
import { Checkbox } from '@/components/ui/checkbox';
import { NativeSelectRoot, NativeSelectField } from '@/components/ui/native-select';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { aggregateSuffix, conditionSummary } from './ruleState';

const OPERATORS = [
    { value: '>=', label: 'не меньше (≥)' },
    { value: '>', label: 'больше (>)' },
    { value: '=', label: 'ровно (=)' },
    { value: '<=', label: 'не больше (≤)' },
    { value: '<', label: 'меньше (<)' },
];

/** Критерии отбора товаров: показываем только заполненные, остальные — по кнопке. */
const CRITERIA = [
    { key: 'products', label: 'Товары' },
    { key: 'categories', label: 'Категории' },
    { key: 'brands', label: 'Бренды' },
    { key: 'tags', label: 'Теги' },
    { key: 'erp_promotions', label: 'Группы товаров из 1С' },
    { key: 'whole_cart', label: 'Вся корзина' },
];

const isFilled = (selector, key) => (key === 'whole_cart'
    ? Boolean(selector.whole_cart)
    : Boolean(selector[key]?.length));

/**
 * Одно условие срабатывания: что считаем, по каким товарам и с каким порогом.
 *
 * Карточка сворачивается в строку-подпись — в правиле их может быть полтора
 * десятка. Внутри показываются только заданные критерии отбора: в реальной
 * акции почти все они остаются пустыми.
 */
export default function ConditionCard({
    index,
    condition,
    onChange,
    onRemove,
    erpPromotionTypes = [],
    defaultOpen = false,
}) {
    const [open, setOpen] = useState(defaultOpen);
    const [matchCount, setMatchCount] = useState(null);
    const [extraCriteria, setExtraCriteria] = useState([]);
    const selector = condition.selector;

    const patchSelector = (patch) => onChange({ ...condition, selector: { ...selector, ...patch } });

    // Живая подсказка «сейчас под условие подходит N товаров»
    useEffect(() => {
        const timer = setTimeout(async () => {
            try {
                const { data } = await axios.post(route('admin.promotion-rules.match-count'), {
                    selector: {
                        products: selector.products.map((p) => p.id),
                        categories: selector.categories.map((c) => c.id),
                        with_descendants: selector.with_descendants,
                        brands: selector.brands.map((b) => b.id),
                        tags: selector.tags,
                        erp_promotions: selector.erp_promotions,
                        whole_cart: selector.whole_cart,
                    },
                });
                setMatchCount(data);
            } catch {
                setMatchCount(null);
            }
        }, 400);

        return () => clearTimeout(timer);
    }, [selector]);

    const activeCriteria = useMemo(
        () => CRITERIA.filter(({ key }) => isFilled(selector, key) || extraCriteria.includes(key)),
        [selector, extraCriteria],
    );

    const availableCriteria = CRITERIA.filter(({ key }) => !activeCriteria.some((c) => c.key === key));

    const addCriterion = (key) => {
        if (key === 'whole_cart') {
            patchSelector({ whole_cart: true });
            setExtraCriteria([]);

            return;
        }

        setExtraCriteria((current) => [...current, key]);
    };

    const removeCriterion = (key) => {
        setExtraCriteria((current) => current.filter((item) => item !== key));

        patchSelector(key === 'whole_cart'
            ? { whole_cart: false }
            : { [key]: [] });
    };

    const toggleErpPromotion = (type) => {
        const current = selector.erp_promotions || [];
        patchSelector({
            erp_promotions: current.includes(type)
                ? current.filter((t) => t !== type)
                : [...current, type],
        });
    };

    const criterionBody = (key) => {
        switch (key) {
            case 'products':
                return (
                    <ProductSelector
                        value={selector.products}
                        onChange={(products) => patchSelector({ products })}
                        compactSelected
                    />
                );
            case 'categories':
                return (
                    <VStack align="stretch" gap={2}>
                        <MultiEntitySelector
                            value={selector.categories}
                            onChange={(categories) => patchSelector({ categories })}
                            searchUrl="admin.categories.search"
                            placeholder="Начните вводить категорию..."
                        />
                        <Checkbox
                            checked={selector.with_descendants}
                            onCheckedChange={(e) => patchSelector({ with_descendants: e.checked })}
                        >
                            Включая подкатегории
                        </Checkbox>
                    </VStack>
                );
            case 'brands':
                return (
                    <MultiEntitySelector
                        value={selector.brands}
                        onChange={(brands) => patchSelector({ brands })}
                        searchUrl="admin.brands.search"
                        placeholder="Начните вводить бренд..."
                    />
                );
            case 'tags':
                return <TagSelector value={selector.tags} onChange={(tags) => patchSelector({ tags })} />;
            case 'erp_promotions':
                return (
                    <HStack gap={4} wrap="wrap">
                        {erpPromotionTypes.map((type) => (
                            <Checkbox
                                key={type.value}
                                checked={(selector.erp_promotions || []).includes(type.value)}
                                onCheckedChange={() => toggleErpPromotion(type.value)}
                            >
                                {type.label}
                            </Checkbox>
                        ))}
                    </HStack>
                );
            case 'whole_cart':
                return (
                    <Text fontSize="sm" color="fg.muted">
                        Условие считается по всем позициям корзины. Остальные критерии при этом не нужны.
                    </Text>
                );
            default:
                return null;
        }
    };

    return (
        <Card.Root borderWidth="1px">
            <Card.Body py={3}>
                <VStack align="stretch" gap={open ? 4 : 0}>
                    <HStack justify="space-between" gap={3}>
                        <HStack gap={2} flex="1" minW={0} align="start">
                            <IconButton
                                aria-label={open ? 'Свернуть условие' : 'Развернуть условие'}
                                type="button"
                                size="xs"
                                variant="ghost"
                                onClick={() => setOpen((value) => !value)}
                            >
                                {open ? <LuChevronDown /> : <LuChevronRight />}
                            </IconButton>

                            <Box flex="1" minW={0} cursor="pointer" onClick={() => setOpen((value) => !value)}>
                                <Text fontSize="xs" color="fg.muted">Условие {index + 1}</Text>
                                <Text fontSize="sm" fontWeight="medium">{conditionSummary(condition)}</Text>
                            </Box>
                        </HStack>

                        <HStack gap={2}>
                            {matchCount && !matchCount.whole_cart && (
                                <Badge colorPalette={matchCount.count > 0 ? 'blue' : 'orange'} variant="subtle">
                                    товаров: {matchCount.count}
                                </Badge>
                            )}
                            <IconButton
                                aria-label="Удалить условие"
                                type="button"
                                size="sm"
                                variant="ghost"
                                colorPalette="red"
                                onClick={onRemove}
                            >
                                <LuTrash2 />
                            </IconButton>
                        </HStack>
                    </HStack>

                    {open && (
                        <>
                            <VStack align="stretch" gap={3}>
                                <Text fontSize="sm" fontWeight="medium">Что попадает под условие</Text>

                                {activeCriteria.length === 0 && (
                                    <Text fontSize="sm" color="fg.muted">
                                        Критерии не заданы — добавьте хотя бы один, иначе правило не сработает.
                                    </Text>
                                )}

                                {activeCriteria.map(({ key, label }) => (
                                    <Box key={key}>
                                        <HStack justify="space-between" mb={1}>
                                            <Text fontSize="sm" color="fg.muted">{label}</Text>
                                            <IconButton
                                                aria-label={`Убрать критерий «${label}»`}
                                                type="button"
                                                size="2xs"
                                                variant="ghost"
                                                onClick={() => removeCriterion(key)}
                                            >
                                                <LuX />
                                            </IconButton>
                                        </HStack>
                                        {criterionBody(key)}
                                    </Box>
                                ))}

                                {availableCriteria.length > 0 && !selector.whole_cart && (
                                    <Box maxW="260px">
                                        <NativeSelectRoot size="sm">
                                            <NativeSelectField
                                                value=""
                                                onChange={(e) => e.target.value && addCriterion(e.target.value)}
                                            >
                                                <option value="">+ ещё критерий</option>
                                                {availableCriteria.map(({ key, label }) => (
                                                    <option key={key} value={key}>{label}</option>
                                                ))}
                                            </NativeSelectField>
                                        </NativeSelectRoot>
                                    </Box>
                                )}
                            </VStack>

                            <SimpleGrid columns={{ base: 1, md: 4 }} gap={4}>
                                <FormField label="Считать">
                                    <SegmentedControl
                                        value={condition.aggregate}
                                        onValueChange={(e) => onChange({ ...condition, aggregate: e.value })}
                                        items={[
                                            { value: 'quantity', label: 'Штуки' },
                                            { value: 'amount', label: 'Сумму' },
                                        ]}
                                    />
                                </FormField>

                                <FormField label="Сравнение">
                                    <NativeSelectRoot>
                                        <NativeSelectField
                                            value={condition.operator}
                                            onChange={(e) => onChange({ ...condition, operator: e.target.value })}
                                        >
                                            {OPERATORS.map((operator) => (
                                                <option key={operator.value} value={operator.value}>
                                                    {operator.label}
                                                </option>
                                            ))}
                                        </NativeSelectField>
                                    </NativeSelectRoot>
                                </FormField>

                                <FormField
                                    label={`Порог, ${aggregateSuffix(condition.aggregate)}`}
                                    helpText={condition.aggregate === 'amount'
                                        ? 'По индивидуальным ценам клиента со скидкой'
                                        : 'Количество штук в корзине'}
                                >
                                    <Input
                                        type="number"
                                        min={0}
                                        step={condition.aggregate === 'amount' ? '0.01' : '1'}
                                        value={condition.value}
                                        onChange={(e) => onChange({ ...condition, value: e.target.value })}
                                    />
                                </FormField>

                                <FormField
                                    label={`Кратность, ${aggregateSuffix(condition.aggregate)}`}
                                    helpText="Награда «на каждые N» повторится за каждый такой шаг по этому условию. Пусто — условие в кратность не входит"
                                >
                                    <Input
                                        type="number"
                                        min={0}
                                        step={condition.aggregate === 'amount' ? '0.01' : '1'}
                                        placeholder="не задана"
                                        value={condition.per_value ?? ''}
                                        onChange={(e) => onChange({
                                            ...condition,
                                            per_value: e.target.value === '' ? null : e.target.value,
                                        })}
                                    />
                                </FormField>
                            </SimpleGrid>
                        </>
                    )}
                </VStack>
            </Card.Body>
        </Card.Root>
    );
}
