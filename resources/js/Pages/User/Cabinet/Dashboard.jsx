import {
    Box, Flex, Grid, GridItem, Text, Card, HStack, VStack,
    Badge, Image,
} from '@chakra-ui/react';
import { Head, Link, usePage } from '@inertiajs/react';
import CabinetLayout from './CabinetLayout';
import { LuShoppingBag, LuHeart, LuShoppingCart, LuWallet, LuClipboardList, LuPhone, LuMail, LuUserRound } from 'react-icons/lu';
import { Tooltip } from '@/components/ui/tooltip';
import PwaInstallBanner from '@/components/PwaInstallBanner';
import { getOrderTypeShortLabel, getOrderTypeColor } from '@/constants/orderType';

export default function Dashboard({ ordersCount = 0, favoritesCount = 0, cartsCount = 0, balance = null, recentOrders = [], questionnaireCompleted = true, clientStatus = null, personalManager = null }) {
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
        pending_approval: 'Ожидается согласование',
        pending_payment_before_provision: 'Ожидается оплата до обеспечения',
        ready_for_provision: 'Готов к обеспечению',
        pending_payment_before_shipment: 'Ожидается оплата до отгрузки',
        awaiting_provision: 'Ожидается обеспечение',
        ready_for_shipment: 'Готов к отгрузке',
        shipping: 'В процессе отгрузки',
        awaiting_payment: 'Ожидается оплата',
        ready_for_closure: 'Готов к закрытию',
        closed: 'Закрыт',
    };

    const statusColors = {
        pending_approval: 'yellow',
        pending_payment_before_provision: 'orange',
        ready_for_provision: 'cyan',
        pending_payment_before_shipment: 'orange',
        awaiting_provision: 'blue',
        ready_for_shipment: 'purple',
        shipping: 'teal',
        awaiting_payment: 'orange',
        ready_for_closure: 'green',
        closed: 'gray',
    };

    return (
        <CabinetLayout title="Дашборд">
            <Head title="Личный кабинет — Pecado" />

            {/* Welcome Card */}
            <Card.Root bg="bg" mb="6" borderRadius="xl" overflow="hidden" border="1px solid" borderColor="border.muted">
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

            <PwaInstallBanner />

            {/* Personal Manager Card */}
            {personalManager && (
                <Card.Root
                    mb="6"
                    borderRadius="xl"
                    overflow="hidden"
                    border="1px solid"
                    borderColor="border.muted"
                    bg="bg"
                >
                    <Card.Body p={{ base: '4', md: '5' }}>
                        <Text fontSize="xs" fontWeight="600" color="gray.500" textTransform="uppercase" letterSpacing="0.04em" mb="3">
                            Ваш персональный менеджер
                        </Text>
                        <Flex
                            align={{ base: 'flex-start', md: 'center' }}
                            gap={{ base: '3', md: '4' }}
                            direction={{ base: 'column', sm: 'row' }}
                        >
                            {personalManager.photo_url ? (
                                <Image
                                    src={personalManager.photo_url}
                                    alt={personalManager.name}
                                    w="14"
                                    h="14"
                                    borderRadius="full"
                                    objectFit="cover"
                                    flexShrink="0"
                                    border="2px solid"
                                    borderColor="pecado.100"
                                    _dark={{ borderColor: 'pecado.900/40' }}
                                />
                            ) : (
                                <Flex
                                    align="center"
                                    justify="center"
                                    w="14"
                                    h="14"
                                    borderRadius="full"
                                    bg="pecado.50"
                                    color="pecado.500"
                                    _dark={{ bg: 'pecado.900/20', color: 'pecado.300' }}
                                    flexShrink="0"
                                >
                                    <LuUserRound size={26} />
                                </Flex>
                            )}
                            <Box flex="1" minW="0">
                                <Text fontSize="md" fontWeight="700" color="gray.900" _dark={{ color: 'gray.50' }} mb="1.5">
                                    {personalManager.name}
                                </Text>
                                <HStack gap={{ base: '3', md: '5' }} flexWrap="wrap">
                                    {personalManager.phone && (
                                        <HStack
                                            as="a"
                                            href={`tel:${personalManager.phone}`}
                                            gap="1.5"
                                            color="gray.700"
                                            _dark={{ color: 'gray.200' }}
                                            _hover={{ color: 'pecado.600', _dark: { color: 'pecado.300' } }}
                                            transition="color 0.15s"
                                        >
                                            <LuPhone size={14} />
                                            <Text fontSize="sm" fontWeight="500">{personalManager.phone}</Text>
                                        </HStack>
                                    )}
                                    {personalManager.email && (
                                        <HStack
                                            as="a"
                                            href={`mailto:${personalManager.email}`}
                                            gap="1.5"
                                            color="gray.700"
                                            _dark={{ color: 'gray.200' }}
                                            _hover={{ color: 'pecado.600', _dark: { color: 'pecado.300' } }}
                                            transition="color 0.15s"
                                        >
                                            <LuMail size={14} />
                                            <Text fontSize="sm" fontWeight="500" wordBreak="break-all">{personalManager.email}</Text>
                                        </HStack>
                                    )}
                                </HStack>
                            </Box>
                        </Flex>
                    </Card.Body>
                </Card.Root>
            )}

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
                templateColumns={{ base: '1fr', md: `repeat(${stats.length + (balance ? 1 : 0)}, 1fr)` }}
                gap={{ base: '3', md: '4' }}
                mb="6"
            >
                {stats.map((stat) => (
                    <GridItem key={stat.label}>
                        <Link href={stat.href}>
                            <Card.Root bg="bg"
                                borderRadius="xl"
                                border="1px solid"
                                borderColor="border.muted"

                                _hover={{ shadow: 'sm', borderColor: 'pecado.200', _dark: { borderColor: 'pecado.800' } }}
                                transition="all 0.2s"
                                cursor="pointer"
                                h="100%"
                            >
                                <Card.Body p={{ base: '4', md: '5' }}>
                                    <Flex direction="column" gap="3" h="100%">
                                        <Flex align="center" justify="space-between" gap="3">
                                            <Text fontSize="sm" fontWeight="500" color="gray.500" lineHeight="1.2">
                                                {stat.label}
                                            </Text>
                                            <Flex
                                                align="center"
                                                justify="center"
                                                w="9"
                                                h="9"
                                                borderRadius="lg"
                                                bg="pecado.50"
                                                color="pecado.500"
                                                _dark={{
                                                    bg: 'pecado.900/20',
                                                    color: 'pecado.300',
                                                }}
                                                flexShrink="0"
                                            >
                                                <stat.icon size={18} />
                                            </Flex>
                                        </Flex>
                                        <Text
                                            fontSize={{ base: '2xl', md: '3xl' }}
                                            fontWeight="800"
                                            lineHeight="1"
                                            color="gray.900"
                                            _dark={{ color: 'gray.50' }}
                                            mt="auto"
                                        >
                                            {stat.value}
                                        </Text>
                                    </Flex>
                                </Card.Body>
                            </Card.Root>
                        </Link>
                    </GridItem>
                ))}

                {/* Balance Card */}
                {balance && (
                    <GridItem>
                        <Card.Root bg="bg"
                            borderRadius="xl"
                            border="1px solid"
                            borderColor="border.muted"

                            h="100%"
                        >
                            <Card.Body p={{ base: '4', md: '5' }}>
                                <Flex direction="column" gap="3" h="100%">
                                    <Flex align="center" justify="space-between" gap="3">
                                        <Text fontSize="sm" fontWeight="500" color="gray.500" lineHeight="1.2" truncate>
                                            {balance.contractors_count > 1
                                                ? `Баланс по ${balance.contractors_count} контрагентам`
                                                : 'Баланс'}
                                        </Text>
                                        <Flex
                                            align="center"
                                            justify="center"
                                            w="9"
                                            h="9"
                                            borderRadius="lg"
                                            bg="pecado.50"
                                            color="pecado.500"
                                            _dark={{
                                                bg: 'pecado.900/20',
                                                color: 'pecado.300',
                                            }}
                                            flexShrink="0"
                                        >
                                            <LuWallet size={18} />
                                        </Flex>
                                    </Flex>
                                    <Box minW="0" mt="auto">
                                        <Text
                                            fontSize={{ base: '2xl', md: '3xl' }}
                                            fontWeight="800"
                                            lineHeight="1"
                                            whiteSpace="nowrap"
                                            color={parseFloat(balance.current_balance) < 0 ? 'red.600' : 'green.600'}
                                            _dark={{ color: parseFloat(balance.current_balance) < 0 ? 'red.400' : 'green.400' }}
                                        >
                                            {parseFloat(balance.current_balance).toLocaleString('ru-RU', { minimumFractionDigits: 2 })}&nbsp;₽
                                        </Text>
                                        {parseFloat(balance.overdue_debt) > 0 && (
                                            <Text fontSize="xs" color="red.500" mt="1.5" whiteSpace="nowrap">
                                                Просрочка: {parseFloat(balance.overdue_debt).toLocaleString('ru-RU', { minimumFractionDigits: 2 })}&nbsp;₽
                                            </Text>
                                        )}
                                    </Box>
                                </Flex>
                            </Card.Body>
                        </Card.Root>
                    </GridItem>
                )}

            </Grid>

            {/*
                Разрез задолженности по нашим юрлицам. Показываем только когда 1С
                прислала детализацию: без неё блока нет вовсе. Суммы разных организаций
                намеренно НЕ складываем — переплата одному юрлицу не гасит долг другому.
            */}
            {balance?.organizations?.length > 0 && (
                <Card.Root bg="bg" borderRadius="xl" border="1px solid" borderColor="border.muted" mb="6">
                    <Card.Header p="5" pb="3">
                        <Text fontWeight="700" fontSize="md">Задолженность по организациям</Text>
                        <Text fontSize="xs" color="fg.muted" mt="1">
                            Оплачивайте каждой организации по её реквизитам — переплата одной
                            не погашает задолженность перед другой.
                        </Text>
                    </Card.Header>
                    <Card.Body p="5" pt="0">
                        <VStack align="stretch" gap="4">
                            {balance.organizations.map((row, index) => (
                                <Box
                                    key={index}
                                    p="4"
                                    borderRadius="lg"
                                    border="1px solid"
                                    borderColor="border.muted"
                                >
                                    <Flex justify="space-between" align="start" gap="4" wrap="wrap">
                                        <Box minW="0">
                                            <Text fontSize="sm" fontWeight="600">{row.organization_name}</Text>
                                            {row.contractor_name && (
                                                <Text fontSize="xs" color="fg.muted">
                                                    По контрагенту: {row.contractor_name}
                                                </Text>
                                            )}
                                        </Box>
                                        <Box textAlign="right">
                                            <Text
                                                fontSize="lg"
                                                fontWeight="700"
                                                whiteSpace="nowrap"
                                                color={parseFloat(row.current_balance) < 0 ? 'red.600' : 'green.600'}
                                                _dark={{ color: parseFloat(row.current_balance) < 0 ? 'red.400' : 'green.400' }}
                                            >
                                                {parseFloat(row.current_balance).toLocaleString('ru-RU', { minimumFractionDigits: 2 })}&nbsp;₽
                                            </Text>
                                            {parseFloat(row.overdue_debt) > 0 && (
                                                <Text fontSize="xs" color="red.500" whiteSpace="nowrap">
                                                    Просрочено: {parseFloat(row.overdue_debt).toLocaleString('ru-RU', { minimumFractionDigits: 2 })}&nbsp;₽
                                                </Text>
                                            )}
                                        </Box>
                                    </Flex>

                                    {Object.keys(row.requisites || {}).length > 0 && (
                                        <Box mt="3" pt="3" borderTopWidth="1px" borderColor="border.muted">
                                            <Text fontSize="xs" color="fg.muted" mb="1">Реквизиты для оплаты</Text>
                                            <VStack align="stretch" gap="0.5">
                                                {row.requisites.legal_name && (
                                                    <Text fontSize="xs">{row.requisites.legal_name}</Text>
                                                )}
                                                {row.requisites.tax_id && (
                                                    <Text fontSize="xs" color="fg.muted">
                                                        ИНН: {row.requisites.tax_id}
                                                        {row.requisites.tax_code ? ` · КПП: ${row.requisites.tax_code}` : ''}
                                                    </Text>
                                                )}
                                                {row.requisites.bank_name && (
                                                    <Text fontSize="xs" color="fg.muted">
                                                        {row.requisites.bank_name}
                                                        {row.requisites.bank_bik ? ` · БИК ${row.requisites.bank_bik}` : ''}
                                                    </Text>
                                                )}
                                                {row.requisites.account_number && (
                                                    <Text fontSize="xs" color="fg.muted">
                                                        Р/с: {row.requisites.account_number}
                                                    </Text>
                                                )}
                                                {row.requisites.correspondent_account && (
                                                    <Text fontSize="xs" color="fg.muted">
                                                        К/с: {row.requisites.correspondent_account}
                                                    </Text>
                                                )}
                                            </VStack>
                                        </Box>
                                    )}
                                </Box>
                            ))}
                        </VStack>
                    </Card.Body>
                </Card.Root>
            )}

            {/* Recent Orders */}
            <Card.Root bg="bg" borderRadius="xl" border="1px solid" borderColor="border.muted">
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
                                    <Box
                                        p="3"
                                        borderRadius="lg"
                                        bg="gray.50"
                                        _dark={{ bg: 'gray.700' }}
                                        _hover={{ bg: 'pecado.50', _dark: { bg: 'gray.600' } }}
                                        transition="background 0.15s"
                                        cursor="pointer"
                                    >
                                        {/* Mobile: две строки */}
                                        <Box display={{ base: 'block', md: 'none' }}>
                                            <Flex align="center" justify="space-between" gap="2" mb="1.5" minW="0">
                                                <Text
                                                    fontSize="sm"
                                                    fontWeight="700"
                                                    color="gray.800"
                                                    _dark={{ color: 'gray.100' }}
                                                    whiteSpace="nowrap"
                                                    overflow="hidden"
                                                    textOverflow="ellipsis"
                                                    minW="0"
                                                >
                                                    №{order.order_number || order.id}
                                                </Text>
                                                <HStack gap="1" flexShrink="0">
                                                    <Badge
                                                        colorPalette={getOrderTypeColor(order.type)}
                                                        variant="subtle"
                                                        borderRadius="full"
                                                        px="2"
                                                        fontSize="2xs"
                                                    >
                                                        {getOrderTypeShortLabel(order.type)}
                                                    </Badge>
                                                    <Badge
                                                        colorPalette={statusColors[order.status] || 'gray'}
                                                        variant="subtle"
                                                        borderRadius="full"
                                                        px="2"
                                                        fontSize="2xs"
                                                    >
                                                        {statusLabels[order.status] || order.status}
                                                    </Badge>
                                                </HStack>
                                            </Flex>
                                            <Flex align="center" justify="space-between" gap="2">
                                                <Text fontSize="xs" color="gray.400" minW="0" truncate>
                                                    {order.created_at}
                                                    {order.items_count > 0 && ` · ${order.items_count} ${order.items_count === 1 ? 'позиция' : order.items_count < 5 ? 'позиции' : 'позиций'}`}
                                                </Text>
                                                <Text
                                                    fontSize="sm"
                                                    fontWeight="700"
                                                    flexShrink="0"
                                                    color="gray.800"
                                                    _dark={{ color: 'gray.100' }}
                                                    whiteSpace="nowrap"
                                                >
                                                    {Number(order.total || 0).toLocaleString('ru-RU')}&nbsp;₽
                                                </Text>
                                            </Flex>
                                        </Box>

                                        {/* Desktop: одна строка */}
                                        <Flex
                                            display={{ base: 'none', md: 'flex' }}
                                            align="center"
                                            justify="space-between"
                                        >
                                            <VStack align="start" gap="0.5" flex="1" minW="0">
                                                <HStack gap="1.5">
                                                    <Text fontSize="sm" fontWeight="700" color="gray.800" _dark={{ color: 'gray.100' }}>
                                                        №{order.order_number || order.id}
                                                    </Text>
                                                    <Badge
                                                        colorPalette={getOrderTypeColor(order.type)}
                                                        variant="subtle"
                                                        borderRadius="full"
                                                        px="2"
                                                        fontSize="2xs"
                                                    >
                                                        {getOrderTypeShortLabel(order.type)}
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
                                            <Text fontSize="sm" fontWeight="700" flexShrink="0" color="gray.800" _dark={{ color: 'gray.100' }} whiteSpace="nowrap">
                                                {Number(order.total || 0).toLocaleString('ru-RU')}&nbsp;₽
                                            </Text>
                                        </Flex>
                                    </Box>
                                </Link>
                            ))}
                        </VStack>
                    )}
                </Card.Body>
            </Card.Root>
        </CabinetLayout>
    );
}
