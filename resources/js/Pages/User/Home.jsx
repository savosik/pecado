import { Box } from '@chakra-ui/react';
import { Head } from '@inertiajs/react';
import UserLayout from './UserLayout';
import SeoHead from '@/components/common/SeoHead';
import BannerSlider from '@/components/banner/BannerSlider';
import StoryCircles from '@/components/stories/StoryCircles';
import ProductSelectionTabs from '@/components/product/ProductSelectionCarousel';
import ProductGrid from '@/components/product/ProductGrid';

export default function Home({
    banners = [],
    stories = [],
    productSelections = [],
    newProducts = [],
    bestsellerProducts = [],
    seo,
}) {
    return (
        <UserLayout>
            <SeoHead seo={seo} />
            <Head title={seo?.title || 'Pecado — Интернет-магазин для взрослых'} />

            <StoryCircles stories={stories} />
            <BannerSlider banners={banners} />

            {productSelections.length > 0 && (
                <Box mt="6">
                    <ProductSelectionTabs selections={productSelections} />
                </Box>
            )}

            {newProducts.length > 0 && (
                <Box mt="6">
                    <ProductGrid
                        title="Новинки"
                        products={newProducts}
                        linkUrl="/products?sort=newest"
                        linkText="Смотреть все новинки"
                        maxItems={5}
                    />
                </Box>
            )}

            {bestsellerProducts.length > 0 && (
                <Box mt="6">
                    <ProductGrid
                        title="Бестселлеры"
                        products={bestsellerProducts}
                        linkUrl="/products?sort=bestsellers"
                        linkText="Смотреть все бестселлеры"
                        maxItems={5}
                    />
                </Box>
            )}
        </UserLayout>
    );
}
