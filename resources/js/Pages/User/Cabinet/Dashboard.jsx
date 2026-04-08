import {
    Box, Flex, Grid, GridItem, Text, Card, HStack, VStack,
    Badge,
} from '@chakra-ui/react';
import { Head, Link, usePage } from '@inertiajs/react';
import CabinetLayout from './CabinetLayout';
import { LuShoppingBag, LuHeart, LuShoppingCart, LuWallet, LuClipboardList, LuAward } from 'react-icons/lu';
import { Tooltip } from '@/components/ui/tooltip';

export default function Dashboard({ ordersCount = 0, favoritesCount = 0, cartsCount = 0, balance = null, recentOrders = [], questionnaireCompleted = true, clientStatus = null }) {
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
                        <Tooltip content={clientStatus ? clientStatus.name : 'Статус не назначен'} positioning={{ placement: 'top' }}>
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
                                border="3px solid"
                                borderColor={clientStatus?.color || 'transparent'}
                                boxShadow={clientStatus?.color ? `0 0 0 1px ${clientStatus.color}20, 0 0 12px ${clientStatus.color}40` : 'none'}
                                transition="all 0.3s"
                                cursor="default"
                            >
                                {initials}
                            </Flex>
                        </Tooltip>
                        <Box flex="1">
                            <Flex align="center" gap="2" mb="0.5">
                                <Text fontSize="lg" fontWeight="700">
                                    Добро пожаловать, {(user?.name || 'Пользователь')}
                                </Text>
                                {clientStatus && (
                                    <Badge
                                        px="2.5"
                                        py="0.5"
                                        borderRadius="full"
                                        fontSize="xs"
                                        fontWeight="600"
                                        bg={clientStatus.color || 'gray.200'}
                                        color="white"
                                        textShadow="0 1px 2px rgba(0,0,0,0.2)"
                                    >
                                        {clientStatus.name}
                                    </Badge>
                                )}
                            </Flex>
                            <Text fontSize="sm" color="gray.500">
                                Здесь вы можете управлять заказами, избранным и настройками профиля.
                            </Text>
                        </Box>
                    </Flex>
                </Card.Body>
            </Card.Root>

            {/* Onboarding Reminder */}
            {!questionnaireCompleted && (
                <Card.Root
                    mb="6"
                    borderRadius="xl"
                    overflow="hidden"
                    border="1px solid"
                    borderColor="orange.200"
                    bg="orange.50"
                    _dark={{ bg: 'orange.900/10', borderColor: 'orange.800' }}
                >
                    <Card.Body p="5">
                        <Flex align="center" gap="4">
                            <Flex
                                align="center"
                                justify="center"
                                w="10"
                                h="10"
                                borderRadius="xl"
                                bg="orange.100"
                                color="orange.600"
                                _dark={{ bg: 'orange.900/20', color: 'orange.300' }}
                                flexShrink="0"
                            >
                                <LuClipboardList size={20} />
                            </Flex>
                            <Box flex="1">
                                <Text fontSize="sm" fontWeight="600" color="orange.800" _dark={{ color: 'orange.200' }}>
                                    Заполните анкету
                                </Text>
                                <Text fontSize="xs" color="orange.600" _dark={{ color: 'orange.400' }}>
                                    Расскажите о вашем бизнесе — это поможет нам подобрать лучшие условия сотрудничества.
                                </Text>
                            </Box>
                            <Link href="/onboarding">
                                <Box
                                    as="span"
                                    px="4"
                                    py="2"
                                    borderRadius="lg"
                                    bg="orange.500"
                                    color="white"
                                    fontSize="sm"
                                    fontWeight="600"
                                    _hover={{ bg: 'orange.600' }}
                                    transition="background 0.15s"
                                    whiteSpace="nowrap"
                                    cursor="pointer"
                                >
                                    Заполнить
                                </Box>
                            </Link>
                        </Flex>
                    </Card.Body>
                </Card.Root>
            )}

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

                {/* Client Status Card */}
                {clientStatus && (
                    <GridItem>
                        <Card.Root
                            borderRadius="xl"
                            border="2px solid"
                            borderColor={clientStatus.color || 'gray.200'}
                            bg={{ base: 'white', _dark: 'gray.800' }}
                            _dark={{ bg: 'gray.800' }}
                            h="100%"
                            overflow="hidden"
                            position="relative"
                        >
                            <Box
                                position="absolute"
                                top="0"
                                left="0"
                                right="0"
                                h="3px"
                                bg={clientStatus.color || 'gray.300'}
                            />
                            <Card.Body p="5">
                                <HStack justify="space-between" mb="3">
                                    <Flex
                                        align="center"
                                        justify="center"
                                        w="10"
                                        h="10"
                                        borderRadius="xl"
                                        bg={`${clientStatus.color}15` || 'gray.50'}
                                        color={clientStatus.color || 'gray.500'}
                                    >
                                        <LuAward size={20} />
                                    </Flex>
                                </HStack>
                                <Text
                                    fontSize="lg"
                                    fontWeight="800"
                                    lineHeight="1.2"
                                    color={clientStatus.color || 'gray.800'}
                                    _dark={{ color: clientStatus.color || 'gray.200' }}
                                >
                                    {clientStatus.name}
                                </Text>
                                <Text fontSize="sm" color="gray.500" mt="1">
                                    Ваш статус
                                </Text>
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
