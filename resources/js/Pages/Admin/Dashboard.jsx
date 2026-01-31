import { Head } from '@inertiajs/react';
import { Box, Heading, SimpleGrid, Text, VStack, HStack, Icon } from '@chakra-ui/react';
import { LuShoppingCart, LuPackage, LuUsers, LuBanknote } from 'react-icons/lu';
import { AdminLayout } from '../../Admin/Layouts/AdminLayout';

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
        <AdminLayout>
            <Head title="Панель управления" />

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
                        const StatIcon = stat.icon;
                        return (
                            <Box
                                key={stat.title}
                                bg="bg.panel"
                                borderRadius="lg"
                                borderWidth="1px"
                                borderColor="border.default"
                                p={6}
                                boxShadow="sm"
                                _hover={{ boxShadow: "md" }}
                                transition="box-shadow 0.2s"
                            >
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
                                        <Icon as={StatIcon} boxSize={6} />
                                    </Box>
                                </HStack>
                            </Box>
                        );
                    })}
                </SimpleGrid>

                {/* Placeholder for future content */}
                <Box
                    bg="bg.panel"
                    borderRadius="lg"
                    borderWidth="1px"
                    borderColor="border.default"
                    p={6}
                    boxShadow="sm"
                >
                    <VStack align="start" gap={4}>
                        <Heading size="lg">Фаза 2 завершена</Heading>
                        <Text color="fg.muted">
                            Базовый Layout с навигацией создан. Следующий шаг — создание переиспользуемых компонентов (Фаза 3).
                        </Text>
                    </VStack>
                </Box>
            </VStack>
        </AdminLayout>
    );
}
