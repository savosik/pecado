import { Head } from '@inertiajs/react';
import { Box, Container, Heading, SimpleGrid, Card, Text, VStack, HStack } from '@chakra-ui/react';
import { LuShoppingCart, LuPackage, LuUsers, LuBanknote } from 'react-icons/lu';

export default function Dashboard({ stats }) {
    const statsCards = [
        {
            title: 'Заказы',
            value: stats.totalOrders,
            icon: LuShoppingCart,
            color: 'blue',
        },
        {
            title: 'Товары',
            value: stats.totalProducts,
            icon: LuPackage,
            color: 'green',
        },
        {
            title: 'Пользователи',
            value: stats.totalUsers,
            icon: LuUsers,
            color: 'purple',
        },
        {
            title: 'Выручка',
            value: stats.totalRevenue,
            icon: LuBanknote,
            color: 'orange',
        },
    ];

    return (
        <>
            <Head title="Панель управления" />

            <Box minH="100vh" bg="bg.subtle" py={8}>
                <Container maxW="7xl">
                    <VStack align="stretch" gap={8}>
                        {/* Header */}
                        <Box>
                            <Heading size="2xl" mb={2}>
                                Панель управления
                            </Heading>
                            <Text color="fg.muted">
                                Добро пожаловать в админ-панель Pecado
                            </Text>
                        </Box>

                        {/* Stats Grid */}
                        <SimpleGrid columns={{ base: 1, md: 2, lg: 4 }} gap={6}>
                            {statsCards.map((stat) => {
                                const Icon = stat.icon;
                                return (
                                    <Card.Root key={stat.title} variant="elevated">
                                        <Card.Body>
                                            <HStack justify="space-between">
                                                <VStack align="start" gap={1}>
                                                    <Text fontSize="sm" color="fg.muted" fontWeight="medium">
                                                        {stat.title}
                                                    </Text>
                                                    <Heading size="3xl" color="fg.default">
                                                        {stat.value.toLocaleString('ru-RU')}
                                                    </Heading>
                                                </VStack>
                                                <Box
                                                    p={3}
                                                    bg={`${stat.color}.subtle`}
                                                    borderRadius="lg"
                                                >
                                                    <Icon size={24} color={`${stat.color}.600`} />
                                                </Box>
                                            </HStack>
                                        </Card.Body>
                                    </Card.Root>
                                );
                            })}
                        </SimpleGrid>

                        {/* Placeholder for future content */}
                        <Card.Root variant="elevated">
                            <Card.Body>
                                <VStack align="start" gap={4}>
                                    <Heading size="lg">Фаза 1 завершена</Heading>
                                    <Text color="fg.muted">
                                        Базовая инфраструктура админ-панели настроена. Следующий шаг — создание Layout и компонентов навигации (Фаза 2).
                                    </Text>
                                </VStack>
                            </Card.Body>
                        </Card.Root>
                    </VStack>
                </Container>
            </Box>
        </>
    );
}
