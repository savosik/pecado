import { Box, HStack, Image, Tag, Text } from '@chakra-ui/react';
import UserLayout from '../UserLayout';
import SeoHead from '@/components/common/SeoHead';
import Breadcrumbs from '@/components/common/Breadcrumbs';
import PageHeader from '@/components/common/PageHeader';
import { Prose } from '@/components/ui/prose';
import { formatDate } from '@/utils/formatDate';

/**
 * Детальная страница статьи.
 *
 * @param {{ article: object, seo: object, breadcrumbs: Array }} props
 */
export default function ArticleShow({ article, seo, breadcrumbs }) {
    return (
        <UserLayout>
            <SeoHead seo={seo} />
            <Breadcrumbs items={breadcrumbs} />
            <PageHeader title={article.title} />

            <Box
                bg={{ base: 'white', _dark: 'gray.800' }}
                _dark={{ bg: 'gray.800' }}
                border="1px solid"
                borderColor={{ base: 'gray.100', _dark: 'gray.700' }}
                borderRadius="sm"
                overflow="hidden"
            >
                {/* Изображение */}
                {article.image && (
                    <Box
                        position="relative"
                        overflow="hidden"
                        css={{
                            aspectRatio: { base: '3 / 2', md: '8 / 3' },
                        }}
                    >
                        <Image
                            src={article.image}
                            alt={article.title}
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
                        {article.published_at && (
                            <Text fontSize="sm" color="fg.muted">
                                {formatDate(article.published_at, 'long')}
                            </Text>
                        )}

                        {article.tags && article.tags.length > 0 && (
                            <HStack gap="2" flexWrap="wrap">
                                {article.tags.map((tag) => (
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
                        dangerouslySetInnerHTML={{ __html: article.content }}
                    />
                </Box>
            </Box>
        </UserLayout>
    );
}
