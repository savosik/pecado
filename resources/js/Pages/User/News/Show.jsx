import { Box, HStack, Image, Tag, Text } from '@chakra-ui/react';
import UserLayout from '../UserLayout';
import SeoHead from '@/components/common/SeoHead';
import Breadcrumbs from '@/components/common/Breadcrumbs';
import PageHeader from '@/components/common/PageHeader';
import ContentRenderer from '@/components/content/ContentRenderer';
import { formatDate } from '@/utils/formatDate';

/**
 * Детальная страница новости.
 *
 * @param {{ newsItem: object, seo: object, breadcrumbs: Array }} props
 */
export default function NewsShow({ newsItem, seo, breadcrumbs }) {
    return (
        <UserLayout>
            <SeoHead seo={seo} />
            <Breadcrumbs items={breadcrumbs} />
            <PageHeader title={newsItem.title} />

            <Box
                bg={{ base: 'white', _dark: 'gray.800' }}
                _dark={{ bg: 'gray.800' }}
                border="1px solid"
                borderColor={{ base: 'gray.100', _dark: 'gray.700' }}
                borderRadius="sm"
                overflow="hidden"
            >
                {/* Изображение */}
                {newsItem.image && (
                    <Box
                        position="relative"
                        overflow="hidden"
                        css={{
                            aspectRatio: { base: '3 / 2', md: '8 / 3' },
                        }}
                    >
                        <Image
                            src={newsItem.image}
                            alt={newsItem.title}
                            w="100%"
                            h="100%"
                            objectFit="cover"
                        />
                    </Box>
                )}

                {/* Контент */}
                <Box p={{ base: '5', md: '8' }}>
                    {/* Мета: дата + теги */}
                    <HStack gap="4" mb="6" flexWrap="wrap">
                        {newsItem.published_at && (
                            <Text fontSize="sm" color="fg.muted">
                                {formatDate(newsItem.published_at, 'long')}
                            </Text>
                        )}

                        {newsItem.tags && newsItem.tags.length > 0 && (
                            <HStack gap="2" flexWrap="wrap">
                                {newsItem.tags.map((tag) => (
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

                    {/* Контент (JSON-блоки или HTML) */}
                    <ContentRenderer content={newsItem.content} />
                </Box>
            </Box>
        </UserLayout>
    );
}
