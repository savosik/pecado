import { Box, HStack, Stack, Text } from '@chakra-ui/react';
import { usePage } from '@inertiajs/react';
import { FiClock } from 'react-icons/fi';

export default function UserStatusBanner() {
    const { auth } = usePage().props;

    if (auth?.user?.status !== 'processing') {
        return null;
    }

    return (
        <Box
            role="status"
            bg="orange.50"
            borderBottomWidth="1px"
            borderColor="orange.200"
            _dark={{ bg: 'orange.900', borderColor: 'orange.700' }}
            px={{ base: '3', md: '6' }}
            py="3"
        >
            <Box maxW="1360px" mx="auto">
                <HStack align="flex-start" gap="3">
                    <Box color="orange.500" fontSize="xl" lineHeight="1" mt="0.5">
                        <FiClock />
                    </Box>
                    <Stack gap="1">
                        <Text fontWeight="semibold" color="orange.900" _dark={{ color: 'orange.100' }}>
                            Мы уже готовим ваш аккаунт
                        </Text>
                        <Text fontSize="sm" color="orange.800" _dark={{ color: 'orange.200' }}>
                            Цены и остатки откроются, как только мы проверим аккаунт и установим для вас индивидуальные цены. А пока листайте каталог и собирайте интересные позиции.
                        </Text>
                    </Stack>
                </HStack>
            </Box>
        </Box>
    );
}
