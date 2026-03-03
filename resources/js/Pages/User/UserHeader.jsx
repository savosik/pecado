import { useState, useCallback, useEffect } from 'react';
import { useFavoritesStore } from '@/stores/useFavoritesStore';
import { useCartStore } from '@/stores/useCartStore';
import CatalogPanel from './CatalogPanel';
import CurrencySwitcher from './Components/CurrencySwitcher';
import CartDropdown from '@/shared/CartDropdown';
import Search from '@/shared/Search';
import HeaderIconButton from '@/components/common/HeaderIconButton';
import {
    Box, Flex, HStack, Text, IconButton, Button, Badge,
    Drawer, Portal, CloseButton, VStack, Separator, Menu,
} from '@chakra-ui/react';
import { Link, usePage } from '@inertiajs/react';
import {
    LuHeart, LuUser, LuMenu, LuShoppingCart,
    LuHouse, LuGrid2X2, LuNewspaper, LuFileText, LuCircleHelp, LuMapPin,
    LuLayoutDashboard, LuShoppingBag, LuLogOut, LuLock,
} from 'react-icons/lu';

const navLinks = [
    { href: '/products', label: 'Каталог', icon: LuGrid2X2 },
    { href: '/promotions', label: 'Акции' },
    { href: '/news', label: 'Новости', icon: LuNewspaper },
    { href: '/articles', label: 'Статьи', icon: LuFileText },
    { href: '/faq', label: 'FAQ', icon: LuCircleHelp },
    { href: '/where-to-buy', label: 'Где купить', icon: LuMapPin },
];



export default function UserHeader() {
    const { auth } = usePage().props;
    const user = auth?.user;
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
    const [catalogOpen, setCatalogOpen] = useState(false);
    const favCount = useFavoritesStore((s) => s.ids.size);

    // Загрузка избранного и корзины при наличии пользователя
    useEffect(() => {
        if (!user) return;
        useFavoritesStore.getState().loadOnce(user);
        useCartStore.getState().init(user);
    }, [user?.id]);

    const openCatalog = useCallback(() => {
        setMobileMenuOpen(false);
        setCatalogOpen(true);
    }, []);

    // Слушатель кастомного события catalog:open (используется MobileNav)
    useEffect(() => {
        const handler = () => setCatalogOpen(true);
        document.addEventListener('catalog:open', handler);
        return () => document.removeEventListener('catalog:open', handler);
    }, []);

    return (
        <>
            {/* Main Header */}
            <Box
                as="header"
                bg="white"
                shadow="sm"
                position="sticky"
                top="0"
                zIndex="50"
                _dark={{ bg: 'gray.900' }}
            >
                <Box maxW="1360px" mx="auto" px={{ base: '3', md: '6' }} py="1">
                    <Flex align="center" gap="3">
                        {/* Logo */}
                        <Link href="/">
                            <Box as="img" src="/images/logo.png" alt="Pecado" h="10" objectFit="contain" flexShrink="0" />
                        </Link>

                        {/* Catalog Button — desktop (sm+) */}
                        <Button
                            onClick={openCatalog}
                            display={{ base: 'none', sm: 'inline-flex' }}
                            size="sm"
                            bg="#9e1b32"
                            color="white"
                            _hover={{ bg: '#7a1527' }}
                            flexShrink="0"
                        >
                            <LuMenu />
                            <Text display={{ base: 'none', lg: 'inline' }} textTransform="uppercase" fontSize="xs" letterSpacing="0.05em">Каталог</Text>
                        </Button>

                        {/* Search — takes remaining space */}
                        <Search />

                        {/* Desktop Actions — lg+ */}
                        <HStack as="nav" gap="2" display={{ base: 'none', lg: 'flex' }} flexShrink="0">
                            {user && <CurrencySwitcher />}
                            {user && (
                                <>
                                    <Link href="/favorites" aria-label="Избранное">
                                        <HeaderIconButton icon={LuHeart} count={favCount} badgeColor="pecado.solid" aria-label="Избранное" />
                                    </Link>

                                    <CartDropdown />
                                </>
                            )}

                            {user ? (
                                <Menu.Root positioning={{ placement: 'bottom-end' }}>
                                    <Menu.Trigger asChild>
                                        <Button
                                            variant="ghost"
                                            colorPalette="gray"
                                            size="sm"
                                            title={user.name}
                                        >
                                            <Flex
                                                align="center"
                                                justify="center"
                                                w="7"
                                                h="7"
                                                borderRadius="full"
                                                bg="gray.200"
                                                _dark={{ bg: 'gray.600' }}
                                                flexShrink="0"
                                            >
                                                <Text fontSize="xs" fontWeight="600" color="gray.700" _dark={{ color: 'gray.200' }}>
                                                    {(() => {
                                                        const parts = (user.name || '').trim().split(/\s+/);
                                                        const first = parts[0]?.[0] ?? '';
                                                        const last = parts.length > 1 ? parts[parts.length - 1]?.[0] ?? '' : '';
                                                        return (first + last).toUpperCase() || '?';
                                                    })()}
                                                </Text>
                                            </Flex>
                                            <Text display={{ base: 'none', xl: 'inline' }} fontSize="xs" maxW="120px" truncate>
                                                {user.name}
                                            </Text>
                                        </Button>
                                    </Menu.Trigger>
                                    <Portal>
                                        <Menu.Positioner>
                                            <Menu.Content minW="200px" css={{ '& a, & button': { cursor: 'pointer' } }}>
                                                <Menu.Item value="dashboard" asChild>
                                                    <Link href="/cabinet/dashboard">
                                                        <LuLayoutDashboard />
                                                        Личный кабинет
                                                    </Link>
                                                </Menu.Item>
                                                <Menu.Item value="orders" asChild>
                                                    <Link href="/cabinet/orders">
                                                        <LuShoppingBag />
                                                        Мои заказы
                                                    </Link>
                                                </Menu.Item>
                                                <Menu.Item value="favorites" asChild>
                                                    <Link href="/favorites">
                                                        <LuHeart />
                                                        Избранное
                                                    </Link>
                                                </Menu.Item>
                                                <Menu.Separator />
                                                <Menu.Item value="profile" asChild>
                                                    <Link href="/cabinet/profile">
                                                        <LuUser />
                                                        Мои данные
                                                    </Link>
                                                </Menu.Item>
                                                <Menu.Item value="password" asChild>
                                                    <Link href="/cabinet/change-password">
                                                        <LuLock />
                                                        Смена пароля
                                                    </Link>
                                                </Menu.Item>
                                                <Menu.Separator />
                                                <Menu.Item value="logout" color="fg.error" asChild>
                                                    <Link href="/logout" method="post" as="button" style={{ width: '100%' }}>
                                                        <LuLogOut />
                                                        Выйти
                                                    </Link>
                                                </Menu.Item>
                                            </Menu.Content>
                                        </Menu.Positioner>
                                    </Portal>
                                </Menu.Root>
                            ) : (
                                <HStack gap="1">
                                    <Button
                                        as={Link}
                                        href="/login"
                                        variant="ghost"
                                        colorPalette="gray"
                                        size="sm"
                                    >
                                        <LuUser />
                                        Войти
                                    </Button>
                                    <Button
                                        as={Link}
                                        href="/register"
                                        variant="outline"
                                        colorPalette="pecado"
                                        size="sm"
                                    >
                                        Регистрация
                                    </Button>
                                </HStack>
                            )}
                        </HStack>

                        {/* Mobile Menu Button — lg- */}
                        <IconButton
                            aria-label="Меню"
                            display={{ base: 'inline-flex', lg: 'none' }}
                            variant="ghost"
                            colorPalette="gray"
                            size="sm"
                            onClick={() => setMobileMenuOpen(true)}
                        >
                            <LuMenu />
                        </IconButton>
                    </Flex>
                </Box>

                {/* Desktop Nav Row */}
                <Box
                    display={{ base: 'none', lg: 'block' }}
                >
                    <Box maxW="1360px" mx="auto" px="6" py="1.5">
                        <HStack gap="6">
                            {navLinks.map((item) => (
                                <Link key={item.href} href={item.href}>
                                    <Text
                                        fontSize="xs"
                                        fontWeight="500"
                                        textTransform="uppercase"
                                        letterSpacing="0.04em"
                                        color="gray.600"
                                        _hover={{ color: 'pecado.500' }}
                                        _dark={{ color: 'gray.400', _hover: { color: 'pecado.300' } }}
                                        transition="colors 0.2s"
                                    >
                                        {item.label}
                                    </Text>
                                </Link>
                            ))}
                        </HStack>
                    </Box>
                </Box>
            </Box>

            {/* Mobile Drawer */}
            <Drawer.Root open={mobileMenuOpen} onOpenChange={(e) => setMobileMenuOpen(e.open)} placement="end">
                <Portal>
                    <Drawer.Backdrop />
                    <Drawer.Positioner>
                        <Drawer.Content>
                            <Drawer.Header borderBottom="1px solid" borderColor="gray.100" _dark={{ borderColor: 'gray.700' }}>
                                <Drawer.Title fontSize="lg" fontWeight="700">Меню</Drawer.Title>
                                <Drawer.CloseTrigger asChild>
                                    <CloseButton size="sm" />
                                </Drawer.CloseTrigger>
                            </Drawer.Header>
                            <Drawer.Body py="4">
                                <VStack align="stretch" gap="1">
                                    {/* User info */}
                                    {user ? (
                                        <>
                                            <Box px="3" py="3" bg="gray.50" borderRadius="lg" mb="2" _dark={{ bg: 'gray.800' }}>
                                                <Text fontWeight="600" fontSize="sm">{user.name}</Text>
                                                <Text fontSize="xs" color="gray.500">{user.email}</Text>
                                            </Box>
                                            <Box mb="2">
                                                <CurrencySwitcher />
                                            </Box>
                                        </>
                                    ) : (
                                        <HStack gap="2" mb="2">
                                            <Button as={Link} href="/login" size="sm" colorPalette="pecado" variant="solid" flex="1" onClick={() => setMobileMenuOpen(false)}>
                                                Войти
                                            </Button>
                                            <Button as={Link} href="/register" size="sm" variant="outline" flex="1" onClick={() => setMobileMenuOpen(false)}>
                                                Регистрация
                                            </Button>
                                        </HStack>
                                    )}

                                    <Separator />

                                    {/* Nav links */}
                                    <Link href="/" onClick={() => setMobileMenuOpen(false)}>
                                        <HStack px="3" py="2.5" borderRadius="md" _hover={{ bg: 'gray.50' }} _dark={{ _hover: { bg: 'gray.800' } }}>
                                            <LuHouse size={18} />
                                            <Text fontSize="sm" fontWeight="500">Главная</Text>
                                        </HStack>
                                    </Link>

                                    {navLinks.map((item) => (
                                        <Link key={item.href} href={item.href} onClick={() => setMobileMenuOpen(false)}>
                                            <HStack px="3" py="2.5" borderRadius="md" _hover={{ bg: 'gray.50' }} _dark={{ _hover: { bg: 'gray.800' } }}>
                                                {item.icon && <item.icon size={18} />}
                                                <Text fontSize="sm" fontWeight="500">{item.label}</Text>
                                            </HStack>
                                        </Link>
                                    ))}

                                    {user && (
                                        <>
                                            <Separator />

                                            <Link href="/favorites" onClick={() => setMobileMenuOpen(false)}>
                                                <HStack px="3" py="2.5" borderRadius="md" _hover={{ bg: 'gray.50' }} _dark={{ _hover: { bg: 'gray.800' } }}>
                                                    <LuHeart size={18} />
                                                    <Text fontSize="sm" fontWeight="500">Избранное</Text>
                                                    {favCount > 0 && (
                                                        <Badge colorPalette="red" variant="solid" size="xs" borderRadius="full">
                                                            {favCount > 99 ? '99+' : favCount}
                                                        </Badge>
                                                    )}
                                                </HStack>
                                            </Link>

                                            <Link href="/cart" onClick={() => setMobileMenuOpen(false)}>
                                                <HStack px="3" py="2.5" borderRadius="md" _hover={{ bg: 'gray.50' }} _dark={{ _hover: { bg: 'gray.800' } }}>
                                                    <LuShoppingCart size={18} />
                                                    <Text fontSize="sm" fontWeight="500">Корзина</Text>
                                                </HStack>
                                            </Link>
                                        </>
                                    )}

                                    {user && (
                                        <>
                                            <Separator />
                                            <Link href="/cabinet/dashboard" onClick={() => setMobileMenuOpen(false)}>
                                                <HStack px="3" py="2.5" borderRadius="md" _hover={{ bg: 'gray.50' }} _dark={{ _hover: { bg: 'gray.800' } }}>
                                                    <LuLayoutDashboard size={18} />
                                                    <Text fontSize="sm" fontWeight="500">Личный кабинет</Text>
                                                </HStack>
                                            </Link>
                                            <Link href="/cabinet/orders" onClick={() => setMobileMenuOpen(false)}>
                                                <HStack px="3" py="2.5" borderRadius="md" _hover={{ bg: 'gray.50' }} _dark={{ _hover: { bg: 'gray.800' } }}>
                                                    <LuShoppingBag size={18} />
                                                    <Text fontSize="sm" fontWeight="500">Мои заказы</Text>
                                                </HStack>
                                            </Link>
                                            <Separator />
                                            <Link href="/logout" method="post" as="button" onClick={() => setMobileMenuOpen(false)} style={{ width: '100%' }}>
                                                <HStack px="3" py="2.5" borderRadius="md" color="red.500" _hover={{ bg: 'red.50' }} _dark={{ _hover: { bg: 'red.900/20' } }}>
                                                    <LuLogOut size={18} />
                                                    <Text fontSize="sm" fontWeight="500">Выйти</Text>
                                                </HStack>
                                            </Link>
                                        </>
                                    )}
                                </VStack>
                            </Drawer.Body>
                        </Drawer.Content>
                    </Drawer.Positioner>
                </Portal>
            </Drawer.Root>

            <CatalogPanel open={catalogOpen} onClose={() => setCatalogOpen(false)} />
        </>
    );
}
