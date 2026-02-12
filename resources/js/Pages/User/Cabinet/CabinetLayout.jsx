import {
    Box, Flex, VStack, Text, HStack, Heading, Separator,
    IconButton, Accordion, Span, Button,
} from '@chakra-ui/react';
import { Link, usePage } from '@inertiajs/react';
import UserLayout from '../UserLayout';
import {
    LuLayoutDashboard, LuShoppingBag, LuHeart, LuMessageCircle,
    LuUser, LuSettings, LuLogOut, LuWallet, LuFileText,
    LuShield, LuBell, LuMenu,
} from 'react-icons/lu';
import { useState } from 'react';

const menuGroups = [
    {
        title: 'Обзор',
        items: [
            { href: '/cabinet/dashboard', label: 'Дашборд', icon: LuLayoutDashboard },
            { href: '/cabinet/notifications', label: 'Уведомления', icon: LuBell },
        ],
    },
    {
        title: 'Покупки',
        items: [
            { href: '/cabinet/orders', label: 'Мои заказы', icon: LuShoppingBag },
            { href: '/cabinet/favorites', label: 'Избранное', icon: LuHeart },
            { href: '/cabinet/wallet', label: 'Кошелёк', icon: LuWallet },
        ],
    },
    {
        title: 'Профиль',
        items: [
            { href: '/cabinet/profile', label: 'Мои данные', icon: LuUser },
            { href: '/cabinet/addresses', label: 'Адреса', icon: LuFileText },
            { href: '/cabinet/security', label: 'Безопасность', icon: LuShield },
            { href: '/cabinet/settings', label: 'Настройки', icon: LuSettings },
        ],
    },
];

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
                                        const isActive = currentPath === item.href;
                                        return (
                                            <Link key={item.href} href={item.href}>
                                                <HStack
                                                    px="3"
                                                    py="2"
                                                    borderRadius="lg"
                                                    bg={isActive ? 'pink.50' : 'transparent'}
                                                    color={isActive ? 'pink.600' : undefined}
                                                    _hover={{ bg: isActive ? 'pink.50' : 'gray.50' }}
                                                    _dark={{
                                                        bg: isActive ? 'pink.900/20' : 'transparent',
                                                        _hover: { bg: isActive ? 'pink.900/20' : 'gray.800' },
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

            <Separator my="2" />

            <Link href="/logout" method="post" as="button" style={{ width: '100%' }}>
                <HStack px="3" py="2" borderRadius="lg" _hover={{ bg: 'red.50' }} _dark={{ _hover: { bg: 'red.900/20' } }} color="red.500">
                    <LuLogOut size={16} />
                    <Text fontSize="sm" fontWeight="500">Выйти</Text>
                </HStack>
            </Link>
        </VStack>
    );
}

export default function CabinetLayout({ title, children }) {
    const { url } = usePage();
    const currentPath = url.split('?')[0];
    const [sidebarOpen, setSidebarOpen] = useState(false);

    return (
        <UserLayout>
            <Flex gap="6" direction={{ base: 'column', lg: 'row' }}>
                {/* Mobile header */}
                <Flex display={{ base: 'flex', lg: 'none' }} align="center" justify="space-between" mb="2">
                    <Heading size="lg" fontWeight="800">{title}</Heading>
                    <IconButton
                        aria-label="Меню кабинета"
                        variant="ghost"
                        size="sm"
                        onClick={() => setSidebarOpen(!sidebarOpen)}
                    >
                        <LuMenu />
                    </IconButton>
                </Flex>

                {/* Mobile Sidebar (collapsible) */}
                {sidebarOpen && (
                    <Box display={{ base: 'block', lg: 'none' }} mb="4">
                        <Box
                            bg="white"
                            borderRadius="xl"
                            border="1px solid"
                            borderColor="gray.100"
                            p="3"
                            _dark={{ bg: 'gray.800', borderColor: 'gray.700' }}
                        >
                            <SidebarContent currentPath={currentPath} />
                        </Box>
                    </Box>
                )}

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

                {/* Content */}
                <Box flex="1" minW="0">
                    <Heading display={{ base: 'none', lg: 'block' }} size="xl" fontWeight="800" mb="5">
                        {title}
                    </Heading>
                    {children}
                </Box>
            </Flex>
        </UserLayout>
    );
}
