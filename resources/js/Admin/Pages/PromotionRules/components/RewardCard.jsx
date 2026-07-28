import { Card, HStack, VStack, Text, Input, IconButton, SimpleGrid, Box } from '@chakra-ui/react';
import { LuTrash2 } from 'react-icons/lu';
import { FormField, ProductSelector } from '@/Admin/Components';
import { Switch } from '@/components/ui/switch';
import { Radio, RadioGroup } from '@/components/ui/radio';
import { NativeSelectRoot, NativeSelectField } from '@/components/ui/native-select';
import { SegmentedControl } from '@/components/ui/segmented-control';

/**
 * Одна награда правила — промо-позиция с произвольной ценой.
 */
export default function RewardCard({ index, reward, onChange, onRemove, warehouses = [] }) {
    const patch = (values) => onChange({ ...reward, ...values });

    const price = Number(reward.price) || 0;
    const isSample = reward.promo_kind === 'sample';
    const perThreshold = reward.multiply === 'per_threshold';

    // Пробники выдаются со склада «Москва реклама» — он появится в волне 3
    const availableWarehouses = isSample
        ? warehouses.filter((warehouse) => warehouse.is_promo_sample)
        : warehouses.filter((warehouse) => !warehouse.is_defect);

    return (
        <Card.Root borderWidth="1px">
            <Card.Body>
                <VStack align="stretch" gap={4}>
                    <HStack justify="space-between">
                        <Text fontWeight="semibold">Награда {index + 1}</Text>
                        <IconButton
                            aria-label="Удалить награду"
                            type="button"
                            size="sm"
                            variant="ghost"
                            colorPalette="red"
                            onClick={onRemove}
                        >
                            <LuTrash2 />
                        </IconButton>
                    </HStack>

                    <FormField label="Что выдаём">
                        <SegmentedControl
                            value={reward.type}
                            onValueChange={(e) => patch({ type: e.value })}
                            items={[
                                { value: 'fixed', label: 'Конкретный товар' },
                                { value: 'choice', label: 'Клиент выбирает из списка' },
                            ]}
                        />
                    </FormField>

                    {reward.type === 'fixed' ? (
                        <FormField
                            label="Товар награды"
                            helpText="Можно указать тот же товар, что и в условии — так настраивается «каждый шестой в подарок»: условие «от 5 шт.» плюс кратность «на каждые 5»"
                        >
                            <ProductSelector
                                mode="single"
                                value={reward.product}
                                onChange={(product) => patch({ product })}
                                compactSelected
                            />
                        </FormField>
                    ) : (
                        <FormField label="Варианты на выбор" helpText="Минимум два товара">
                            <ProductSelector
                                value={reward.choices}
                                onChange={(choices) => patch({ choices })}
                                compactSelected
                            />
                        </FormField>
                    )}

                    <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                        <FormField label="Количество">
                            <Input
                                type="number"
                                min={1}
                                step="1"
                                value={reward.quantity}
                                onChange={(e) => patch({ quantity: e.target.value })}
                            />
                        </FormField>

                        <FormField
                            label="Цена промо-позиции, ₽"
                            helpText="0 — подарок. Можно указать любую цену: 0,01 ₽, 10 ₽, 40 ₽ — клиент оплатит её в отдельном заказе"
                        >
                            <Input
                                type="number"
                                min={0}
                                step="0.01"
                                value={reward.price}
                                onChange={(e) => patch({ price: e.target.value })}
                            />
                        </FormField>
                    </SimpleGrid>

                    <FormField label="Вид промо-позиции">
                        <RadioGroup
                            value={reward.promo_kind}
                            onValueChange={(e) => patch({ promo_kind: e.value, warehouse_id: null })}
                        >
                            <VStack align="start" gap={2}>
                                <Radio value="accountable">
                                    Подотчётная — выписывается в накладной клиенту
                                </Radio>
                                <Radio value="sample">
                                    Пробник — не выписывается, уходит со склада «Москва реклама»
                                </Radio>
                            </VStack>
                        </RadioGroup>
                    </FormField>

                    <FormField
                        label="Склад-источник"
                        helpText={isSample && availableWarehouses.length === 0
                            ? 'Складов пробников пока нет: склад «Москва реклама» заводится в 1С и приедет по шине вместе с волной 3. До этого правило с наградой-пробником сохраняется, но не включается.'
                            : 'Откуда списывается промо-позиция'}
                    >
                        <NativeSelectRoot disabled={availableWarehouses.length === 0}>
                            <NativeSelectField
                                value={reward.warehouse_id || ''}
                                onChange={(e) => patch({ warehouse_id: e.target.value ? Number(e.target.value) : null })}
                            >
                                <option value="">Не выбран</option>
                                {availableWarehouses.map((warehouse) => (
                                    <option key={warehouse.id} value={warehouse.id}>
                                        {warehouse.name}
                                    </option>
                                ))}
                            </NativeSelectField>
                        </NativeSelectRoot>
                    </FormField>

                    <FormField label="Кратность">
                        <RadioGroup
                            value={reward.multiply}
                            onValueChange={(e) => patch({ multiply: e.value })}
                        >
                            <VStack align="start" gap={2}>
                                <Radio value="once">Один раз при достижении порога</Radio>
                                <Radio value="per_threshold">На каждые …</Radio>
                            </VStack>
                        </RadioGroup>
                    </FormField>

                    {perThreshold && (
                        <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                            <FormField
                                label="На каждые"
                                helpText="Шаг в тех же единицах, что и первое условие правила"
                            >
                                <Input
                                    type="number"
                                    min={0}
                                    step="0.01"
                                    value={reward.per_value ?? ''}
                                    onChange={(e) => patch({ per_value: e.target.value })}
                                />
                            </FormField>

                            <FormField
                                label="Но не более … раз"
                                helpText="Потолок обязателен: без него одна крупная закупка выметет весь остаток склада"
                            >
                                <Input
                                    type="number"
                                    min={1}
                                    step="1"
                                    value={reward.max_multiplier ?? ''}
                                    onChange={(e) => patch({ max_multiplier: e.target.value })}
                                />
                            </FormField>
                        </SimpleGrid>
                    )}

                    {price > 0 && (
                        <FormField
                            label="Клиент может отказаться"
                            helpText="Платную промо-позицию можно убрать из корзины"
                        >
                            <Switch
                                checked={reward.optional}
                                onCheckedChange={(e) => patch({ optional: e.checked })}
                            />
                        </FormField>
                    )}

                    {price === 0 && (
                        <Box>
                            <Text fontSize="sm" color="fg.muted">
                                Бесплатную промо-позицию отклонить нельзя — переключатель отказа скрыт.
                            </Text>
                        </Box>
                    )}
                </VStack>
            </Card.Body>
        </Card.Root>
    );
}
