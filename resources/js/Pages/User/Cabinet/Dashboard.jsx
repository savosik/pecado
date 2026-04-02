import {
    Box, Flex, Grid, GridItem, Text, Card, HStack, VStack,
    Badge,
} from '@chakra-ui/react';
import { Head, Link, usePage } from '@inertiajs/react';
import CabinetLayout from './CabinetLayout';
import { LuShoppingBag, LuHeart, LuShoppingCart, LuWallet } from 'react-icons/lu';

export default function Dashboard({ ordersCount = 0, favoritesCount = 0, cartsCount = 0, balance = null, recentOrders = [] }) {
    const { auth } = usePage().props;
    const user = auth?.user;
    const name = user?.name || user?.name || 'Пользователь';

    const initials = name
        .split(' ')
        .map(w => w[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);

    const stats = [
        { label: 'Заказы', value: ordersCount, icon: LuShoppingBag, href: '/cabinet/orders' },
        { label: 'Избранное', value: favoritesCount, icon: LuHeart, href: '/favorites' },
        { label: 'Корзины', value: cartsCount, icon: LuShoppingCart, href: '/cart' },
    ];

    const statusLabels = {
        pending: 'Ожидает',
        processing: 'В обработке',
        shipped: 'В пути',
        delivered: 'Доставлен',
        completed: 'Завершён',
        cancelled: 'Отменён',
    };

    const statusColors = {
        pending: 'yellow',
        processing: 'blue',
        shipped: 'purple',
        delivered: 'teal',
        completed: 'green',
        cancelled: 'red',
    };

    return (
        <CabinetLayout title="Дашборд">
            <Head title="Личный кабинет — Pecado" />

            {/* Welcome Card */}
            <Card.Root bg={{ base: 'white', _dark: 'gray.800' }} mb="6" borderRadius="xl" overflow="hidden" border="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }} _dark={{ bg: 'gray.800', borderColor: 'gray.700' }}>
                <Card.Body p="6">
                    <Flex align="center" gap="4">
                        <Flex
                            align="center"
                            justify="center"
                            w="14"
                            h="14"
                            borderRadius="full"
                            bg="pecado.50"
                            color="pecado.600"
                            _dark={{ bg: 'pecado.900/20', color: 'pecado.300' }}
                            fontSize="lg"
                            fontWeight="700"
                            flexShrink="0"
                        >
                            {initials}
                        </Flex>
                        <Box flex="1">
                            <Text fontSize="lg" fontWeight="700" mb="0.5">
                                Добро пожаловать, {(user?.name || 'Пользователь')}
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
                templateColumns={{ base: 'repeat(2, 1fr)', md: `repeat(${stats.length + (balance ? 1 : 0)}, 1fr)` }}
                gap="4"
                mb="6"
            >
                {stats.map((stat) => (
                    <GridItem key={stat.label}>
                        <Link href={stat.href}>
                            <Card.Root bg={{ base: 'white', _dark: 'gray.800' }}
                                borderRadius="xl"
                                border="1px solid"
                                borderColor={{ base: 'gray.100', _dark: 'gray.700' }}
                                _dark={{ bg: 'gray.800', borderColor: 'gray.700' }}
                                _hover={{ shadow: 'sm', borderColor: 'pecado.200', _dark: { borderColor: 'pecado.800' } }}
                                transition="all 0.2s"
                                cursor="pointer"
                                h="100%"
                            >
                                <Card.Body p="5">
                                    <HStack justify="space-between" mb="3">
                                        <Flex
                                            align="center"
                                            justify="center"
                                            w="10"
                                            h="10"
                                            borderRadius="xl"
                                            bg="pecado.50"
                                            color="pecado.500"
                                            _dark={{
                                                bg: 'pecado.900/20',
                                                color: 'pecado.300',
                                            }}
                                        >
                                            <stat.icon size={20} />
                                        </Flex>
                                    </HStack>
                                    <Text fontSize="2xl" fontWeight="800" lineHeight="1">
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

                {/* Balance Card */}
                {balance && (
                    <GridItem>
                        <Card.Root bg={{ base: 'white', _dark: 'gray.800' }}
                            borderRadius="xl"
                            border="1px solid"
                            borderColor={{ base: 'gray.100', _dark: 'gray.700' }}
                            _dark={{ bg: 'gray.800', borderColor: 'gray.700' }}
                            h="100%"
                        >
                            <Card.Body p="5">
                                <HStack justify="space-between" mb="3">
                                    <Flex
                                        align="center"
                                        justify="center"
                                        w="10"
                                        h="10"
                                        borderRadius="xl"
                                        bg="pecado.50"
                                        color="pecado.500"
                                        _dark={{
                                            bg: 'pecado.900/20',
                                            color: 'pecado.300',
                                        }}
                                    >
                                        <LuWallet size={20} />
                                    </Flex>
                                </HStack>
                                <Text
                                    fontSize="2xl"
                                    fontWeight="800"
                                    lineHeight="1"
                                    color={parseFloat(balance.current_balance) < 0 ? 'red.600' : 'green.600'}
                                    _dark={{ color: parseFloat(balance.current_balance) < 0 ? 'red.400' : 'green.400' }}
                                >
                                    {parseFloat(balance.current_balance).toLocaleString('ru-RU', { minimumFractionDigits: 2 })} ₽
                                </Text>
                                <Text fontSize="sm" color="gray.500" mt="1">
                                    {balance.contractors_count > 1
                                        ? `Баланс по ${balance.contractors_count} контрагентам`
                                        : 'Баланс'}
                                </Text>
                                {parseFloat(balance.overdue_debt) > 0 && (
                                    <Text fontSize="xs" color="red.500" mt="1">
                                        Просрочка: {parseFloat(balance.overdue_debt).toLocaleString('ru-RU', { minimumFractionDigits: 2 })} ₽
                                    </Text>
                                )}
                            </Card.Body>
                        </Card.Root>
                    </GridItem>
                )}
            </Grid>

            {/* Recent Orders */}
            <Card.Root bg={{ base: 'white', _dark: 'gray.800' }} borderRadius="xl" border="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }} _dark={{ bg: 'gray.800', borderColor: 'gray.700' }}>
                <Card.Header p="5" pb="3">
                    <Flex align="center" justify="space-between">
                        <Text fontSize="md" fontWeight="700">Последние заказы</Text>
                        {recentOrders.length > 0 && (
                            <Link href="/cabinet/orders">
                                <Text fontSize="xs" fontWeight="500" color="pecado.500" _hover={{ color: 'pecado.700' }} transition="colors 0.15s" cursor="pointer">
                                    Все заказы →
                                </Text>
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
                        <VStack gap="2" align="stretch">
                            {recentOrders.map((order) => (
                                <Link key={order.id} href={`/cabinet/orders/${order.id}`}>
                                    <Flex
                                        align="center"
                                        justify="space-between"
                                        p="3"
                                        borderRadius="lg"
                                        bg="gray.50"
                                        _dark={{ bg: 'gray.750' }}
                                        _hover={{ bg: 'pecado.50', _dark: { bg: 'gray.700' } }}
                                        transition="background 0.15s"
                                        cursor="pointer"
                                    >
                                        <VStack align="start" gap="0.5" flex="1" minW="0">
                                            <HStack gap="1.5">
                                                <Text fontSize="sm" fontWeight="700" color="gray.800" _dark={{ color: 'gray.100' }}>
                                                    №{order.order_number || order.id}
                                                </Text>
                                                <Badge
                                                    colorPalette={order.type === 'preorder' ? 'orange' : 'gray'}
                                                    variant="subtle"
                                                    borderRadius="full"
                                                    px="2"
                                                    fontSize="2xs"
                                                >
                                                    {order.type === 'preorder' ? 'Предзаказ' : 'Заказ'}
                                                </Badge>
                                            </HStack>
                                            <Text fontSize="xs" color="gray.400">
                                                {order.created_at}
                                                {order.items_count > 0 && ` · ${order.items_count} ${order.items_count === 1 ? 'позиция' : order.items_count < 5 ? 'позиции' : 'позиций'}`}
                                            </Text>
                                        </VStack>
                                        <Badge
                                            colorPalette={statusColors[order.status] || 'gray'}
                                            variant="subtle"
                                            borderRadius="full"
                                            px="2.5"
                                            fontSize="xs"
                                            flexShrink="0"
                                            mx="3"
                                        >
                                            {statusLabels[order.status] || order.status}
                                        </Badge>
                                        <Text fontSize="sm" fontWeight="700" flexShrink="0" color="gray.800" _dark={{ color: 'gray.100' }}>
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
