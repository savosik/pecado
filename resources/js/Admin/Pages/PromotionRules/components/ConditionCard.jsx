import { useEffect, useState } from 'react';
import axios from 'axios';
import { Box, Card, HStack, VStack, Text, Input, IconButton, Badge, SimpleGrid } from '@chakra-ui/react';
import { LuTrash2 } from 'react-icons/lu';
import { FormField, ProductSelector, MultiEntitySelector, TagSelector } from '@/Admin/Components';
import { Checkbox } from '@/components/ui/checkbox';
import { Switch } from '@/components/ui/switch';
import { NativeSelectRoot, NativeSelectField } from '@/components/ui/native-select';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { aggregateSuffix } from './ruleState';

const OPERATORS = [
    { value: '>=', label: 'не меньше (≥)' },
    { value: '>', label: 'больше (>)' },
    { value: '=', label: 'ровно (=)' },
    { value: '<=', label: 'не больше (≤)' },
    { value: '<', label: 'меньше (<)' },
];

/**
 * Одно условие срабатывания: что считаем, по каким товарам и с каким порогом.
 */
export default function ConditionCard({ index, condition, onChange, onRemove, erpPromotionTypes = [] }) {
    const [matchCount, setMatchCount] = useState(null);
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

    const toggleErpPromotion = (type) => {
        const current = selector.erp_promotions || [];
        patchSelector({
            erp_promotions: current.includes(type)
                ? current.filter((t) => t !== type)
                : [...current, type],
        });
    };

    return (
        <Card.Root borderWidth="1px">
            <Card.Body>
                <VStack align="stretch" gap={4}>
                    <HStack justify="space-between">
                        <Text fontWeight="semibold">Условие {index + 1}</Text>
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

                    <FormField
                        label="Вся корзина"
                        helpText="Условие считается по всем позициям корзины, а не по выбранным товарам"
                    >
                        <Switch
                            checked={selector.whole_cart}
                            onCheckedChange={(e) => patchSelector({ whole_cart: e.checked })}
                        />
                    </FormField>

                    {!selector.whole_cart && (
                        <>
                            <FormField label="Товары">
                                <ProductSelector
                                    value={selector.products}
                                    onChange={(products) => patchSelector({ products })}
                                    compactSelected
                                />
                            </FormField>

                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Категории">
                                    <MultiEntitySelector
                                        value={selector.categories}
                                        onChange={(categories) => patchSelector({ categories })}
                                        searchUrl="admin.categories.search"
                                        placeholder="Начните вводить категорию..."
                                    />
                                </FormField>

                                <FormField label="Бренды">
                                    <MultiEntitySelector
                                        value={selector.brands}
                                        onChange={(brands) => patchSelector({ brands })}
                                        searchUrl="admin.brands.search"
                                        placeholder="Начните вводить бренд..."
                                    />
                                </FormField>
                            </SimpleGrid>

                            <Checkbox
                                checked={selector.with_descendants}
                                onCheckedChange={(e) => patchSelector({ with_descendants: e.checked })}
                            >
                                Включая подкатегории
                            </Checkbox>

                            <FormField label="Теги">
                                <TagSelector
                                    value={selector.tags}
                                    onChange={(tags) => patchSelector({ tags })}
                                />
                            </FormField>

                            <FormField label="Группы товаров из 1С">
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
                            </FormField>
                        </>
                    )}

                    <Box>
                        {matchCount && (
                            <Badge colorPalette={matchCount.count > 0 ? 'blue' : 'orange'} variant="subtle">
                                {matchCount.whole_cart
                                    ? 'Условие считается по всей корзине'
                                    : `Сейчас под условие подходит товаров: ${matchCount.count}`}
                            </Badge>
                        )}
                    </Box>

                    <SimpleGrid columns={{ base: 1, md: 3 }} gap={4}>
                        <FormField label="Считать">
                            <SegmentedControl
                                value={condition.aggregate}
                                onValueChange={(e) => onChange({ ...condition, aggregate: e.value })}
                                items={[
                                    { value: 'quantity', label: 'Количество штук' },
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
                                ? 'Сумма в рублях по индивидуальным ценам клиента со скидкой'
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
                    </SimpleGrid>
                </VStack>
            </Card.Body>
        </Card.Root>
    );
}
