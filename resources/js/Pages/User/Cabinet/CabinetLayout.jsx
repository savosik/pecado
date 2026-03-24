import { useState } from 'react';
import {
    Box, Flex, VStack, Text, HStack, Heading,
    Accordion, Span, Button, IconButton, Drawer, Portal, CloseButton,
} from '@chakra-ui/react';
import { Link, usePage } from '@inertiajs/react';
import UserLayout from '../UserLayout';
import {
    LuLayoutDashboard, LuShoppingBag, LuShoppingCart,
    LuUser, LuLogOut, LuLock, LuBuilding2, LuMenu, LuMapPin,
    LuFileDown, LuImage, LuRotateCcw, LuSettings, LuTruck,
} from 'react-icons/lu';

const menuGroups = [
    {
        title: 'Обзор',
        items: [
            { href: '/cabinet/dashboard', label: 'Дашборд', icon: LuLayoutDashboard },
        ],
    },
    {
        title: 'Заказы',
        items: [
            { href: '/cabinet/orders', label: 'Мои заказы', icon: LuShoppingBag },
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
        title: 'Инструменты',
        items: [
            { href: '/cabinet/product-exports', label: 'Выгрузки товаров', icon: LuFileDown },
            { href: '/cabinet/media', label: 'Медиатека', icon: LuImage },
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

function SidebarContent({ currentPath }) {
    return (
        <VStack align="stretch" gap="1">
            <Accordion.Root collapsible multiple defaultValue={menuGroups.map((_, i) => `group-${i}`)}>
                {menuGroups.map((group, gi) => (
                    <Accordion.Item key={gi} value={`group-${gi}`}>
                        <Accordion.ItemTrigger py="2" px="2">
                            <Span flex="1" fontSize="xs" fontWeight="700" textTransform="uppercase" letterSpacing="0.05em" color="gray.400">
                                {group.title}
                            </Span>
                            <Accordion.ItemIndicator />
                        </Accordion.ItemTrigger>
                        <Accordion.ItemContent>
                            <Accordion.ItemBody px="0" pb="2">
                                <VStack align="stretch" gap="0.5">
                                    {group.items.map((item) => {
                                        const isActive = currentPath === item.href
                                            || (item.href !== '/' && currentPath.startsWith(item.href));
                                        return (
                                            <Link key={item.href} href={item.href}>
                                                <HStack
                                                    px="3"
                                                    py="2"
                                                    borderRadius="lg"
                                                    bg={isActive ? 'pecado.50' : 'transparent'}
                                                    color={isActive ? 'pecado.600' : undefined}
                                                    _hover={{ bg: isActive ? 'pecado.50' : 'gray.50' }}
                                                    _dark={{
                                                        bg: isActive ? 'pecado.900/20' : 'transparent',
                                                        _hover: { bg: isActive ? 'pecado.900/20' : 'gray.800' },
                                                    }}
                                                    transition="all 0.15s"
                                                >
                                                    <item.icon size={16} />
                                                    <Text fontSize="sm" fontWeight={isActive ? '600' : '500'}>{item.label}</Text>
                                                </HStack>
                                            </Link>
                                        );
                                    })}
                                </VStack>
                            </Accordion.ItemBody>
                        </Accordion.ItemContent>
                    </Accordion.Item>
                ))}
            </Accordion.Root>

            <Link href="/logout" method="post" as="button" style={{ width: '100%' }}>
                <HStack px="3" py="2" borderRadius="lg" _hover={{ bg: 'red.50' }} _dark={{ _hover: { bg: 'red.900/20' } }} color="red.500">
                    <LuLogOut size={16} />
                    <Text fontSize="sm" fontWeight="500">Выйти</Text>
                </HStack>
            </Link>
        </VStack>
    );
}

function MobileMenuDrawer({ open, onClose, currentPath }) {
    return (
        <Drawer.Root open={open} onOpenChange={({ open: o }) => !o && onClose()} placement="start" size="xs">
            <Portal>
                <Drawer.Backdrop />
                <Drawer.Positioner>
                    <Drawer.Content>
                        <Drawer.Header borderBottom="1px solid" borderColor="gray.100" _dark={{ borderColor: 'gray.700' }}>
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
                    <Box
                        position="sticky"
                        top="80px"
                        bg="white"
                        borderRadius="xl"
                        border="1px solid"
                        borderColor="gray.100"
                        p="4"
                        _dark={{ bg: 'gray.800', borderColor: 'gray.700' }}
                    >
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
                        borderColor="gray.200"
                        bg="white"
                        _dark={{ bg: 'gray.800', borderColor: 'gray.700' }}
                        _hover={{ bg: 'gray.50', _dark: { bg: 'gray.750' } }}
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
                    <Box
                        css={{
                            '--chakra-colors-bg': 'white',
                            '--chakra-colors-bg-muted': 'white',
                        }}
                    >
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
