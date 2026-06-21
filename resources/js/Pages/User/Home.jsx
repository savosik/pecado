import { Box, Heading } from '@chakra-ui/react';
import { usePage } from '@inertiajs/react';
import UserLayout from './UserLayout';
import SeoHead from '@/components/common/SeoHead';
import BannerSlider from '@/components/banner/BannerSlider';
import StoryCircles from '@/components/stories/StoryCircles';
import ProductSelectionTabs from '@/components/product/ProductSelectionCarousel';
import ProductGrid from '@/components/product/ProductGrid';
import WelcomeDialog from '@/components/common/WelcomeDialog';

export default function Home({
    banners = [],
    stories = [],
    productSelections = [],
    newProducts = [],
    bestsellerProducts = [],
    seo,
}) {
    const { flash, auth } = usePage().props;
    const showWelcome = flash?.onboarding_completed;

    return (
        <UserLayout fluid flushTop={stories.length === 0}>
            {showWelcome && <WelcomeDialog userName={auth?.user?.name} />}
            <SeoHead seo={seo} />

            {/* Единственный H1 страницы (визуально скрыт — дизайн главной без заголовка). */}
            <Heading as="h1" srOnly>
                {seo?.h1 || 'Секс-шоп Pecado'}
            </Heading>

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
                        linkUrl="/products/novinki"
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
                        linkUrl="/products/bestsellery"
                        linkText="Смотреть все бестселлеры"
                        maxItems={5}
                    />
                </Box>
            )}
        </UserLayout>
    );
}
