import { Box, Grid, GridItem, Heading, Image, SimpleGrid, Text } from '@chakra-ui/react';
import UserLayout from '../UserLayout';
import SeoHead from '@/components/common/SeoHead';
import Breadcrumbs from '@/components/common/Breadcrumbs';
import PageHeader from '@/components/common/PageHeader';
import ProductCard from '@/components/product/ProductCard';
import { Prose } from '@/components/ui/prose';
import { formatDate } from '@/utils/formatDate';

/**
 * Детальная страница акции.
 *
 * @param {{ promotion: object, seo: object, breadcrumbs: Array }} props
 */
export default function PromotionShow({ promotion, seo, breadcrumbs }) {
    return (
        <UserLayout>
            <SeoHead seo={seo} />
            <Breadcrumbs items={breadcrumbs} />
            <PageHeader title={promotion.name} />

            <Box
                bg="white"
                _dark={{ bg: 'gray.800' }}
                border="1px solid"
                borderColor="gray.100"
                borderRadius="sm"
                overflow="hidden"
            >
                {/* Баннер */}
                {promotion.image && (
                    <Box>
                        <Image
                            src={promotion.image}
                            alt={promotion.name}
                            w="100%"
                            maxH="480px"
                            objectFit="cover"
                            display={{ base: promotion.mobile_image ? 'none' : 'block', md: 'block' }}
                        />
                        {promotion.mobile_image && (
                            <Image
                                src={promotion.mobile_image}
                                alt={promotion.name}
                                w="100%"
                                maxH="400px"
                                objectFit="cover"
                                display={{ base: 'block', md: 'none' }}
                            />
                        )}
                    </Box>
                )}

                {/* Контент */}
                <Box p={{ base: '5', md: '8' }}>
                    {/* Дата */}
                    {promotion.created_at && (
                        <Text fontSize="sm" color="fg.muted" mb="4">
                            {formatDate(promotion.created_at, 'long')}
                        </Text>
                    )}

                    {/* HTML-контент (описание) */}
                    {promotion.content && (
                        <Prose
                            size="lg"
                            maxW="none"
                            dangerouslySetInnerHTML={{ __html: promotion.content }}
                        />
                    )}
                </Box>
            </Box>

            {/* Галерея */}
            {promotion.gallery && promotion.gallery.length > 0 && (
                <Box mt="8">
                    <Heading as="h2" size={{ base: 'md', md: 'lg' }} fontWeight="bold" mb="4">
                        Галерея
                    </Heading>
                    <SimpleGrid columns={{ base: 2, md: 3, lg: 4 }} gap="4">
                        {promotion.gallery.map((item) => (
                            <Box
                                key={item.id}
                                borderRadius="sm"
                                overflow="hidden"
                                border="1px solid"
                                borderColor="gray.100"
                                _dark={{ borderColor: 'gray.700' }}
                            >
                                <Image
                                    src={item.url}
                                    alt=""
                                    w="100%"
                                    h="200px"
                                    objectFit="cover"
                                    loading="lazy"
                                />
                            </Box>
                        ))}
                    </SimpleGrid>
                </Box>
            )}

            {/* Товары по акции */}
            {promotion.products && promotion.products.length > 0 && (
                <Box mt="8">
                    <Heading as="h2" size={{ base: 'md', md: 'lg' }} fontWeight="bold" mb="4">
                        Товары по акции
                    </Heading>
                    <Grid
                        templateColumns={{
                            base: 'repeat(2, minmax(0, 1fr))',
                            md: 'repeat(3, minmax(0, 1fr))',
                            lg: 'repeat(4, minmax(0, 1fr))',
                            xl: 'repeat(5, minmax(0, 1fr))',
                        }}
                        gap={{ base: '3', md: '4' }}
                    >
                        {promotion.products.map((product) => (
                            <GridItem key={product.id} h="100%" overflow="hidden">
                                <ProductCard product={product} />
                            </GridItem>
                        ))}
                    </Grid>
                </Box>
            )}
        </UserLayout>
    );
}
