import { Box, Card, Image, Text, Button } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import { LuShoppingBag } from 'react-icons/lu';

/**
 * Карточка статьи о бренде.
 * Содержит изображение, название бренда, краткое описание и кнопку «Товары бренда».
 */
export default function BrandStoryCard({ item }) {
    return (
        <Card.Root
            overflow="hidden"
            bg={{ base: 'white', _dark: 'gray.800' }}
            _dark={{ bg: 'gray.800' }}
            border="1px solid"
            borderColor={{ base: 'gray.100', _dark: 'gray.700' }}
            borderRadius="sm"
            transition="all 0.2s"
            display="flex"
            flexDirection="column"
            _hover={{
                borderColor: 'pecado.200',
                shadow: 'md',
                transform: 'translateY(-2px)',
            }}
        >
            <Link href={`/brand-stories/${item.slug}`} style={{ textDecoration: 'none' }}>
                <Box
                    position="relative"
                    overflow="hidden"
                    css={{ aspectRatio: '4 / 3' }}
                    _hover={{ '& img': { transform: 'scale(1.05)' } }}
                >
                    <Image
                        src={item.image || '/images/no-image.svg'}
                        alt={item.title}
                        w="100%"
                        h="100%"
                        objectFit="cover"
                        transition="transform 0.3s"
                        loading="lazy"
                    />
                </Box>
            </Link>

            <Card.Body p={{ base: '4', md: '5' }} gap="3" flex="1" display="flex" flexDirection="column">
                {/* Заголовок */}
                <Link href={`/brand-stories/${item.slug}`} style={{ textDecoration: 'none' }}>
                    <Text
                        fontWeight="semibold"
                        fontSize={{ base: 'md', md: 'lg' }}
                        lineHeight="short"
                        color="fg"
                        noOfLines={2}
                        _hover={{ color: 'pecado.500' }}
                        transition="color 0.15s"
                    >
                        {item.title}
                    </Text>
                </Link>

                {/* Краткое описание */}
                {item.excerpt && (
                    <Text
                        color="fg.muted"
                        fontSize="sm"
                        lineHeight="tall"
                        noOfLines={4}
                        flex="1"
                    >
                        {item.excerpt}
                    </Text>
                )}

                {/* Кнопка «Товары бренда» */}
                {item.brand && item.brand.slug && (
                    <Button
                        as={Link}
                        href={`/brands/${item.brand.slug}`}
                        size="sm"
                        colorPalette="pecado"
                        variant="solid"
                        w="100%"
                        mt="auto"
                    >
                        <LuShoppingBag />
                        Товары бренда
                    </Button>
                )}
            </Card.Body>
        </Card.Root>
    );
}
