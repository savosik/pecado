import { Box, Flex, Heading, Text } from '@chakra-ui/react';
import { pluralGoods } from '@/utils/plural';

/**
 * CatalogHeader — заголовок страницы каталога.
 *
 * @param {{ h1: string, total: number|null, description?: string }} props
 */
export default function CatalogHeader({ h1, total, description }) {
    return (
        <Box mb="4">
            <Flex align="baseline" gap="3" flexWrap="wrap">
                <Heading as="h1" size="2xl" fontWeight="900">
                    {h1}
                </Heading>
                {total != null && (
                    <Text fontSize="sm" color="gray.400" fontWeight="normal">
                        {total} {pluralGoods(total)}
                    </Text>
                )}
            </Flex>
            {description && (
                <Text fontSize="sm" color="gray.500" mt="1">
                    {description}
                </Text>
            )}
        </Box>
    );
}
