import { Box, Flex, Text } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import { LuChevronRight } from 'react-icons/lu';

/**
 * ProductBreadcrumbs — хлебные крошки: Каталог → Категория → ... → Товар.
 *
 * @param {{ categoryTrail: Array<{id, name, slug, parent_id}>, productName: string }} props
 */
export default function ProductBreadcrumbs({ categoryTrail = [], productName = '' }) {
    const items = [
        { label: 'Каталог', href: '/products' },
    ];

    categoryTrail.forEach(cat => {
        items.push({
            label: cat.name,
            href: `/products?category=${cat.slug}`,
        });
    });

    // Последний элемент — имя товара (без ссылки)
    if (productName) {
        items.push({ label: productName });
    }

    return (
        <Box
            as="nav"
            fontSize="sm"
            color="gray.500"
            _dark={{ color: 'gray.400' }}
            mb="4"
            overflowX="auto"
            css={{ scrollbarWidth: 'none', '&::-webkit-scrollbar': { display: 'none' } }}
            mx="-2" px="2"
        >
            <Flex align="center" gap="1" minW="max-content">
                {items.map((item, index) => (
                    <Flex key={index} align="center" flexShrink={0}>
                        {index > 0 && (
                            <Box mx={{ base: '0.5', sm: '1' }} flexShrink={0} color="gray.400">
                                <LuChevronRight size={14} />
                            </Box>
                        )}
                        {item.href ? (
                            <Text
                                as={Link}
                                href={item.href}
                                _hover={{ color: 'gray.700', _dark: { color: 'gray.200' } }}
                                transition="color 0.15s"
                                truncate
                                maxW={{ base: '80px', sm: '150px', md: '200px', lg: '250px' }}
                                whiteSpace="nowrap"
                                title={item.label}
                            >
                                {item.label}
                            </Text>
                        ) : (
                            <Text
                                color={index === items.length - 1 ? 'gray.700' : undefined}
                                _dark={{ color: index === items.length - 1 ? 'gray.200' : undefined }}
                                truncate
                                maxW={{ base: '100px', sm: '150px', md: '200px', lg: '300px' }}
                                whiteSpace="nowrap"
                                title={item.label}
                            >
                                {item.label}
                            </Text>
                        )}
                    </Flex>
                ))}
            </Flex>
        </Box>
    );
}
