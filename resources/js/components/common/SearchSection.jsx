import { Box, Flex, Text } from '@chakra-ui/react';

/**
 * SearchSection — presentation-компонент секции результатов поиска.
 *
 * @param {{ title: string, action?: React.ReactNode, children: React.ReactNode }} props
 */
export default function SearchSection({ title, action, children }) {
    return (
        <Box py="2">
            <Flex align="center" justify="space-between" px="3" mb="1">
                <Text
                    fontSize="2xs"
                    fontWeight="600"
                    textTransform="uppercase"
                    letterSpacing="0.05em"
                    color="gray.400"
                >
                    {title}
                </Text>
                {action}
            </Flex>
            {children}
        </Box>
    );
}
