import {
    Box, Flex, Grid, GridItem, Text, Card, HStack, VStack,
    Badge,
} from '@chakra-ui/react';
import { Head, usePage } from '@inertiajs/react';
import CabinetLayout from './CabinetLayout';
import { LuShoppingBag, LuHeart, LuShoppingCart, LuMessageCircle } from 'react-icons/lu';

const stats = [
    { label: 'Заказы', value: 12, icon: LuShoppingBag, color: 'blue' },
    { label: 'Избранное', value: 24, icon: LuHeart, color: 'pink' },
    { label: 'Корзины', value: 3, icon: LuShoppingCart, color: 'green' },
    { label: 'Вопросы', value: 5, icon: LuMessageCircle, color: 'purple' },
];

export default function Dashboard() {
    const { auth } = usePage().props;
    const user = auth?.user;
    const name = user?.name || 'Пользователь';

    const initials = name
        .split(' ')
        .map(w => w[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);

    return (
        <CabinetLayout title="Дашборд">
            <Head title="Личный кабинет — Pecado" />

            {/* Welcome Card */}
            <Card.Root mb="6" borderRadius="xl" overflow="hidden" border="1px solid" borderColor="gray.100" _dark={{ bg: 'gray.800', borderColor: 'gray.700' }}>
                <Card.Body p="6">
                    <Flex align="center" gap="4">
                        {/* Avatar */}
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
                                Добро пожаловать, {name.split(' ')[0]}! 👋
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
                templateColumns={{ base: 'repeat(2, 1fr)', md: 'repeat(4, 1fr)' }}
                gap="4"
                mb="6"
            >
                {stats.map((stat) => (
                    <GridItem key={stat.label}>
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
                    </GridItem>
                ))}
            </Grid>

            {/* Recent Orders placeholder */}
            <Card.Root borderRadius="xl" border="1px solid" borderColor="gray.100" _dark={{ bg: 'gray.800', borderColor: 'gray.700' }}>
                <Card.Header p="5" pb="3">
                    <Flex align="center" justify="space-between">
                        <Text fontSize="md" fontWeight="700">Последние заказы</Text>
                        <Badge colorPalette="pink" variant="subtle" borderRadius="full" px="2.5" py="0.5" fontSize="xs">
                            Все заказы →
                        </Badge>
                    </Flex>
                </Card.Header>
                <Card.Body p="5" pt="0">
                    <VStack gap="3" align="stretch">
                        {[
                            { id: '#10234', date: '10 фев 2026', status: 'Доставлен', statusColor: 'green', total: '5 490 ₽' },
                            { id: '#10189', date: '03 фев 2026', status: 'В пути', statusColor: 'blue', total: '2 990 ₽' },
                            { id: '#10112', date: '28 янв 2026', status: 'Обработка', statusColor: 'orange', total: '8 790 ₽' },
                        ].map((order) => (
                            <Flex
                                key={order.id}
                                align="center"
                                justify="space-between"
                                p="3"
                                borderRadius="lg"
                                bg="gray.50"
                                _dark={{ bg: 'gray.800' }}
                            >
                                <VStack align="start" gap="0">
                                    <Text fontSize="sm" fontWeight="600">{order.id}</Text>
                                    <Text fontSize="xs" color="gray.400">{order.date}</Text>
                                </VStack>
                                <Badge colorPalette={order.statusColor} variant="subtle" borderRadius="full" px="2.5" fontSize="xs">
                                    {order.status}
                                </Badge>
                                <Text fontSize="sm" fontWeight="700">{order.total}</Text>
                            </Flex>
                        ))}
                    </VStack>
                </Card.Body>
            </Card.Root>
        </CabinetLayout>
    );
}
