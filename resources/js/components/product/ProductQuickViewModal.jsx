import { useEffect, useRef, useState } from 'react';
import { Box, Flex, Grid, GridItem, IconButton, Spinner, Text } from '@chakra-ui/react';
import { LuX } from 'react-icons/lu';
import { usePage } from '@inertiajs/react';
import { useProductQuickView } from '@/contexts/ProductQuickViewContext';
import { buildProductInfoProps } from '@/utils/product';
import ProductGallery from './ProductGallery';
import ProductInfo from './ProductInfo';
import ProductDetailTabs from './ProductDetailTabs';
import ProductVariants from './ProductVariants';

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
    const variants = data?.variants ?? [];
    const certificates = data?.certificates ?? [];
    const specifications = data?.specifications ?? {};

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
                h="100%"
                w="100%"
                alignItems="flex-start"
                justifyContent="center"
                overflow="hidden"
            >
                <Box
                    w="100%"
                    maxW="1360px"
                    mx={{ base: '2', md: '4' }}
                    my={{ base: '2', md: '6' }}
                >
                    <Box
                        ref={dialogRef}
                        bg="white"
                        _dark={{ bg: 'gray.800' }}
                        borderRadius={{ base: 'lg', md: 'xl' }}
                        boxShadow="xl"
                        overflow="hidden"
                        maxH={{ base: 'calc(100vh - 1rem)', md: 'calc(100vh - 3rem)' }}
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
                                <Flex align="center" justify="center" minH="200px">
                                    <Spinner size="lg" color="purple.500" />
                                    <Text ml="3" color="gray.500">Загрузка…</Text>
                                </Flex>
                            ) : (
                                <>
                                    {/* Mobile layout */}
                                    <Box display={{ base: 'block', lg: 'none' }} spaceY="6">
                                        <ProductInfo {...productInfoProps} />

                                        {variants.length > 0 && (
                                            <ProductVariants
                                                variants={variants}
                                                currentProductId={product.id}
                                                modelName={product.model_name}
                                            />
                                        )}

                                        <ProductGallery media={media} productName={product.name} />

                                        <ProductDetailTabs
                                            specifications={specifications}
                                            description={product.description_html || product.description}
                                            media={media}
                                            certificates={certificates}
                                        />
                                    </Box>

                                    {/* Desktop layout */}
                                    <Grid display={{ base: 'none', lg: 'grid' }} templateColumns="repeat(12, 1fr)" gap="6">
                                        <GridItem colSpan={4}>
                                            <ProductGallery media={media} productName={product.name} />
                                        </GridItem>

                                        <GridItem colSpan={8} spaceY="6">
                                            <ProductInfo {...productInfoProps} />

                                            {variants.length > 0 && (
                                                <ProductVariants
                                                    variants={variants}
                                                    currentProductId={product.id}
                                                    modelName={product.model_name}
                                                />
                                            )}

                                            <ProductDetailTabs
                                                specifications={specifications}
                                                description={product.description_html || product.description}
                                                media={media}
                                                certificates={certificates}
                                            />
                                        </GridItem>
                                    </Grid>
                                </>
                            )}
                        </Box>
                    </Box>
                </Box>
            </Flex>
        </Box>
    );
}
