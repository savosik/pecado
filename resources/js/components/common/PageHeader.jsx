import { Box, Flex, Heading, Text } from '@chakra-ui/react';

/**
 * Заголовок страницы с подзаголовком и кнопками действий.
 *
 * @param {{ title: string, subtitle?: string, actions?: React.ReactNode }} props
 */
export default function PageHeader({ title, subtitle, actions }) {
    return (
        <Flex
            direction={{ base: 'column', md: 'row' }}
            justify="space-between"
            align={{ base: 'flex-start', md: 'center' }}
            gap="3"
            mb="6"
        >
            <Box minW="0" maxW="100%">
                <Heading as="h1" size={{ base: 'xl', md: '3xl' }} fontWeight="bold" color="fg" css={{ wordBreak: 'break-word' }}>
                    {title}
                </Heading>
                {subtitle && (
                    <Text mt="1" color="fg.muted" fontSize="md">
                        {subtitle}
                    </Text>
                )}
            </Box>
            {actions && (
                <Flex gap="2" flexShrink={0}>
                    {actions}
                </Flex>
            )}
        </Flex>
    );
}
