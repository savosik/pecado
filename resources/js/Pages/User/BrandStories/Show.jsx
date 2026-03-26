import { Box, HStack, Image, Tag, Text, Button } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import { LuShoppingBag } from 'react-icons/lu';
import UserLayout from '../UserLayout';
import SeoHead from '@/components/common/SeoHead';
import Breadcrumbs from '@/components/common/Breadcrumbs';
import PageHeader from '@/components/common/PageHeader';
import { Prose } from '@/components/ui/prose';
import { formatDate } from '@/utils/formatDate';

/**
 * Детальная страница статьи о бренде.
 */
export default function BrandStoryShow({ brandStory, seo, breadcrumbs }) {
    return (
        <UserLayout>
            <SeoHead seo={seo} />
            <Breadcrumbs items={breadcrumbs} />
            <PageHeader title={brandStory.title} />

            <Box
                bg="white" _dark={{ bg: "gray.800" }}
                _dark={{ bg: 'gray.800' }}
                border="1px solid"
                borderColor="gray.100" _dark={{ borderColor: "gray.700" }}
                borderRadius="sm"
                overflow="hidden"
            >
                {/* Изображение */}
                {brandStory.image && (
                    <Image
                        src={brandStory.image}
                        alt={brandStory.title}
                        w="100%"
                        maxH="480px"
                        objectFit="cover"
                    />
                )}

                {/* Контент */}
                <Box p={{ base: '5', md: '8' }}>
                    {/* Мета: дата + теги + бренд */}
                    <HStack gap="4" mb="6" flexWrap="wrap">
                        {brandStory.published_at && (
                            <Text fontSize="sm" color="fg.muted">
                                {formatDate(brandStory.published_at, 'long')}
                            </Text>
                        )}

                        {brandStory.brand && (
                            <Tag.Root size="sm" variant="subtle" colorPalette="blue">
                                <Tag.Label>{brandStory.brand.name}</Tag.Label>
                            </Tag.Root>
                        )}

                        {brandStory.tags && brandStory.tags.length > 0 && (
                            <HStack gap="2" flexWrap="wrap">
                                {brandStory.tags.map((tag) => (
                                    <Tag.Root
                                        key={tag}
                                        size="sm"
                                        variant="subtle"
                                        colorPalette="pecado"
                                    >
                                        <Tag.Label>{tag}</Tag.Label>
                                    </Tag.Root>
                                ))}
                            </HStack>
                        )}
                    </HStack>

                    {/* HTML-контент */}
                    <Prose
                        size="lg"
                        maxW="none"
                        dangerouslySetInnerHTML={{ __html: brandStory.content }}
                    />

                    {/* Кнопка «Товары бренда» */}
                    {brandStory.brand && brandStory.brand.slug && (
                        <Box mt="8">
                            <Button
                                as={Link}
                                href={`/brands/${brandStory.brand.slug}`}
                                size="lg"
                                colorPalette="pecado"
                                variant="solid"
                            >
                                <LuShoppingBag />
                                Товары бренда {brandStory.brand.name}
                            </Button>
                        </Box>
                    )}
                </Box>
            </Box>
        </UserLayout>
    );
}
