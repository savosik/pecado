import { useState, useEffect } from 'react';
import { Box, Flex, Text, Badge, IconButton, Heading } from '@chakra-ui/react';
import { Tooltip } from '@/components/ui/tooltip';
import { LuHash, LuCode, LuQrCode, LuCopy, LuHeart, LuCheck, LuClock3, LuCircleX } from 'react-icons/lu';
import { Link, usePage } from '@inertiajs/react';
import TagList from './TagList';
import CartQuantityControl from './CartQuantityControl';
import { useFavoritesStore } from '@/stores/useFavoritesStore';
import { LOGIN_URL } from '@/constants/user';

/**
 * ProductInfo — информационная панель товара с ценой, SKU, наличием, избранным и корзиной.
 */
export default function ProductInfo({
    productId,
    name,
    price,
    originalPrice = null,
    currencySymbol = '₽',
    sku,
    code,
    barcodes = [],
    brand = null,
    category = null,
    isNew = false,
    isBestseller = false,
    inStock = true,
    isPreorder = false,
    tags = [],
    discountPct = null,
}) {
    const { auth } = usePage().props;
    const user = auth?.user || null;
    const [isFav, setIsFav] = useState(false);
    const [copiedField, setCopiedField] = useState(null);

    // Подписка на избранное
    useEffect(() => {
        if (!user || !productId) return;
        const store = useFavoritesStore.getState();
        store.loadOnce(user);
        setIsFav(store.isFavorite(productId));
        const unsub = useFavoritesStore.subscribe((state) => {
            setIsFav(state.ids.has(Number(productId)));
        });
        return unsub;
    }, [productId, user]);

    const toggleFavorite = async (e) => {
        e?.preventDefault?.();
        e?.stopPropagation?.();
        if (!user) {
            window.location.href = LOGIN_URL;
            return;
        }
        useFavoritesStore.getState().toggle(productId);
    };

    const copyToClipboard = async (text, fieldName) => {
        try {
            await navigator.clipboard.writeText(text);
            setCopiedField(fieldName);
            setTimeout(() => setCopiedField(null), 1500);
        } catch { }
    };

    const hasSale = originalPrice != null && originalPrice > price;

    const formatPrice = (value) => {
        if (value === null || value === undefined) return '';
        return Number(value).toLocaleString('ru-RU') + ' ' + currencySymbol;
    };

    const CopyableField = ({ icon: Icon, value, fieldKey, label }) => {
        const isCopied = copiedField === fieldKey;
        const tooltipContent = isCopied ? 'Скопировано!' : `${label} — нажмите, чтобы скопировать`;
        return (
            <Tooltip content={tooltipContent} positioning={{ placement: 'top' }} openDelay={200} closeDelay={0}>
                <Flex
                    as="button"
                    align="center" gap="1.5"
                    fontSize="xs"
                    color={isCopied ? 'green.600' : 'gray.500'}
                    bg={isCopied ? 'green.50' : 'gray.50'}
                    borderColor={isCopied ? 'green.200' : 'gray.200'}
                    _dark={{
                        color: isCopied ? 'green.400' : 'gray.400',
                        bg: isCopied ? 'green.900/30' : 'gray.800',
                        borderColor: isCopied ? 'green.700' : 'gray.600',
                    }}
                    _hover={{ color: 'gray.700', bg: 'gray.100', _dark: { color: 'gray.200', bg: 'gray.700' } }}
                    transition="all 0.15s"
                    cursor="pointer"
                    px="2" py="1" rounded="md"
                    borderWidth="1px"
                    onClick={() => copyToClipboard(value, fieldKey)}
                >
                    <Icon size={12} style={{ flexShrink: 0 }} />
                    <Text truncate maxW={{ base: '120px', sm: 'none' }}
                        textDecoration="underline" textDecorationStyle="dotted"
                        textUnderlineOffset="2px" textDecorationColor="gray.300"
                    >
                        {value}
                    </Text>
                    {isCopied ? (
                        <LuCheck size={12} style={{ flexShrink: 0 }} />
                    ) : (
                        <LuCopy size={12} style={{ flexShrink: 0, opacity: 0.4 }} />
                    )}
                </Flex>
            </Tooltip>
        );
    };

    return (
        <Box spaceY="5">
            {/* Бренд / Категория */}
            <Box>
                <Flex align="center" gap="2" fontSize="sm" color="gray.500" _dark={{ color: 'gray.400' }} mb="2">
                    {brand && (
                        <Text
                            as={Link} href={`/brands/${brand.slug}`}
                            _hover={{ color: 'gray.700', _dark: { color: 'gray.200' } }}
                            transition="color 0.15s"
                        >
                            {brand.name}
                        </Text>
                    )}
                    {brand && category && <Text>/</Text>}
                    {category && (
                        <Text
                            as={Link} href={`/catalog?category=${category.slug}`}
                            _hover={{ color: 'gray.700', _dark: { color: 'gray.200' } }}
                            transition="color 0.15s"
                        >
                            {category.name}
                        </Text>
                    )}
                </Flex>

                <Heading as="h1" size="2xl" mb="3" lineHeight="1.2" css={{ wordBreak: 'break-word' }}>
                    {name}
                </Heading>

                {/* Теги */}
                {tags && tags.length > 0 && (
                    <Box mb="3">
                        <TagList tags={tags} maxVisible={5} />
                    </Box>
                )}

                {/* SKU / Код / Штрихкоды */}
                <Flex wrap="wrap" align="center" gap={{ base: '2', sm: '4' }} mb="2">
                    {sku && <CopyableField icon={LuHash} value={sku} fieldKey="sku" label="Артикул" />}
                    {code && <CopyableField icon={LuCode} value={code} fieldKey="code" label="Код товара" />}
                    {barcodes.map((b) => (
                        <CopyableField key={b.id} icon={LuQrCode} value={b.barcode} fieldKey={`barcode-${b.id}`} label="Штрихкод" />
                    ))}
                </Flex>

                {/* Бейджи */}
                <Flex gap="2" wrap="wrap">
                    {isNew && (
                        <Badge colorPalette="green" fontSize="2xs" fontWeight="700" borderRadius="md" px="2">
                            Новинка
                        </Badge>
                    )}
                    {isBestseller && (
                        <Badge colorPalette="orange" fontSize="2xs" fontWeight="700" borderRadius="md" px="2">
                            Хит
                        </Badge>
                    )}
                    {hasSale && discountPct && (
                        <Badge colorPalette="red" fontSize="2xs" fontWeight="700" borderRadius="md" px="2">
                            −{Math.round(discountPct)}%
                        </Badge>
                    )}
                </Flex>
            </Box>

            {/* Цена и корзина */}
            <Box data-sticky-anchor="true">
                {/* Статус наличия */}
                <Flex align="center" gap="1" fontSize="sm" fontWeight="500" mb="2">
                    {inStock ? (
                        <>
                            <LuCheck size={16} color="var(--chakra-colors-green-600)" />
                            <Text color="green.600">В наличии</Text>
                        </>
                    ) : isPreorder ? (
                        <>
                            <LuClock3 size={16} color="var(--chakra-colors-orange-500)" />
                            <Text color="orange.500">Предзаказ</Text>
                        </>
                    ) : (
                        <>
                            <LuCircleX size={16} color="var(--chakra-colors-red-600)" />
                            <Text color="red.600">Нет в наличии</Text>
                        </>
                    )}
                </Flex>

                {/* Цена */}
                <Box spaceY="1" mb="4">
                    {hasSale && (
                        <Text fontSize="sm" color="gray.400" textDecoration="line-through">
                            {formatPrice(originalPrice)}
                        </Text>
                    )}
                    <Text
                        fontSize="2xl" fontWeight="600"
                        color={hasSale ? 'red.600' : undefined}
                        _dark={{ color: hasSale ? 'red.400' : undefined }}
                    >
                        {formatPrice(price)}
                    </Text>
                </Box>

                {/* Корзина + Избранное */}
                {user && (
                    <Flex align="center" gap="3">
                        {(inStock || isPreorder) && price > 0 && (
                            <Box w="200px">
                                <CartQuantityControl productId={productId} />
                            </Box>
                        )}
                        <IconButton
                            aria-label="В избранное"
                            variant="ghost"
                            size="md"
                            rounded="sm"
                            onClick={toggleFavorite}
                            color={isFav ? 'red.500' : 'gray.400'}
                            _hover={{ color: 'red.500' }}
                        >
                            <LuHeart size={20} fill={isFav ? 'currentColor' : 'none'} />
                        </IconButton>
                    </Flex>
                )}

                {!inStock && !isPreorder && (
                    <Text fontSize="sm" color="gray.500" mt="2">
                        Товар временно недоступен
                    </Text>
                )}
            </Box>
        </Box>
    );
}
