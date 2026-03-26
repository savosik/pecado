import { Box, Card, HStack, Image, Tag, Text } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import { formatDate } from '@/utils/formatDate';

/**
 * Переиспользуемая карточка контента (для новостей и статей).
 *
 * @param {{
 *   title: string,
 *   excerpt?: string,
 *   image?: string,
 *   date?: string,
 *   url: string,
 *   tags?: string[],
 * }} props
 */
export default function ContentCard({ title, excerpt, image, date, url, tags }) {
    return (
        <Card.Root
            overflow="hidden"
            bg="white"
            _dark={{ bg: 'gray.800' }}
            border="1px solid"
            borderColor="gray.100"
            borderRadius="sm"
            transition="all 0.2s"
            _hover={{
                borderColor: 'pecado.200',
                shadow: 'md',
                transform: 'translateY(-2px)',
            }}
        >
            <Link href={url} style={{ textDecoration: 'none' }}>
                <Box
                    position="relative"
                    overflow="hidden"
                    h={{ base: '180px', md: '200px' }}
                    _hover={{ '& img': { transform: 'scale(1.05)' } }}
                >
                    <Image
                        src={image || '/images/no-image.svg'}
                        alt={title}
                        w="100%"
                        h="100%"
                        objectFit="cover"
                        transition="transform 0.3s"
                        loading="lazy"
                    />
                </Box>
            </Link>

            <Card.Body p={{ base: '4', md: '5' }} gap="3">
                {/* Дата */}
                {date && (
                    <Text fontSize="xs" color="fg.muted" fontWeight="medium">
                        {formatDate(date, 'long')}
                    </Text>
                )}

                {/* Заголовок */}
                <Link href={url} style={{ textDecoration: 'none' }}>
                    <Text
                        fontWeight="semibold"
                        fontSize={{ base: 'md', md: 'lg' }}
                        lineHeight="short"
                        color="fg"
                        noOfLines={2}
                        _hover={{ color: 'pecado.500' }}
                        transition="color 0.15s"
                    >
                        {title}
                    </Text>
                </Link>

                {/* Excerpt */}
                {excerpt && (
                    <Text
                        color="fg.muted"
                        fontSize="sm"
                        lineHeight="tall"
                        noOfLines={3}
                    >
                        {excerpt}
                    </Text>
                )}

                {/* Теги */}
                {tags && tags.length > 0 && (
                    <HStack gap="2" flexWrap="wrap" mt="1">
                        {tags.map((tag) => (
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
            </Card.Body>
        </Card.Root>
    );
}
