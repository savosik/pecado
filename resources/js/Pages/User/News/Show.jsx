import { Box, HStack, Image, Tag, Text } from '@chakra-ui/react';
import UserLayout from '../UserLayout';
import SeoHead from '@/components/common/SeoHead';
import Breadcrumbs from '@/components/common/Breadcrumbs';
import PageHeader from '@/components/common/PageHeader';
import { Prose } from '@/components/ui/prose';
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
                bg="white"
                _dark={{ bg: 'gray.800' }}
                border="1px solid"
                borderColor="gray.100"
                borderRadius="sm"
                overflow="hidden"
            >
                {/* Изображение */}
                {newsItem.image && (
                    <Image
                        src={newsItem.image}
                        alt={newsItem.title}
                        w="100%"
                        maxH="480px"
                        objectFit="cover"
                    />
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

                    {/* HTML-контент */}
                    <Prose
                        size="lg"
                        maxW="none"
                        dangerouslySetInnerHTML={{ __html: newsItem.content }}
                    />
                </Box>
            </Box>
        </UserLayout>
    );
}
