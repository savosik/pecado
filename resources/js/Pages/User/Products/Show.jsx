import { useEffect } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { Box, Grid, GridItem } from '@chakra-ui/react';
import UserLayout from '../UserLayout';
import ProductBreadcrumbs from '@/components/product/ProductBreadcrumbs';
import ProductGallery from '@/components/product/ProductGallery';
import ProductInfo from '@/components/product/ProductInfo';
import ProductDetailTabs from '@/components/product/ProductDetailTabs';
import ProductVariants from '@/components/product/ProductVariants';
import MobileActionsBar from '@/components/product/MobileActionsBar';
import { buildProductInfoProps, getProductDescription } from '@/utils/product';

/**
 * Show — детальная страница товара.
 */
export default function Show() {
    const { product, media, categoryTrail, variants, certificates, specifications, specificationGroups, sizeChart, similarProducts, currency, auth } = usePage().props;
    const currencySymbol = currency?.symbol || '₽';
    const user = auth?.user || null;

    const productInfoProps = buildProductInfoProps(product, currencySymbol);
    const { price, isPreorder, inStock: isInStock, stockQuantity, preorderQuantity } = productInfoProps;

    // Флаг для глобального перехватчика QuickView в bootstrap.js:
    // на детальной странице товара клики по ссылкам /products/{slug} идут обычной навигацией.
    useEffect(() => {
        window.__isProductDetailPage = true;
        return () => { window.__isProductDetailPage = false; };
    }, []);

    return (
        <UserLayout>
            <Head title={product.name} />

            {/* Хлебные крошки */}
            <ProductBreadcrumbs categoryTrail={categoryTrail} productName={product.name} />

            {/* Белый контейнер-карточка */}
            <Box
                bg="bg"
                _dark={{ bg: 'gray.800' }}
                borderRadius="xl"
                boxShadow="sm"
                p={{ base: '4', md: '6' }}
            >
                {/* Mobile: info → варианты → галерея → табы */}
                <Box display={{ base: 'block', lg: 'none' }} spaceY="6">
                    <ProductInfo {...productInfoProps} />

                    {variants && variants.length > 0 && (
                        <ProductVariants
                            variants={variants}
                            currentProductId={product.id}
                            modelName={product.model_name}
                        />
                    )}

                    <ProductGallery media={media} productName={product.name} />

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

                {/* Desktop: gallery (4) + info (8) */}
                <Grid display={{ base: 'none', lg: 'grid' }} templateColumns="repeat(12, 1fr)" gap="6">
                    <GridItem colSpan={4}>
                        <ProductGallery media={media} productName={product.name} />
                    </GridItem>

                    <GridItem colSpan={8} spaceY="6">
                        <ProductInfo {...productInfoProps} />

                        {variants && variants.length > 0 && (
                            <ProductVariants
                                variants={variants}
                                currentProductId={product.id}
                                modelName={product.model_name}
                            />
                        )}

                        <ProductDetailTabs
                            specifications={specifications}
                            specificationGroups={specificationGroups}
                            description={getProductDescription(product)}
                            media={media}
                            certificates={certificates}
                            sizeChart={sizeChart}
                        />
                    </GridItem>
                </Grid>
            </Box>

            {/* Мобильная sticky-панель — только для авторизованных */}
            {user && (
                <MobileActionsBar
                    productId={product.id}
                    name={product.name}
                    price={price}
                    isPreorder={isPreorder}
                    inStock={isInStock}
                    stockQuantity={stockQuantity}
                    preorderQuantity={preorderQuantity}
                />
            )}
        </UserLayout>
    );
}
