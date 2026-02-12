import { Box } from '@chakra-ui/react';
import { Head } from '@inertiajs/react';
import UserLayout from './UserLayout';
import SeoHead from '@/components/common/SeoHead';
import BannerSlider from '@/components/banner/BannerSlider';
import StoryCircles from '@/components/stories/StoryCircles';
import ProductSelectionTabs from '@/components/product/ProductSelectionCarousel';

export default function Home({ banners = [], stories = [], productSelections = [], seo }) {
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
        </UserLayout>
    );
}
