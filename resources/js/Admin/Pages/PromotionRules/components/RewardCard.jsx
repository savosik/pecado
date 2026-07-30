import { useState } from 'react';
import { Card, HStack, VStack, Text, Input, IconButton, SimpleGrid, Box, Button } from '@chakra-ui/react';
import { LuTrash2, LuChevronDown, LuChevronRight } from 'react-icons/lu';
import { FormField, ProductSelector } from '@/Admin/Components';
import { Switch } from '@/components/ui/switch';
import { Radio, RadioGroup } from '@/components/ui/radio';
import { NativeSelectRoot, NativeSelectField } from '@/components/ui/native-select';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { rewardSummary } from './ruleState';

/**
 * Одна награда правила — промо-позиция с произвольной ценой.
 *
 * В типовой акции это подарок за 0 ₽ с обычного склада один раз, поэтому вид
 * промо-позиции, склад и кратность живут под «Дополнительно»: наверху остаётся
 * только «что выдаём, сколько и почём».
 */
export default function RewardCard({
    index,
    reward,
    onChange,
    onRemove,
    warehouses = [],
    defaultOpen = false,
    stepFromConditions = false,
}) {
    const [open, setOpen] = useState(defaultOpen);
    const patch = (values) => onChange({ ...reward, ...values });

    const price = Number(reward.price) || 0;
    const isSample = reward.promo_kind === 'sample';
    const perThreshold = reward.multiply === 'per_threshold';

    // Дополнительное раскрыто сразу, если там что-то настроено — иначе его не найдут
    const [advanced, setAdvanced] = useState(
        isSample || perThreshold || Boolean(reward.warehouse_id),
    );

    // Пробники выдаются со склада «Москва реклама» — он появится в волне 3
    const availableWarehouses = isSample
        ? warehouses.filter((warehouse) => warehouse.is_promo_sample)
        : warehouses.filter((warehouse) => !warehouse.is_defect);

    return (
        <Card.Root borderWidth="1px">
            <Card.Body py={3}>
                <VStack align="stretch" gap={open ? 4 : 0}>
                    <HStack justify="space-between" gap={3}>
                        <HStack gap={2} flex="1" minW={0} align="start">
                            <IconButton
                                aria-label={open ? 'Свернуть награду' : 'Развернуть награду'}
                                type="button"
                                size="xs"
                                variant="ghost"
                                onClick={() => setOpen((value) => !value)}
                            >
                                {open ? <LuChevronDown /> : <LuChevronRight />}
                            </IconButton>

                            <Box flex="1" minW={0} cursor="pointer" onClick={() => setOpen((value) => !value)}>
                                <Text fontSize="xs" color="fg.muted">Награда {index + 1}</Text>
                                <Text fontSize="sm" fontWeight="medium">{rewardSummary(reward)}</Text>
                            </Box>
                        </HStack>

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

                    {open && (
                        <>
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

                            <Box>
                                <Button
                                    size="xs"
                                    variant="ghost"
                                    type="button"
                                    onClick={() => setAdvanced((value) => !value)}
                                >
                                    {advanced ? <LuChevronDown /> : <LuChevronRight />}
                                    Дополнительно: вид промо-позиции, склад, кратность
                                </Button>
                            </Box>

                            {advanced && (
                                <VStack align="stretch" gap={4} pl={2} borderLeftWidth="2px" borderColor="border">
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
                                            {/* Шаг задан в позициях условия — здесь спрашивать его нечего:
                                                два места для одного числа только путают */}
                                            {stepFromConditions ? (
                                                <FormField label="На каждые">
                                                    <Text fontSize="sm" color="fg.muted" pt={2}>
                                                        Шаг берётся из условий — у каждой позиции свой.
                                                    </Text>
                                                </FormField>
                                            ) : (
                                                <FormField
                                                    label="На каждые"
                                                    helpText="Шаг в единицах первого сработавшего условия. Если у артикулов кратность разная, задайте её в самих условиях"
                                                >
                                                    <Input
                                                        type="number"
                                                        min={0}
                                                        step="0.01"
                                                        value={reward.per_value ?? ''}
                                                        onChange={(e) => patch({ per_value: e.target.value === '' ? null : e.target.value })}
                                                    />
                                                </FormField>
                                            )}

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
                                </VStack>
                            )}
                        </>
                    )}
                </VStack>
            </Card.Body>
        </Card.Root>
    );
}
