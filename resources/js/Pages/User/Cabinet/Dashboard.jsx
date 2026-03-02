import {
    Box, Flex, Grid, GridItem, Text, Card, HStack, VStack,
    Badge,
} from '@chakra-ui/react';
import { Head, Link, usePage } from '@inertiajs/react';
import CabinetLayout from './CabinetLayout';
import { LuShoppingBag, LuHeart, LuShoppingCart } from 'react-icons/lu';

export default function Dashboard({ ordersCount = 0, favoritesCount = 0, cartsCount = 0, recentOrders = [] }) {
    const { auth } = usePage().props;
    const user = auth?.user;
    const name = user?.full_name || user?.name || 'Пользователь';

    const initials = name
        .split(' ')
        .map(w => w[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);

    const stats = [
        { label: 'Заказы', value: ordersCount, icon: LuShoppingBag, color: 'blue', href: '/cabinet/orders' },
        { label: 'Избранное', value: favoritesCount, icon: LuHeart, color: 'pink', href: '/favorites' },
        { label: 'Корзины', value: cartsCount, icon: LuShoppingCart, color: 'green', href: '/cart' },
    ];

    const statusColors = {
        pending: 'orange',
        processing: 'blue',
        shipped: 'blue',
        delivered: 'green',
        completed: 'green',
        cancelled: 'red',
    };

    const statusLabels = {
        pending: 'Ожидает',
        processing: 'В обработке',
        shipped: 'В пути',
        delivered: 'Доставлен',
        completed: 'Завершён',
        cancelled: 'Отменён',
    };

    return (
        <CabinetLayout title="Дашборд">
            <Head title="Личный кабинет — Pecado" />

            {/* Welcome Card */}
            <Card.Root mb="6" borderRadius="xl" overflow="hidden" border="1px solid" borderColor="gray.100" _dark={{ bg: 'gray.800', borderColor: 'gray.700' }}>
                <Card.Body p="6">
                    <Flex align="center" gap="4">
                        <Flex
                            align="center"
                            justify="center"
                            w="16"
                            h="16"
                            borderRadius="full"
                            bgGradient="to-br"
                            gradientFrom="pink.400"
                            gradientTo="purple.500"
                            color="white"
                            fontSize="xl"
                            fontWeight="800"
                            flexShrink="0"
                        >
                            {initials}
                        </Flex>
                        <Box flex="1">
                            <Text fontSize="xl" fontWeight="800" mb="0.5">
                                Добро пожаловать, {(user?.name || 'Пользователь')}! 👋
                            </Text>
                            <Text fontSize="sm" color="gray.500">
                                Здесь вы можете управлять заказами, избранным и настройками профиля.
                            </Text>
                        </Box>
                    </Flex>
                </Card.Body>
            </Card.Root>

            {/* Stats Grid */}
            <Grid
                templateColumns={{ base: 'repeat(2, 1fr)', md: `repeat(${stats.length}, 1fr)` }}
                gap="4"
                mb="6"
            >
                {stats.map((stat) => (
                    <GridItem key={stat.label}>
                        <Link href={stat.href}>
                            <Card.Root
                                borderRadius="xl"
                                border="1px solid"
                                borderColor="gray.100"
                                _dark={{ bg: 'gray.800', borderColor: 'gray.700' }}
                                _hover={{ shadow: 'md', transform: 'translateY(-1px)' }}
                                transition="all 0.2s"
                                cursor="pointer"
                            >
                                <Card.Body p="5">
                                    <HStack justify="space-between" mb="3">
                                        <Flex
                                            align="center"
                                            justify="center"
                                            w="10"
                                            h="10"
                                            borderRadius="xl"
                                            bg={`${stat.color}.50`}
                                            color={`${stat.color}.500`}
                                            _dark={{
                                                bg: `${stat.color}.900/20`,
                                                color: `${stat.color}.300`,
                                            }}
                                        >
                                            <stat.icon size={20} />
                                        </Flex>
                                    </HStack>
                                    <Text fontSize="2xl" fontWeight="900" lineHeight="1">
                                        {stat.value}
                                    </Text>
                                    <Text fontSize="sm" color="gray.500" mt="1">
                                        {stat.label}
                                    </Text>
                                </Card.Body>
                            </Card.Root>
                        </Link>
                    </GridItem>
                ))}
            </Grid>

            {/* Recent Orders */}
            <Card.Root borderRadius="xl" border="1px solid" borderColor="gray.100" _dark={{ bg: 'gray.800', borderColor: 'gray.700' }}>
                <Card.Header p="5" pb="3">
                    <Flex align="center" justify="space-between">
                        <Text fontSize="md" fontWeight="700">Последние заказы</Text>
                        {recentOrders.length > 0 && (
                            <Link href="/cabinet/orders">
                                <Badge colorPalette="pink" variant="subtle" borderRadius="full" px="2.5" py="0.5" fontSize="xs" cursor="pointer">
                                    Все заказы →
                                </Badge>
                            </Link>
                        )}
                    </Flex>
                </Card.Header>
                <Card.Body p="5" pt="0">
                    {recentOrders.length === 0 ? (
                        <Text fontSize="sm" color="gray.400" py="4" textAlign="center">
                            Заказов пока нет
                        </Text>
                    ) : (
                        <VStack gap="3" align="stretch">
                            {recentOrders.map((order) => (
                                <Link key={order.id} href={`/orders/${order.id}`}>
                                    <Flex
                                        align="center"
                                        justify="space-between"
                                        p="3"
                                        borderRadius="lg"
                                        bg="gray.50"
                                        _dark={{ bg: 'gray.800' }}
                                        _hover={{ bg: 'gray.100', _dark: { bg: 'gray.700' } }}
                                        transition="background 0.15s"
                                        cursor="pointer"
                                    >
                                        <VStack align="start" gap="0">
                                            <Text fontSize="sm" fontWeight="600">#{order.order_number}</Text>
                                            <Text fontSize="xs" color="gray.400">{order.created_at}</Text>
                                        </VStack>
                                        <Badge
                                            colorPalette={statusColors[order.status] || 'gray'}
                                            variant="subtle"
                                            borderRadius="full"
                                            px="2.5"
                                            fontSize="xs"
                                        >
                                            {statusLabels[order.status] || order.status}
                                        </Badge>
                                        <Text fontSize="sm" fontWeight="700">
                                            {Number(order.total || 0).toLocaleString('ru-RU')} ₽
                                        </Text>
                                    </Flex>
                                </Link>
                            ))}
                        </VStack>
                    )}
                </Card.Body>
            </Card.Root>
        </CabinetLayout>
    );
}
