import { useState } from 'react';
import {
    Box, Flex, VStack, Text, HStack, Heading,
    Button, Drawer, Portal, CloseButton,
} from '@chakra-ui/react';
import { Link, usePage } from '@inertiajs/react';
import UserLayout from '../UserLayout';
import CollapsibleFilterCard from '../Products/filters/CollapsibleFilterCard';
import {
    LuLayoutDashboard, LuShoppingBag, LuShoppingCart,
    LuUser, LuLogOut, LuLock, LuBuilding2, LuMenu, LuMapPin,
    LuFileDown, LuImage, LuRotateCcw, LuSettings, LuTruck, LuLayoutGrid, LuWrench, LuCode,
    LuChartPie, LuMessageSquare, LuArrowRightLeft,
} from 'react-icons/lu';

const menuGroups = [
    {
        title: 'Обзор',
        items: [
            { href: '/cabinet/dashboard', label: 'Дашборд', icon: LuLayoutDashboard },
            { href: '/cabinet/analytics', label: 'Аналитика', icon: LuChartPie },
        ],
    },
    {
        title: 'Заказы',
        items: [
            { href: '/cabinet/orders', label: 'Мои заказы', icon: LuShoppingBag },
            { href: '/cabinet/order-changes', label: 'Изменения заказов', icon: LuArrowRightLeft },
            { href: '/cabinet/shipments', label: 'Отгрузки', icon: LuTruck },
            { href: '/cabinet/returns', label: 'Возвраты', icon: LuRotateCcw },
            { href: '/cabinet/carts', label: 'Мои корзины', icon: LuShoppingCart },
        ],
    },
    {
        title: 'Организация',
        items: [
            { href: '/cabinet/companies', label: 'Мои компании', icon: LuBuilding2 },
            { href: '/cabinet/delivery-addresses', label: 'Адреса доставки', icon: LuMapPin },
        ],
    },
    {
        title: 'Выгрузки',
        items: [
            { href: '/cabinet/export-presets', label: 'Стандартные выгрузки', icon: LuLayoutGrid },
            { href: '/cabinet/product-exports', label: 'Конструктор выгрузок', icon: LuWrench },
            { href: '/cabinet/api-tokens', label: 'API', icon: LuCode },
        ],
    },
    {
        title: 'Инструменты',
        items: [
            { href: '/cabinet/media', label: 'Медиатека', icon: LuImage },
        ],
    },
    {
        title: 'Поддержка',
        items: [
            { href: '/cabinet/questions', label: 'Мои вопросы', icon: LuMessageSquare },
        ],
    },
    {
        title: 'Настройки',
        items: [
            { href: '/cabinet/profile', label: 'Мои данные', icon: LuUser },
            { href: '/cabinet/change-password', label: 'Смена пароля', icon: LuLock },
        ],
    },
];

const allMenuItems = menuGroups.flatMap(g => g.items);

function MenuItemRow({ item, isActive }) {
    return (
        <Link href={item.href}>
            <HStack
                gap="2"
                px="1"
                py="1.5"
                borderRadius="md"
                bg={isActive ? 'pecado.50' : 'transparent'}
                color={isActive ? 'pecado.600' : 'gray.700'}
                _hover={{ bg: isActive ? 'pecado.100' : 'gray.50' }}
                _dark={{
                    bg: isActive ? 'pecado.900/30' : 'transparent',
                    color: isActive ? 'pecado.300' : 'gray.300',
                    _hover: { bg: isActive ? 'pecado.900/40' : 'gray.700' },
                }}
                transition="all 0.15s"
            >
                <item.icon size={16} />
                <Text fontSize="sm" fontWeight={isActive ? '600' : '400'}>{item.label}</Text>
            </HStack>
        </Link>
    );
}

function SidebarContent({ currentPath }) {
    return (
        <Flex direction="column" gap="2">
            {menuGroups.map((group, gi) => {
                return (
                    <CollapsibleFilterCard
                        key={gi}
                        title={group.title}
                        storageKey={`cabinet_menu_group_${gi}_open`}
                        defaultOpen
                        bodyPx="2"
                        bodyPy="2"
                    >
                        <VStack align="stretch" gap="0.5">
                            {group.items.map((item) => {
                                const isActive = currentPath === item.href
                                    || (item.href !== '/' && currentPath.startsWith(item.href));
                                return <MenuItemRow key={item.href} item={item} isActive={isActive} />;
                            })}
                        </VStack>
                    </CollapsibleFilterCard>
                );
            })}

            <Box
                bg="bg"
                borderRadius="md"
                border="1px solid"
                borderColor="gray.200"
                _dark={{ borderColor: 'gray.700' }}
                overflow="hidden"
            >
                <Link href="/logout" method="post" as="button" style={{ width: '100%' }}>
                    <HStack
                        gap="2"
                        px="3"
                        py="2.5"
                        color="red.500"
                        _hover={{ bg: 'red.50' }}
                        _dark={{ _hover: { bg: 'red.900/20' } }}
                        transition="background 0.15s"
                    >
                        <LuLogOut size={16} />
                        <Text fontSize="sm" fontWeight="500">Выйти</Text>
                    </HStack>
                </Link>
            </Box>
        </Flex>
    );
}

function MobileMenuDrawer({ open, onClose, currentPath }) {
    return (
        <Drawer.Root open={open} onOpenChange={({ open: o }) => !o && onClose()} placement="start" size="xs">
            <Portal>
                <Drawer.Backdrop />
                <Drawer.Positioner>
                    <Drawer.Content>
                        <Drawer.Header borderBottom="1px solid" borderColor="border.muted">
                            <Drawer.Title fontSize="lg" fontWeight="700">Личный кабинет</Drawer.Title>
                            <Drawer.CloseTrigger asChild position="absolute" top="3" right="3">
                                <CloseButton size="sm" />
                            </Drawer.CloseTrigger>
                        </Drawer.Header>
                        <Drawer.Body p="3" onClick={onClose}>
                            <SidebarContent currentPath={currentPath} />
                        </Drawer.Body>
                    </Drawer.Content>
                </Drawer.Positioner>
            </Portal>
        </Drawer.Root>
    );
}

export default function CabinetLayout({ title, children, actions }) {
    const { url } = usePage();
    const currentPath = url.split('?')[0];
    const [drawerOpen, setDrawerOpen] = useState(false);

    // Find current page label
    const currentItem = allMenuItems.find(
        i => currentPath === i.href || (i.href !== '/' && currentPath.startsWith(i.href))
    );

    return (
        <UserLayout>
            <Flex gap="6" direction={{ base: 'column', lg: 'row' }}>
                {/* Desktop Sidebar */}
                <Box
                    display={{ base: 'none', lg: 'block' }}
                    w="260px"
                    flexShrink="0"
                >
                    <Box position="sticky" top="80px">
                        <SidebarContent currentPath={currentPath} />
                    </Box>
                </Box>

                {/* Mobile Navigation Bar */}
                <Box display={{ base: 'block', lg: 'none' }}>
                    <Button
                        w="100%"
                        variant="outline"
                        size="lg"
                        borderRadius="xl"
                        borderColor="border"
                        bg="bg"

                        _hover={{ bg: 'gray.50', _dark: { bg: 'gray.700' } }}
                        onClick={() => setDrawerOpen(true)}
                        justifyContent="flex-start"
                        px="4"
                    >
                        <HStack gap="3" flex="1">
                            <LuMenu size={20} />
                            <Text fontSize="sm" fontWeight="600">Меню личного кабинета</Text>
                        </HStack>
                        {currentItem && (
                            <Text fontSize="xs" color="pecado.500" fontWeight="500">{currentItem.label}</Text>
                        )}
                    </Button>
                </Box>

                {/* Content */}
                <Box flex="1" minW="0">
                    <Flex align="center" justify="space-between" mb="5">
                        <Heading size={{ base: 'xl', md: '3xl' }} fontWeight="bold" color="fg">
                            {title}
                        </Heading>
                        {actions && <Box>{actions}</Box>}
                    </Flex>
                    <Box>
                        {children}
                    </Box>
                </Box>
            </Flex>

            {/* Mobile Drawer */}
            <MobileMenuDrawer
                open={drawerOpen}
                onClose={() => setDrawerOpen(false)}
                currentPath={currentPath}
            />
        </UserLayout>
    );
}

export { menuGroups, SidebarContent };
