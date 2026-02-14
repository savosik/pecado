import { Head, usePage } from '@inertiajs/react';
import { Box, Grid, GridItem } from '@chakra-ui/react';
import UserLayout from '../UserLayout';
import ProductBreadcrumbs from '@/components/product/ProductBreadcrumbs';
import ProductGallery from '@/components/product/ProductGallery';
import ProductInfo from '@/components/product/ProductInfo';
import ProductDetailTabs from '@/components/product/ProductDetailTabs';
import ProductVariants from '@/components/product/ProductVariants';
import MobileActionsBar from '@/components/product/MobileActionsBar';

/**
 * Show — детальная страница товара.
 */
export default function Show() {
    const { product, media, categoryTrail, variants, certificates, specifications, currency } = usePage().props;
    const currencySymbol = currency?.symbol || '₽';

    const price = product.sale_price ?? product.base_price;
    const originalPrice = product.sale_price != null && product.sale_price < product.base_price
        ? product.base_price
        : null;

    const stockQty = product.stock_quantity || 0;
    const preorderQty = product.preorder_quantity || 0;
    const isInStock = stockQty > 0;
    const isPreorder = !isInStock && preorderQty > 0;

    return (
        <UserLayout>
            <Head title={product.name} />

            {/* Хлебные крошки */}
            <ProductBreadcrumbs categoryTrail={categoryTrail} productName={product.name} />

            {/* Белый контейнер-карточка */}
            <Box
                bg="white"
                _dark={{ bg: 'gray.800' }}
                borderRadius="xl"
                boxShadow="sm"
                p={{ base: '4', md: '6' }}
            >
                {/* Mobile: info → варианты → галерея → табы */}
                <Box display={{ base: 'block', lg: 'none' }} spaceY="6">
                    <ProductInfo
                        productId={product.id}
                        name={product.name}
                        price={price}
                        originalPrice={originalPrice}
                        currencySymbol={currencySymbol}
                        sku={product.sku}
                        code={product.code}
                        barcodes={product.barcodes || []}
                        brand={product.brand}
                        category={product.category}
                        isNew={product.is_new}
                        isBestseller={product.is_bestseller}
                        inStock={isInStock}
                        isPreorder={isPreorder}
                        tags={product.tags || []}
                        discountPct={product.discount_percentage}
                    />

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
                        description={product.description_html || product.description}
                        media={media}
                        certificates={certificates}
                    />
                </Box>

                {/* Desktop: gallery (4) + info (8) */}
                <Grid display={{ base: 'none', lg: 'grid' }} templateColumns="repeat(12, 1fr)" gap="6">
                    <GridItem colSpan={4}>
                        <ProductGallery media={media} productName={product.name} />
                    </GridItem>

                    <GridItem colSpan={8} spaceY="6">
                        <ProductInfo
                            productId={product.id}
                            name={product.name}
                            price={price}
                            originalPrice={originalPrice}
                            currencySymbol={currencySymbol}
                            sku={product.sku}
                            code={product.code}
                            barcodes={product.barcodes || []}
                            brand={product.brand}
                            category={product.category}
                            isNew={product.is_new}
                            isBestseller={product.is_bestseller}
                            inStock={isInStock}
                            isPreorder={isPreorder}
                            tags={product.tags || []}
                            discountPct={product.discount_percentage}
                        />

                        {variants && variants.length > 0 && (
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
            </Box>

            {/* Мобильная sticky-панель */}
            <MobileActionsBar
                productId={product.id}
                name={product.name}
                price={price}
                isPreorder={isPreorder}
                inStock={isInStock}
            />
        </UserLayout>
    );
}
