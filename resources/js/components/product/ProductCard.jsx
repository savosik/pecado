import { Box, Text, Badge, Image } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';

/**
 * ProductCard — базовая карточка товара для каталога и подборок.
 *
 * @param {Object} props
 * @param {Object} props.product — { id, name, slug, main_image, brand_name, is_new, is_bestseller }
 */
export default function ProductCard({ product }) {
    const imageSrc = product.main_image || '/images/no-image.svg';

    return (
        <Box
            as={Link}
            href={`/products/${product.slug}`}
            display="block"
            borderRadius="xl"
            overflow="hidden"
            border="1px solid"
            borderColor="gray.100"
            _dark={{ borderColor: 'gray.700', bg: 'gray.800' }}
            transition="all 0.2s"
            _hover={{ shadow: 'lg', transform: 'translateY(-2px)', borderColor: 'pink.200' }}
            bg="white"
            cursor="pointer"
            textDecoration="none"
        >
            {/* Изображение */}
            <Box position="relative" css={{ aspectRatio: '1 / 1' }} bg="gray.50" _dark={{ bg: 'gray.700' }}>
                <Image
                    src={imageSrc}
                    alt={product.name}
                    w="100%"
                    h="100%"
                    objectFit="cover"
                    loading="lazy"
                />

                {/* Бейджи */}
                <Box position="absolute" top="2" left="2" display="flex" flexDirection="column" gap="1">
                    {product.is_new && (
                        <Badge
                            colorPalette="green"
                            fontSize="xs"
                            fontWeight="700"
                            borderRadius="md"
                            px="2"
                        >
                            Новинка
                        </Badge>
                    )}
                    {product.is_bestseller && (
                        <Badge
                            colorPalette="orange"
                            fontSize="xs"
                            fontWeight="700"
                            borderRadius="md"
                            px="2"
                        >
                            Хит
                        </Badge>
                    )}
                </Box>
            </Box>

            {/* Информация */}
            <Box p="3">
                {product.brand_name && (
                    <Text
                        fontSize="xs"
                        color="gray.400"
                        fontWeight="500"
                        textTransform="uppercase"
                        letterSpacing="0.05em"
                        mb="1"
                    >
                        {product.brand_name}
                    </Text>
                )}
                <Text
                    fontSize="sm"
                    fontWeight="600"
                    lineClamp="2"
                    minH="40px"
                    color="gray.800"
                    _dark={{ color: 'gray.100' }}
                >
                    {product.name}
                </Text>
            </Box>
        </Box>
    );
}
