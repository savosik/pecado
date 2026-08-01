import { useCallback, useState } from 'react';
import { Box, Flex, HStack, Stack, Text, Image, Table, Badge } from '@chakra-ui/react';
import { usePage } from '@inertiajs/react';
import { LuGift, LuInfo, LuSprout } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { toaster } from '@/components/ui/toaster';

/**
 * Промо-позиции корзины.
 *
 * Строки виртуальные: их считает движок, в cart_items их нет, а id строковый
 * (`promo:{rule}:{reward}`). Поэтому здесь нет ни редактирования количества,
 * ни удаления — только то, что клиент действительно решает: выбрать товар
 * из вариантов и отказаться от платной позиции.
 */

const money = (value) => Number(value || 0).toLocaleString('ru-RU');

/** Цена 0 читается мгновенно как «Бесплатно», а «0 ₽» — как ошибка загрузки. */
const priceLabel = (value, symbol) => (Number(value || 0) <= 0 ? 'Бесплатно' : `${money(value)} ${symbol}`);

const kindBadge = (kind) =>
    kind === 'sample'
        ? { label: 'Рекламный образец', palette: 'gray', icon: LuSprout }
        : { label: 'Акция', palette: 'teal', icon: LuGift };

export default function CartPromoSection({ cartId, promoItems = [], onChanged }) {
    const [busyId, setBusyId] = useState(null);

    const post = useCallback(
        async (url, payload, errorTitle) => {
            setBusyId(payload.__id);
            try {
                const response = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    },
                    body: JSON.stringify({ ...payload, cart_id: cartId, __id: undefined }),
                });

                const data = await response.json().catch(() => ({}));

                if (!response.ok) {
                    toaster.create({ title: data.message || errorTitle, type: 'error' });

                    return;
                }

                onChanged?.(data);
            } catch {
                toaster.create({ title: errorTitle, type: 'error' });
            } finally {
                setBusyId(null);
            }
        },
        [cartId, onChanged],
    );

    const choose = useCallback(
        (item, productId) =>
            post(
                '/api/cart/promo/select',
                {
                    __id: item.id,
                    rule_id: item.promotion?.rule_id,
                    reward_index: item.promotion?.reward_index,
                    product_id: productId,
                },
                'Не удалось выбрать товар',
            ),
        [post],
    );

    const setDeclined = useCallback(
        (item, declined) =>
            post(
                '/api/cart/promo/decline',
                {
                    __id: item.id,
                    rule_id: item.promotion?.rule_id,
                    reward_index: item.promotion?.reward_index,
                    declined,
                },
                declined ? 'Не удалось отказаться' : 'Не удалось вернуть позицию',
            ),
        [post],
    );

    if (!promoItems.length) return null;

    return (
        <Box
            bg="bg"
            borderWidth={{ base: '0', md: '1px' }}
            borderColor="border.muted"
            rounded={{ base: 'none', md: 'lg' }}
            px={{ base: '3', md: '5' }}
            py={{ base: '3', md: '4' }}
            mt="4"
        >
            <Flex align="center" gap="2" mb="1">
                <LuGift size={18} />
                <Text fontWeight="600">Промо-позиции</Text>
                <Badge colorPalette="teal" variant="subtle">
                    {promoItems.reduce((sum, it) => sum + Number(it.quantity || 0), 0)} шт.
                </Badge>
            </Flex>

            <HStack gap="1.5" align="start" mb="3">
                <Box color="fg.muted" mt="0.5">
                    <LuInfo size={14} />
                </Box>
                <Text fontSize="xs" color="fg.muted">
                    Добавлены автоматически по акции. Они оформляются <b>отдельным заказом</b>.
                    {promoItems.some((item) => Number(item.price || 0) > 0) && (
                        <> Позиции с ценой войдут в этот заказ <b>к оплате</b>.</>
                    )}
                </Text>
            </HStack>

            {/* Десктоп */}
            <Box display={{ base: 'none', md: 'block' }} overflowX="auto">
                <Table.Root size="sm">
                    <Table.Header>
                        <Table.Row>
                            <Table.ColumnHeader>Товар</Table.ColumnHeader>
                            <Table.ColumnHeader w="120px" textAlign="center">Кол-во</Table.ColumnHeader>
                            <Table.ColumnHeader w="140px" textAlign="right">Цена</Table.ColumnHeader>
                            <Table.ColumnHeader w="140px" textAlign="right">Сумма</Table.ColumnHeader>
                            <Table.ColumnHeader w="140px" />
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {promoItems.map((item) => (
                            <PromoRow
                                key={item.id}
                                item={item}
                                busy={busyId === item.id}
                                onChoose={choose}
                                onDecline={setDeclined}
                            />
                        ))}
                    </Table.Body>
                </Table.Root>
            </Box>

            {/* Мобильные карточки */}
            <Stack gap="3" display={{ base: 'flex', md: 'none' }}>
                {promoItems.map((item) => (
                    <PromoCard
                        key={item.id}
                        item={item}
                        busy={busyId === item.id}
                        onChoose={choose}
                        onDecline={setDeclined}
                    />
                ))}
            </Stack>
        </Box>
    );
}

function ChoiceSelector({ item, busy, onChoose }) {
    if (!item.choices?.length) return null;

    return (
        <Flex gap="1.5" wrap="wrap" mt="1.5">
            {item.choices.map((choice) => (
                <Button
                    key={choice.product_id}
                    size="xs"
                    variant={choice.product_id === item.product?.id ? 'solid' : 'outline'}
                    colorPalette="teal"
                    disabled={busy}
                    onClick={() => onChoose(item, choice.product_id)}
                >
                    {choice.name}
                </Button>
            ))}
        </Flex>
    );
}

function DeclineButton({ item, busy, onDecline }) {
    // От бесплатного не отказываются — сервер такой запрос и не примет
    if (!item.is_optional) return null;

    return (
        <Button
            size="xs"
            variant="outline"
            colorPalette={item.is_declined ? 'teal' : 'gray'}
            disabled={busy}
            onClick={() => onDecline(item, !item.is_declined)}
        >
            {item.is_declined ? 'Вернуть' : 'Отказаться'}
        </Button>
    );
}

function ProductCell({ item }) {
    const badge = kindBadge(item.promo_kind);

    return (
        <Flex gap="2.5" align="start" minW="0">
            {item.product?.thumbnail_url && (
                <Image
                    src={item.product.thumbnail_url}
                    alt=""
                    boxSize="40px"
                    objectFit="contain"
                    rounded="md"
                    flexShrink="0"
                />
            )}
            <Box minW="0">
                <Text fontSize="sm" lineClamp="2" opacity={item.is_declined ? 0.5 : 1}>
                    {item.product?.name}
                </Text>
                <HStack gap="1.5" mt="1" wrap="wrap">
                    <Badge colorPalette={badge.palette} variant="subtle" fontSize="2xs">
                        {badge.label}
                    </Badge>
                    {/* Платную позицию клиент не заказывал — она должна быть
                        заметна, а не теряться среди подарков */}
                    {Number(item.price || 0) > 0 && (
                        <Badge colorPalette="orange" variant="subtle" fontSize="2xs">
                            Платная позиция
                        </Badge>
                    )}
                    {item.promotion?.name && (
                        <Text fontSize="2xs" color="fg.muted" lineClamp="1">
                            {item.promotion.name}
                        </Text>
                    )}
                </HStack>
            </Box>
        </Flex>
    );
}

function PromoRow({ item, busy, onChoose, onDecline }) {
    const currencySymbol = usePage().props.currency?.symbol ?? '₽';

    return (
        <Table.Row opacity={item.is_declined ? 0.6 : 1}>
            <Table.Cell>
                <ProductCell item={item} />
                <ChoiceSelector item={item} busy={busy} onChoose={onChoose} />
            </Table.Cell>
            <Table.Cell textAlign="center">
                {/* Количество задаёт правило — клиент его не меняет */}
                <Text fontSize="sm" fontWeight="600">{item.quantity}</Text>
            </Table.Cell>
            <Table.Cell textAlign="right">
                <Text fontSize="sm">{priceLabel(item.price, currencySymbol)}</Text>
            </Table.Cell>
            <Table.Cell textAlign="right">
                <Text fontSize="sm" fontWeight="600">
                    {priceLabel(item.total_amount, currencySymbol)}
                </Text>
            </Table.Cell>
            <Table.Cell textAlign="right">
                <DeclineButton item={item} busy={busy} onDecline={onDecline} />
            </Table.Cell>
        </Table.Row>
    );
}

function PromoCard({ item, busy, onChoose, onDecline }) {
    const currencySymbol = usePage().props.currency?.symbol ?? '₽';

    return (
        <Box
            borderWidth="1px"
            borderColor="border.muted"
            rounded="lg"
            p="3"
            opacity={item.is_declined ? 0.6 : 1}
        >
            <ProductCell item={item} />
            <ChoiceSelector item={item} busy={busy} onChoose={onChoose} />

            <Flex justify="space-between" align="center" mt="2.5">
                <Text fontSize="xs" color="fg.muted">
                    {item.quantity} шт. · {priceLabel(item.price, currencySymbol)}
                </Text>
                <Text fontSize="sm" fontWeight="600">
                    {priceLabel(item.total_amount, currencySymbol)}
                </Text>
            </Flex>

            <Box mt="2">
                <DeclineButton item={item} busy={busy} onDecline={onDecline} />
            </Box>
        </Box>
    );
}
