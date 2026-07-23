import { memo } from 'react';
import { Box, Flex, Text, Table, Image, Badge, IconButton } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import { LuTrash2, LuImageOff } from 'react-icons/lu';
import QuantityControl from '@/components/common/QuantityControl';

/**
 * Desktop-строка уценки в общей таблице корзины.
 *
 * Уценка привязана к партии (cart_item.id), а не к product_id, поэтому живёт
 * вне store-агрегации: qty приходит controlled-ом из Index, изменения летят
 * через onSetQty(item, next) → PATCH/DELETE /api/cart/items/{id}. Визуально
 * строка идентична товарной, отличие — лиловый лейбл «Уценка».
 */
function CartDefectTableRow({ item, qty, hasPreorderItems, onSetQty }) {
    const product = item.product;
    const price = Number(item.price ?? 0);
    const max = Number(item.max_total ?? item.available_quantity ?? qty);
    const sum = price * qty;
    const isUnavailable = !!item.is_unavailable;
    const image = item.defect?.photo_url || product?.thumbnail_url || product?.main_image_url;

    if (qty <= 0) return null;

    return (
        <Table.Row opacity={isUnavailable ? 0.5 : 1}>
            <Table.Cell textAlign="center" verticalAlign="middle" />
            <Table.Cell verticalAlign="middle">
                <Flex gap="2" align="center" minW="0">
                    {image ? (
                        <Image
                            src={image}
                            alt={product?.name || ''}
                            h="28px"
                            w="20px"
                            objectFit="cover"
                            borderWidth="1px"
                            borderColor="border.muted"
                            flexShrink={0}
                        />
                    ) : (
                        <Flex h="28px" w="20px" align="center" justify="center" color="fg.muted" borderWidth="1px" borderColor="border.muted" flexShrink={0}>
                            <LuImageOff size={12} />
                        </Flex>
                    )}
                    <Box minW="0" flex="1">
                        <Box fontWeight="medium" lineHeight="1.25" overflow="hidden">
                            {product?.slug ? (
                                <Link href={`/products/${product.slug}`} style={{ display: 'block' }}>
                                    <Text fontSize="xs" _hover={{ textDecoration: 'underline' }} lineClamp={2}>
                                        {product?.name || 'Товар'}
                                    </Text>
                                </Link>
                            ) : (
                                <Text fontSize="xs" lineClamp={2}>{product?.name || 'Товар'}</Text>
                            )}
                        </Box>
                        <Flex align="center" gap="1.5" mt="0.5" flexWrap="wrap" rowGap="0.5">
                            <Text fontSize="2xs" color="fg.muted" lineClamp={1}>{product?.sku || '—'}</Text>
                            <Badge colorPalette="purple" variant="subtle" fontSize="2xs">Уценка</Badge>
                            {item.defect?.description && (
                                <Text fontSize="2xs" color="purple.500" lineClamp={1}>{item.defect.description}</Text>
                            )}
                        </Flex>
                        {isUnavailable && (
                            <Badge colorPalette="red" variant="subtle" fontSize="2xs" mt="0.5">Недоступен</Badge>
                        )}
                    </Box>
                </Flex>
            </Table.Cell>
            <Table.Cell textAlign="center" verticalAlign="middle">
                <Box
                    display="inline-block"
                    borderWidth="2px"
                    borderColor="purple.200"
                    _dark={{ borderColor: 'purple.700' }}
                    rounded="md"
                    overflow="hidden"
                >
                    <QuantityControl
                        value={qty}
                        onChange={(v) => onSetQty(item, v)}
                        min={1}
                        max={max > 0 ? max : undefined}
                        size="sm"
                        outerBorder={false}
                    />
                </Box>
            </Table.Cell>
            <Table.Cell textAlign="center" verticalAlign="middle" fontSize="xs">{qty}</Table.Cell>
            {hasPreorderItems && (
                <Table.Cell textAlign="center" verticalAlign="middle" fontSize="xs">0</Table.Cell>
            )}
            <Table.Cell textAlign="right" verticalAlign="middle" fontSize="xs">
                {price.toLocaleString('ru-RU')}
            </Table.Cell>
            <Table.Cell textAlign="right" verticalAlign="middle" fontSize="xs">—</Table.Cell>
            <Table.Cell textAlign="right" verticalAlign="middle" fontSize="xs">
                {price.toLocaleString('ru-RU')}
            </Table.Cell>
            <Table.Cell textAlign="right" verticalAlign="middle" fontWeight="medium" fontSize="xs">
                {sum.toLocaleString('ru-RU')}
            </Table.Cell>
            <Table.Cell verticalAlign="middle">
                <IconButton
                    aria-label="Удалить"
                    variant="ghost"
                    size="xs"
                    colorPalette="red"
                    onClick={() => onSetQty(item, 0)}
                >
                    <LuTrash2 size={14} />
                </IconButton>
            </Table.Cell>
        </Table.Row>
    );
}

export default memo(CartDefectTableRow);
