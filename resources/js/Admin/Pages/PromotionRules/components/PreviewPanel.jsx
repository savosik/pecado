import { useState } from 'react';
import axios from 'axios';
import { Box, Card, HStack, VStack, Text, Input, Button, Badge, SimpleGrid, Heading } from '@chakra-ui/react';
import { LuPlay } from 'react-icons/lu';
import { Alert } from '@/components/ui/alert';
import { NativeSelectRoot, NativeSelectField } from '@/components/ui/native-select';
import { FormField } from '@/Admin/Components';
import { formatAggregate } from './ruleState';

const money = (value) => new Intl.NumberFormat('ru-RU', {
    style: 'currency',
    currency: 'RUB',
    maximumFractionDigits: 2,
}).format(value || 0);

/**
 * Прогон правила по реальной корзине или заказу.
 *
 * Единственное место, где видно, почему правило не сработало: клиенту причины
 * не показываются никогда, поэтому без этого экрана «настроено неверно» и
 * «нет товара на складе» неразличимы.
 */
export default function PreviewPanel({ ruleId }) {
    const [source, setSource] = useState('cart');
    const [id, setId] = useState('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [result, setResult] = useState(null);

    if (!ruleId) {
        return (
            <Alert status="info" title="Предпросмотр доступен после сохранения">
                Правило нужно сначала сохранить — прогон выполняется по сохранённой конфигурации.
            </Alert>
        );
    }

    const run = async () => {
        setLoading(true);
        setError(null);
        setResult(null);

        try {
            const { data } = await axios.post(route('admin.promotion-rules.preview', ruleId), {
                source,
                id: Number(id),
            });
            setResult(data);
        } catch (e) {
            setError(e.response?.data?.message || 'Не удалось выполнить прогон');
        } finally {
            setLoading(false);
        }
    };

    const preview = result?.preview;
    const productNames = result?.product_names || {};
    const warehouseNames = result?.warehouse_names || {};

    return (
        <VStack align="stretch" gap={5}>
            <Card.Root borderWidth="1px">
                <Card.Body>
                    <SimpleGrid columns={{ base: 1, md: 3 }} gap={4} alignItems="end">
                        <FormField label="Что проверяем">
                            <NativeSelectRoot>
                                <NativeSelectField value={source} onChange={(e) => setSource(e.target.value)}>
                                    <option value="cart">Корзина</option>
                                    <option value="order">Заказ</option>
                                </NativeSelectField>
                            </NativeSelectRoot>
                        </FormField>

                        <FormField label={source === 'cart' ? 'ID корзины' : 'ID заказа'}>
                            <Input
                                type="number"
                                min={1}
                                value={id}
                                onChange={(e) => setId(e.target.value)}
                                placeholder="Например: 1024"
                            />
                        </FormField>

                        <Box>
                            <Button type="button" colorPalette="blue" onClick={run} loading={loading} disabled={!id}>
                                <LuPlay /> Проверить
                            </Button>
                        </Box>
                    </SimpleGrid>
                </Card.Body>
            </Card.Root>

            {error && <Alert status="error" title={error} />}

            {preview && (
                <VStack align="stretch" gap={5}>
                    <Card.Root
                        borderWidth="2px"
                        borderColor={preview.fired ? 'green.400' : 'orange.300'}
                    >
                        <Card.Body>
                            <HStack justify="space-between" wrap="wrap" gap={3}>
                                <Heading size="lg" color={preview.fired ? 'green.600' : 'orange.600'}>
                                    {preview.fired ? 'Правило сработало' : 'Правило не сработало'}
                                </Heading>
                                <HStack gap={2} wrap="wrap">
                                    <Badge colorPalette="gray" variant="subtle">
                                        {result.subject.type === 'cart' ? 'Корзина' : 'Заказ'} №{result.subject.id}
                                    </Badge>
                                    {result.subject.client && (
                                        <Badge colorPalette="gray" variant="subtle">
                                            {result.subject.client}
                                        </Badge>
                                    )}
                                    <Badge colorPalette="gray" variant="subtle">
                                        Позиций: {preview.line_count}
                                    </Badge>
                                </HStack>
                            </HStack>
                        </Card.Body>
                    </Card.Root>

                    <Card.Root borderWidth="1px">
                        <Card.Header>
                            <Heading size="sm">
                                Условия ({preview.conditions_mode === 'any' ? 'достаточно любого' : 'нужны все'})
                            </Heading>
                        </Card.Header>
                        <Card.Body>
                            <VStack align="stretch" gap={3}>
                                {preview.conditions.length === 0 && (
                                    <Text color="fg.muted">Условия не заданы</Text>
                                )}
                                {preview.conditions.map((condition) => (
                                    <Box
                                        key={condition.index}
                                        p={3}
                                        borderWidth="1px"
                                        borderRadius="md"
                                        borderColor={condition.satisfied ? 'green.200' : 'border'}
                                        bg={condition.satisfied ? 'green.subtle' : undefined}
                                    >
                                        <Text fontWeight="medium">
                                            {result.condition_lines[condition.index] || `Условие ${condition.index + 1}`}
                                        </Text>
                                        <Text fontSize="sm" color="fg.muted" mt={1}>
                                            Набрано: {formatAggregate(condition.value, condition.aggregate)} из{' '}
                                            {formatAggregate(condition.target, condition.aggregate)}
                                            {!condition.satisfied && condition.remaining > 0 && (
                                                <>, не хватает {formatAggregate(condition.remaining, condition.aggregate)}</>
                                            )}
                                            {condition.per_value > 0 && (
                                                <>
                                                    {'. Кратность: каждые '}
                                                    {formatAggregate(condition.per_value, condition.aggregate)}
                                                    {' → вклад в награду '}
                                                    {condition.multiplier ?? 0}
                                                </>
                                            )}
                                        </Text>
                                    </Box>
                                ))}
                            </VStack>
                        </Card.Body>
                    </Card.Root>

                    <Card.Root borderWidth="1px">
                        <Card.Header>
                            <Heading size="sm">Что было бы выдано</Heading>
                        </Card.Header>
                        <Card.Body>
                            <VStack align="stretch" gap={3}>
                                {preview.applied.length === 0 && (
                                    <Text color="fg.muted">Промо-позиции не выдаются</Text>
                                )}
                                {preview.applied.map((reward) => (
                                    <Box key={`${reward.rule_id}-${reward.reward_index}`} p={3} borderWidth="1px" borderRadius="md">
                                        <Text fontWeight="medium">
                                            {productNames[reward.product_id] || `Товар #${reward.product_id}`} × {reward.quantity}
                                        </Text>
                                        <HStack gap={3} fontSize="sm" color="fg.muted" wrap="wrap" mt={1}>
                                            <Text>Цена: {money(reward.price)}</Text>
                                            <Text>Сумма: {money(reward.total)}</Text>
                                            <Text>
                                                Склад: {reward.warehouse_id ? (warehouseNames[reward.warehouse_id] || `#${reward.warehouse_id}`) : 'не выбран'}
                                            </Text>
                                            <Text>
                                                {reward.promo_kind === 'sample' ? 'Пробник' : 'Подотчётная'}
                                            </Text>
                                            {reward.declined && <Badge colorPalette="orange">Клиент отказался</Badge>}
                                        </HStack>
                                        {reward.rule_mode === 'info' && (
                                            <Text fontSize="xs" color="fg.muted" mt={2}>
                                                Правило в режиме показа: позиция в корзину не попадёт.
                                            </Text>
                                        )}
                                    </Box>
                                ))}
                            </VStack>
                        </Card.Body>
                    </Card.Root>

                    {(preview.blocked.length > 0 || preview.rule_block || !preview.audience_matches
                        || !preview.is_active || !preview.in_period || !preview.applies_to_channel) && (
                        <Card.Root borderWidth="1px" borderColor="orange.300">
                            <Card.Header>
                                <Heading size="sm">Почему промо-позиция не выдаётся</Heading>
                            </Card.Header>
                            <Card.Body>
                                <VStack align="stretch" gap={2}>
                                    {!preview.is_active && (
                                        <Text>Правило выключено — включите его на вкладке «Основное».</Text>
                                    )}
                                    {!preview.in_period && (
                                        <Text>Текущая дата вне периода действия правила.</Text>
                                    )}
                                    {!preview.applies_to_channel && (
                                        <Text>Канал «Сайт» не разрешён в настройках аудитории.</Text>
                                    )}
                                    {!preview.audience_matches && (
                                        <Text>Клиент не попадает в аудиторию правила (регион, список клиентов или менеджеров).</Text>
                                    )}
                                    {preview.rule_block_label && (
                                        <Text>{preview.rule_block_label}</Text>
                                    )}
                                    {preview.blocked.map((blocked, blockedIndex) => (
                                        <Text key={blockedIndex}>
                                            {productNames[blocked.product_id] || `Товар #${blocked.product_id}`}: {blocked.reason_label}
                                        </Text>
                                    ))}
                                </VStack>
                            </Card.Body>
                        </Card.Root>
                    )}
                </VStack>
            )}
        </VStack>
    );
}
