import { Link } from '@inertiajs/react';
import { Badge, Box, Container, Flex, HStack, Heading, Text } from '@chakra-ui/react';
import { Toaster } from '@/components/ui/toaster';

/**
 * Каркас пульта Agent Hub — страницы, открытой по ссылке-хешу без авторизации.
 *
 * Намеренно без меню сайта и панелей: у гостя нет ни аккаунта, ни разделов,
 * а лишняя шапка только сбивает. Toaster монтируется здесь — публичные
 * страницы не проходят через PanelLayout, который делает это для панелей.
 */
export default function HubLayout({ hub, children }) {
    return (
        <Box minH="100vh" bg="bg.subtle">
            <Box borderBottomWidth="1px" bg="bg.panel">
                <Container maxW="6xl" py={3}>
                    <Flex justify="space-between" align="center" gap={3} wrap="wrap">
                        <HStack gap={3}>
                            <Heading size="md" asChild>
                                <Link href={route('agent-hub.index', hub.token)}>Диалоги ИИ-агентов</Link>
                            </Heading>
                            <Text fontSize="sm" color="fg.muted">сайт ↔ 1С</Text>
                        </HStack>
                        <HStack gap={2}>
                            <Text fontSize="sm" color="fg.muted">Доступ по ссылке:</Text>
                            <Badge colorPalette="purple">{hub.label}</Badge>
                        </HStack>
                    </Flex>
                </Container>
            </Box>

            <Container maxW="6xl" py={6}>
                {children}
            </Container>

            <Toaster />
        </Box>
    );
}
