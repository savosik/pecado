import { memo, useState } from 'react';
import { Box, Flex, Text, Image, Badge, IconButton } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import { LuTrash2, LuImageOff } from 'react-icons/lu';
import QuantityControl from '@/components/common/QuantityControl';

/**
 * Mobile-карточка уценки в общей ленте корзины. Визуально совпадает с
 * CartMobileCard (картинка 2:3, sku/бренд, название, нижняя полоска с итогом),
 * отличие — лиловый лейбл «Уценка» и отсутствие split наличие/предзаказ.
 */
function CartDefectMobileCard({ item, qty, currencySymbol, onSetQty }) {
    const [imageError, setImageError] = useState(false);

    const product = item.product;
    const price = Number(item.price ?? 0);
    const max = Number(item.max_total ?? item.available_quantity ?? qty);
    const sum = price * qty;
    const isUnavailable = !!item.is_unavailable;
    const imageSrc = item.defect?.photo_url || product?.thumbnail_url || product?.main_image_url;
    const showImage = imageSrc && !imageError;
    const brandName = product?.brand?.name;

    if (qty <= 0) return null;

    return (
        <Box
            position="relative"
            borderTopWidth="1px"
            borderBottomWidth="1px"
            borderLeftWidth={{ base: '0', md: '1px' }}
            borderRightWidth={{ base: '0', md: '1px' }}
            borderStyle="solid"
            borderColor="border"
            rounded={{ base: 'none', md: 'lg' }}
            mt={{ base: '-1px', md: '0' }}
            bg="bg"
            opacity={isUnavailable ? 0.5 : 1}
            overflow="hidden"
        >
            <Flex direction="row" align="flex-start" p="2" gap="3">
                <Box
                    flexShrink="0"
                    w={{ base: '90px', md: '120px' }}
                    h={{ base: '135px', md: '180px' }}
                    overflow="hidden"
                    borderRadius="md"
                    bg="gray.100"
                    _dark={{ bg: 'gray.700' }}
                    display="flex"
                    alignItems="center"
                    justifyContent="center"
                >
                    {showImage ? (
                        <Image src={imageSrc} alt="" w="100%" h="100%" objectFit="cover" onError={() => setImageError(true)} />
                    ) : (
                        <Flex direction="column" align="center" gap="1" color="gray.400" _dark={{ color: 'gray.500' }}>
                            <LuImageOff size={20} />
                            <Text fontSize="2xs">Нет фото</Text>
                        </Flex>
                    )}
                </Box>

                <Flex flex="1" direction="column" gap="1" minW="0">
                    <Flex align="center" gap="1.5" flexWrap="wrap" rowGap="0.5">
                        {product?.sku && (
                            <Text fontSize="xs" fontWeight="700" color="gray.500" lineHeight="1.3">{product.sku}</Text>
                        )}
                        <Badge colorPalette="purple" variant="subtle" fontSize="2xs">Уценка</Badge>
                        {item.defect?.description && (
                            <Text fontSize="2xs" color="purple.500" lineHeight="1.3" lineClamp={1}>{item.defect.description}</Text>
                        )}
                    </Flex>
                    {brandName && (
                        <Text fontSize="2xs" textTransform="capitalize" letterSpacing="wide" color="gray.400" lineHeight="1.3">
                            {brandName}
                        </Text>
                    )}
                    <Text
                        as={product?.slug ? Link : 'span'}
                        href={product?.slug ? `/products/${product.slug}` : undefined}
                        fontSize="13px"
                        fontWeight="400"
                        lineHeight="1.3"
                        _hover={product?.slug ? { textDecoration: 'underline' } : undefined}
                        display="-webkit-box"
                        css={{
                            WebkitLineClamp: 3,
                            WebkitBoxOrient: 'vertical',
                            overflow: 'hidden',
                            textOverflow: 'ellipsis',
                        }}
                    >
                        {product?.name || 'Товар'}
                    </Text>
                    {isUnavailable && (
                        <Badge colorPalette="red" variant="subtle" fontSize="2xs" mt="1" alignSelf="flex-start">
                            Недоступен
                        </Badge>
                    )}

                    <Flex mt="2" align="center" gap="2" flexWrap="wrap" rowGap="2">
                        <Box>
                            <Text fontSize="2xs" color="fg.muted" lineHeight="1">Цена</Text>
                            <Text fontWeight="600" fontSize="md" lineHeight="1.2">
                                {price.toLocaleString('ru-RU')}
                            </Text>
                        </Box>
                        <Box ml="auto" flexShrink="0">
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
                        </Box>
                    </Flex>
                </Flex>
            </Flex>

            <Flex align="center" gap="2" px="2" py="1.5" borderTopWidth="1px" borderColor="border.muted" bg="bg.subtle" _dark={{ bg: 'gray.900/40' }}>
                <Box ml="auto" fontSize="sm" textAlign="right">
                    <Text as="span" fontSize="2xs" color="fg.muted" mr="1">Итого:</Text>
                    <Text as="span" fontWeight="700">{sum.toLocaleString('ru-RU')} {currencySymbol}</Text>
                </Box>
                <IconButton
                    aria-label="Удалить"
                    variant="ghost"
                    size="xs"
                    colorPalette="red"
                    onClick={() => onSetQty(item, 0)}
                >
                    <LuTrash2 size={14} />
                </IconButton>
            </Flex>
        </Box>
    );
}

export default memo(CartDefectMobileCard);
