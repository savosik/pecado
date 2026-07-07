import { useEffect, useRef, useState } from 'react';
import { Box, Flex, Grid, GridItem, IconButton, Skeleton, Text } from '@chakra-ui/react';
import { LuArrowRight, LuX } from 'react-icons/lu';
import { router, usePage } from '@inertiajs/react';
import { useProductQuickView } from '@/contexts/ProductQuickViewContext';
import { buildProductInfoProps, getProductDescription } from '@/utils/product';
import ProductGallery from './ProductGallery';
import ProductInfo from './ProductInfo';
import ProductDetailTabs from './ProductDetailTabs';
import ProductVariants from './ProductVariants';
import ProductPromotions from './ProductPromotions';

/**
 * Модальное окно быстрого просмотра товара.
 * Рендерит те же компоненты, что и Show.jsx, но в overlay-диалоге.
 */
export default function ProductQuickViewModal() {
    const { open, data, loading, closeQuickView } = useProductQuickView();
    const { currency } = usePage().props;
    const currencySymbol = currency?.symbol || '₽';
    const dialogRef = useRef(null);
    const [visible, setVisible] = useState(false);

    // Animate in/out
    useEffect(() => {
        if (open) {
            // Delay to allow CSS transition
            requestAnimationFrame(() => {
                requestAnimationFrame(() => setVisible(true));
            });
        } else {
            setVisible(false);
        }
    }, [open]);

    // Escape key
    useEffect(() => {
        if (!open) return;
        const onKey = (e) => {
            if (e.key === 'Escape') closeQuickView();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [open, closeQuickView]);

    if (!open) return null;

    const product = data?.product;
    const media = data?.media ?? [];
    const categoryTrail = data?.categoryTrail ?? [];
    const categoryName = categoryTrail?.[categoryTrail.length - 1]?.name || '';
    const variants = data?.variants ?? [];
    const certificates = data?.certificates ?? [];
    const specifications = data?.specifications ?? {};
    const specificationGroups = data?.specificationGroups ?? [];
    const sizeChart = data?.sizeChart ?? null;
    const similarProducts = data?.similarProducts ?? [];
    const promotions = data?.promotions ?? [];

    const productInfoProps = product ? buildProductInfoProps(product, currencySymbol) : {};

    return (
        <Box
            position="fixed"
            inset="0"
            zIndex="1500"
            onClick={(e) => {
                if (dialogRef.current && !dialogRef.current.contains(e.target)) {
                    closeQuickView();
                }
            }}
        >
            {/* Backdrop */}
            <Box
                position="absolute"
                inset="0"
                bg="blackAlpha.600"
                opacity={visible ? 1 : 0}
                transition="opacity 0.2s ease-out"
            />

            {/* Dialog container */}
            <Flex
                position="relative"
                zIndex="1"
                h="100dvh"
                w="100%"
                alignItems="flex-start"
                justifyContent="center"
                overflow="hidden"
            >
                <Box
                    w="100%"
                    maxW="1360px"
                    mx={{ base: '2', md: '4' }}
                    mt={{ base: '2', md: '6', lg: '52px' }}
                    mb={{ base: '2', md: '6' }}
                >
                    <Box
                        ref={dialogRef}
                        bg="bg"
                        _dark={{ bg: 'gray.800' }}
                        borderRadius={{ base: 'lg', md: 'xl' }}
                        boxShadow="xl"
                        overflow="hidden"
                        maxH={{ base: 'calc(100dvh - 1rem)', md: 'calc(100dvh - 3rem)', lg: 'calc(100dvh - 52px - 1.5rem)' }}
                        display="flex"
                        flexDirection="column"
                        transform={visible ? 'scale(1)' : 'scale(0.96)'}
                        opacity={visible ? 1 : 0}
                        transition="transform 0.22s cubic-bezier(0.2, 0.8, 0.2, 1), opacity 0.22s cubic-bezier(0.2, 0.8, 0.2, 1)"
                    >
                        {/* Close button */}
                        <IconButton
                            aria-label="Закрыть"
                            position="absolute"
                            right="3"
                            top="3"
                            zIndex="2"
                            variant="ghost"
                            colorPalette="gray"
                            size="md"
                            onClick={(e) => {
                                e.stopPropagation();
                                closeQuickView();
                            }}
                        >
                            <LuX size={20} />
                        </IconButton>

                        {/* Content */}
                        <Box
                            flex="1"
                            overflowY="auto"
                            overflowX="hidden"
                            px={{ base: '4', md: '6' }}
                            py={{ base: '4', md: '6' }}
                        >
                            {loading || !product ? (
                                <>
                                    {/* Mobile skeleton */}
                                    <Box display={{ base: 'block', lg: 'none' }} spaceY="6">
                                        <Box spaceY="3">
                                            <Skeleton h="3" w="24" />
                                            <Skeleton h="6" w="85%" />
                                            <Skeleton h="3" w="32" />
                                            <Flex align="baseline" gap="3" pt="1">
                                                <Skeleton h="7" w="24" />
                                                <Skeleton h="4" w="16" />
                                            </Flex>
                                            <Skeleton h="10" w="100%" mt="2" />
                                        </Box>
                                        <Flex gap="2" wrap="wrap">
                                            {[0, 1, 2, 3].map((i) => (
                                                <Skeleton key={i} h="16" w="16" borderRadius="md" />
                                            ))}
                                        </Flex>
                                        <Skeleton css={{ aspectRatio: '3 / 4' }} w="100%" borderRadius="md" />
                                        <Box spaceY="3">
                                            <Flex gap="4">
                                                <Skeleton h="5" w="24" />
                                                <Skeleton h="5" w="28" />
                                                <Skeleton h="5" w="20" />
                                            </Flex>
                                            <Skeleton h="3" w="100%" />
                                            <Skeleton h="3" w="95%" />
                                            <Skeleton h="3" w="80%" />
                                        </Box>
                                    </Box>

                                    {/* Desktop skeleton */}
                                    <Grid display={{ base: 'none', lg: 'grid' }} templateColumns="repeat(12, 1fr)" gap="6">
                                        <GridItem colSpan={4}>
                                            <Skeleton css={{ aspectRatio: '3 / 4' }} w="100%" borderRadius="md" />
                                        </GridItem>
                                        <GridItem colSpan={8} spaceY="6">
                                            <Box spaceY="3">
                                                <Skeleton h="3" w="28" />
                                                <Skeleton h="8" w="80%" />
                                                <Skeleton h="3" w="40" />
                                                <Flex align="baseline" gap="3" pt="2">
                                                    <Skeleton h="8" w="32" />
                                                    <Skeleton h="5" w="20" />
                                                </Flex>
                                                <Skeleton h="11" w="60%" mt="2" />
                                            </Box>
                                            <Flex gap="2" wrap="wrap">
                                                {[0, 1, 2, 3, 4].map((i) => (
                                                    <Skeleton key={i} h="20" w="20" borderRadius="md" />
                                                ))}
                                            </Flex>
                                            <Box spaceY="3">
                                                <Flex gap="6">
                                                    <Skeleton h="5" w="28" />
                                                    <Skeleton h="5" w="32" />
                                                    <Skeleton h="5" w="24" />
                                                </Flex>
                                                <Skeleton h="3" w="100%" />
                                                <Skeleton h="3" w="95%" />
                                                <Skeleton h="3" w="90%" />
                                                <Skeleton h="3" w="70%" />
                                            </Box>
                                        </GridItem>
                                    </Grid>
                                </>
                            ) : (
                                <>
                                    {/* Mobile layout */}
                                    <Box display={{ base: 'block', lg: 'none' }} spaceY="6">
                                        <ProductInfo
                                            {...productInfoProps}
                                            variantsSlot={variants.length > 0 ? (
                                                <ProductVariants
                                                    variants={variants}
                                                    currentProductId={product.id}
                                                    modelName={product.model_name}
                                                />
                                            ) : null}
                                            promotionsSlot={<ProductPromotions promotions={promotions} />}
                                        />

                                        <ProductGallery media={media} productName={product.name} categoryName={categoryName} />

                                        <ProductDetailTabs
                                            specifications={specifications}
                                            specificationGroups={specificationGroups}
                                            description={getProductDescription(product)}
                                            media={media}
                                            certificates={certificates}
                                            sizeChart={sizeChart}
                                            similarProducts={similarProducts}
                                        />
                                    </Box>

                                    {/* Desktop layout */}
                                    <Grid display={{ base: 'none', lg: 'grid' }} templateColumns="repeat(12, 1fr)" gap="6">
                                        <GridItem colSpan={4}>
                                            <ProductGallery media={media} productName={product.name} categoryName={categoryName} />
                                        </GridItem>

                                        <GridItem colSpan={8} spaceY="6">
                                            <ProductInfo
                                                {...productInfoProps}
                                                variantsSlot={variants.length > 0 ? (
                                                    <ProductVariants
                                                        variants={variants}
                                                        currentProductId={product.id}
                                                        modelName={product.model_name}
                                                    />
                                                ) : null}
                                                promotionsSlot={<ProductPromotions promotions={promotions} />}
                                            />

                                            <ProductDetailTabs
                                                specifications={specifications}
                                                specificationGroups={specificationGroups}
                                                description={getProductDescription(product)}
                                                media={media}
                                                certificates={certificates}
                                                sizeChart={sizeChart}
                                                similarProducts={similarProducts}
                                            />
                                        </GridItem>
                                    </Grid>
                                </>
                            )}
                        </Box>

                        {/* Подвал — переход на страницу товара */}
                        {!loading && product?.slug && (
                            <Flex
                                as="button"
                                type="button"
                                onClick={(e) => {
                                    e.stopPropagation();
                                    const slug = product.slug;
                                    closeQuickView();
                                    router.visit(`/products/${encodeURIComponent(slug)}`);
                                }}
                                align="center"
                                justify="center"
                                gap="2"
                                w="100%"
                                py="1.5"
                                borderTopWidth="1px"
                                borderColor="border"
                                color="fg.muted"
                                cursor="pointer"
                                transition="background 0.15s, color 0.15s"
                                _hover={{ bg: 'bg.muted', color: 'fg' }}
                            >
                                <Text fontSize="sm" fontWeight="medium">
                                    Перейти на страницу товара
                                </Text>
                                <LuArrowRight size={16} />
                            </Flex>
                        )}
                    </Box>
                </Box>
            </Flex>
        </Box>
    );
}
