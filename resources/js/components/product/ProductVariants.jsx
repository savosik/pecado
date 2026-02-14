import { Box, Flex, Text } from '@chakra-ui/react';
import { Link, usePage } from '@inertiajs/react';
import { LuCheck, LuClock3, LuCircleX } from 'react-icons/lu';

/**
 * ProductVariants — варианты товара из той же ProductModel.
 * Показывает только отличительные характеристики вместо полного названия.
 */
export default function ProductVariants({ variants = [], currentProductId, modelName = '' }) {
    const { currency } = usePage().props;
    const currencySymbol = currency?.symbol || '₽';

    if (!variants || variants.length === 0) return null;

    const formatPrice = (value) => {
        if (value === null || value === undefined) return '';
        return Number(value).toLocaleString('ru-RU') + '\u00A0' + currencySymbol;
    };

    const getStatus = (variant) => {
        const stockQty = variant.stock_quantity || 0;
        const preorderQty = variant.preorder_quantity || 0;
        if (stockQty > 0) return { text: 'В наличии', icon: LuCheck, color: 'green.600', iconColor: '#16a34a' };
        if (preorderQty > 0) return { text: 'Предзаказ', icon: LuClock3, color: 'orange.500', iconColor: '#f97316' };
        return { text: 'Нет в наличии', icon: LuCircleX, color: 'red.600', iconColor: '#dc2626' };
    };

    /** Компактная подпись варианта: только отличительные атрибуты */
    const getVariantLabel = (variant) => {
        if (variant.diff_attrs && variant.diff_attrs.length > 0) {
            return variant.diff_attrs.map(a => a.value).join(', ');
        }
        // Fallback — артикул
        return variant.sku || `#${variant.id}`;
    };

    return (
        <Box mb="4">
            <Text fontSize="sm" fontWeight="500" mb="2" color="gray.600" _dark={{ color: 'gray.400' }}>
                {modelName
                    ? `Другие варианты «${modelName}»`
                    : 'Другие варианты товара'
                }
            </Text>

            <Box
                display="grid"
                gridTemplateColumns={{ base: '1fr 1fr', md: 'repeat(auto-fill, minmax(180px, 1fr))' }}
                gap="2"
            >
                {variants.map((variant) => {
                    const status = getStatus(variant);
                    const StatusIcon = status.icon;
                    const hasSale = variant.sale_price != null && variant.sale_price < variant.base_price;
                    const label = getVariantLabel(variant);

                    return (
                        <Box
                            as={Link}
                            key={variant.id}
                            href={`/products/${variant.slug}`}
                            p="2"
                            rounded="md"
                            borderWidth="1px"
                            borderColor="gray.200"
                            _dark={{ borderColor: 'gray.700' }}
                            _hover={{ shadow: 'sm', borderColor: 'gray.400' }}
                            transition="all 0.15s"
                            display="block"
                        >
                            <Flex align="center" gap="2">
                                {/* Миниатюра */}
                                <Box flexShrink={0}>
                                    {variant.thumbnail || variant.main_image ? (
                                        <Box
                                            w="10" h="14" rounded="md" overflow="hidden"
                                            bg="gray.100" borderWidth="1px" borderColor="gray.200"
                                            _dark={{ bg: 'gray.700', borderColor: 'gray.600' }}
                                        >
                                            <Box
                                                as="img"
                                                src={variant.thumbnail || variant.main_image}
                                                alt={label}
                                                w="100%" h="100%" objectFit="cover"
                                            />
                                        </Box>
                                    ) : (
                                        <Flex
                                            w="10" h="14" rounded="md"
                                            bg="gray.100" _dark={{ bg: 'gray.700', borderColor: 'gray.600' }}
                                            align="center" justify="center"
                                            fontSize="xs" fontWeight="600" color="gray.500"
                                            borderWidth="1px" borderColor="gray.200"
                                        >
                                            {label.charAt(0)?.toUpperCase() || '?'}
                                        </Flex>
                                    )}
                                </Box>

                                {/* Информация */}
                                <Box flex="1" minW="0">
                                    <Text fontSize="sm" fontWeight="600" truncate>
                                        {label}
                                    </Text>
                                    <Flex align="center" justify="space-between" mt="0.5">
                                        <Box>
                                            {hasSale ? (
                                                <>
                                                    <Text as="span" fontSize="2xs" color="gray.400" textDecoration="line-through" mr="1">
                                                        {formatPrice(variant.base_price)}
                                                    </Text>
                                                    <Text as="span" fontSize="xs" fontWeight="500" color="red.600" _dark={{ color: 'red.400' }}>
                                                        {formatPrice(variant.sale_price)}
                                                    </Text>
                                                </>
                                            ) : (
                                                <Text fontSize="xs" fontWeight="500">
                                                    {formatPrice(variant.base_price)}
                                                </Text>
                                            )}
                                        </Box>
                                        <Flex align="center" gap="1">
                                            <StatusIcon size={12} color={status.iconColor} />
                                            <Text fontSize="2xs" fontWeight="500" color={status.color}>
                                                {status.text}
                                            </Text>
                                        </Flex>
                                    </Flex>
                                </Box>
                            </Flex>
                        </Box>
                    );
                })}
            </Box>
        </Box>
    );
}
