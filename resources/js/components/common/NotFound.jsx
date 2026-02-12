import { Box, Heading, Text, Button, VStack, Icon } from '@chakra-ui/react';
import { Head, Link } from '@inertiajs/react';
import UserLayout from '@/Pages/User/UserLayout';
import { LuSearchX } from 'react-icons/lu';

/**
 * Страница 404 — «Не найдено».
 */
export default function NotFound() {
    return (
        <UserLayout>
            <Head title="Страница не найдена" />
            <VStack
                justify="center"
                align="center"
                minH="60vh"
                gap="6"
                textAlign="center"
                px="4"
            >
                <Icon as={LuSearchX} boxSize="16" color="fg.muted" />
                <Box>
                    <Heading as="h1" size="4xl" fontWeight="bold" color="fg">
                        404
                    </Heading>
                    <Text mt="2" fontSize="lg" color="fg.muted">
                        Страница не найдена
                    </Text>
                    <Text mt="1" fontSize="sm" color="fg.subtle">
                        Страница, которую вы ищете, не существует или была перемещена.
                    </Text>
                </Box>
                <Button asChild colorPalette="pecado" size="lg">
                    <Link href="/">Вернуться на главную</Link>
                </Button>
            </VStack>
        </UserLayout>
    );
}
