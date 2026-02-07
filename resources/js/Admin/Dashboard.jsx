import { Head, Link } from '@inertiajs/react';
import { Box, Heading, SimpleGrid, Text, VStack, HStack, Icon, Table, Badge } from '@chakra-ui/react';
import { LuShoppingCart, LuPackage, LuUsers, LuBanknote, LuClock, LuTrendingUp } from 'react-icons/lu';
import { AdminLayout } from './Layouts/AdminLayout';
import { LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer } from 'recharts';



export default function Dashboard({ stats = {}, salesChartData = [], recentOrders = [] }) {
    const statsCards = [
        {
            title: 'Всего заказов',
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
            value: `${stats.totalRevenue.toLocaleString('ru-RU')} ₽`,
            icon: LuBanknote,
            color: 'orange',
        },
        {
            title: 'Ожидающие',
            value: stats.pendingOrders,
            icon: LuClock,
            color: 'yellow',
        },
        {
            title: 'Средний чек',
            value: `${stats.avgOrderValue.toLocaleString('ru-RU')} ₽`,
            icon: LuTrendingUp,
            color: 'teal',
        },
    ];

    const statusColors = {
        pending: 'yellow',
        processing: 'blue',
        completed: 'green',
        cancelled: 'red',
        shipped: 'cyan',
        delivered: 'teal',
    };

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
                <SimpleGrid columns={{ base: 1, md: 2, lg: 3, xl: 6 }} gap={6}>
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
                                        <Heading size="xl" color="fg.default">
                                            {typeof stat.value === 'number' ? stat.value.toLocaleString('ru-RU') : stat.value}
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

                {/* Sales Chart */}
                <Box
                    bg="bg.panel"
                    borderRadius="lg"
                    borderWidth="1px"
                    borderColor="border.default"
                    boxShadow="sm"
                >
                    <Box p={6} borderBottomWidth="1px" borderColor="border.default">
                        <Heading size="md" mb={1}>Продажи за последние 30 дней</Heading>
                        <Text fontSize="sm" color="fg.muted">Количество заказов и выручка по дням</Text>
                    </Box>
                    <Box p={6}>
                        <ResponsiveContainer width="100%" height={300}>
                            <LineChart data={salesChartData}>
                                <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
                                <XAxis
                                    dataKey="date"
                                    style={{ fontSize: '12px' }}
                                />
                                <YAxis
                                    yAxisId="left"
                                    style={{ fontSize: '12px' }}
                                />
                                <YAxis
                                    yAxisId="right"
                                    orientation="right"
                                    style={{ fontSize: '12px' }}
                                />
                                <Tooltip
                                    contentStyle={{
                                        backgroundColor: 'white',
                                        border: '1px solid #e2e8f0',
                                        borderRadius: '8px'
                                    }}
                                />
                                <Legend />
                                <Line
                                    yAxisId="left"
                                    type="monotone"
                                    dataKey="orders"
                                    stroke="#3182ce"
                                    strokeWidth={2}
                                    name="Заказы"
                                    dot={{ fill: '#3182ce', r: 4 }}
                                />
                                <Line
                                    yAxisId="right"
                                    type="monotone"
                                    dataKey="revenue"
                                    stroke="#38a169"
                                    strokeWidth={2}
                                    name="Выручка (₽)"
                                    dot={{ fill: '#38a169', r: 4 }}
                                />
                            </LineChart>
                        </ResponsiveContainer>
                    </Box>
                </Box>

                {/* Recent Orders */}
                <Box
                    bg="bg.panel"
                    borderRadius="lg"
                    borderWidth="1px"
                    borderColor="border.default"
                    boxShadow="sm"
                >
                    <Box p={6} borderBottomWidth="1px" borderColor="border.default">
                        <Heading size="md" mb={1}>Последние заказы</Heading>
                        <Text fontSize="sm" color="fg.muted">10 последних оформленных заказов</Text>
                    </Box>
                    <Box>
                        <Table.Root variant="outline" size="sm">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeader>№ Заказа</Table.ColumnHeader>
                                    <Table.ColumnHeader>Клиент</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Сумма</Table.ColumnHeader>
                                    <Table.ColumnHeader>Статус</Table.ColumnHeader>
                                    <Table.ColumnHeader>Дата</Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {recentOrders.length > 0 ? (
                                    recentOrders.map((order) => (
                                        <Table.Row key={order.id}>
                                            <Table.Cell>
                                                <Link
                                                    href={route('admin.orders.edit', order.id)}
                                                    style={{
                                                        color: '#3182ce',
                                                        textDecoration: 'underline',
                                                        fontWeight: 'medium'
                                                    }}
                                                >
                                                    {order.order_number}
                                                </Link>
                                            </Table.Cell>
                                            <Table.Cell>{order.user_name}</Table.Cell>
                                            <Table.Cell textAlign="right">
                                                {order.total_amount.toLocaleString('ru-RU')} ₽
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Badge colorPalette={statusColors[order.status] || 'gray'}>
                                                    {order.status_label}
                                                </Badge>
                                            </Table.Cell>
                                            <Table.Cell color="fg.muted" fontSize="sm">
                                                {order.created_at}
                                            </Table.Cell>
                                        </Table.Row>
                                    ))
                                ) : (
                                    <Table.Row>
                                        <Table.Cell colSpan={5}>
                                            <Text textAlign="center" color="fg.muted" py={4}>
                                                Заказы отсутствуют
                                            </Text>
                                        </Table.Cell>
                                    </Table.Row>
                                )}
                            </Table.Body>
                        </Table.Root>
                    </Box>
                </Box>
            </VStack>
        </AdminLayout>
    );
}
