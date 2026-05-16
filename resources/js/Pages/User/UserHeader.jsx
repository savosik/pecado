import { useState, useCallback, useEffect, useRef } from 'react';
import { useFavoritesStore } from '@/stores/useFavoritesStore';
import { useCartStore } from '@/stores/useCartStore';
import CatalogPanel from './CatalogPanel';

import CartDropdown from '@/shared/CartDropdown';
import Search from '@/shared/Search';
import HeaderIconButton from '@/components/common/HeaderIconButton';
import { useAuthDialog } from '@/contexts/AuthDialogContext';
import AuthDialog from '@/components/auth/AuthDialog';
import { Tooltip } from '@/components/ui/tooltip';
import {
    Box, Flex, HStack, Text, IconButton, Button, Badge,
    Drawer, Portal, CloseButton, VStack, Separator, Menu,
} from '@chakra-ui/react';
import { Link, usePage } from '@inertiajs/react';
import {
    LuHeart, LuUser, LuMenu, LuShoppingCart,
    LuHouse, LuGrid2X2, LuNewspaper, LuFileText, LuCircleHelp, LuMapPin,
    LuLayoutDashboard, LuShoppingBag, LuLogOut, LuLock, LuBadge,
} from 'react-icons/lu';

// Маппинг имён иконок на компоненты
const iconMap = {
    LuGrid2X2, LuNewspaper, LuFileText, LuCircleHelp, LuMapPin, LuBadge,
    LuHeart, LuShoppingCart, LuHouse, LuMenu, LuUser,
    LuLayoutDashboard, LuShoppingBag,
};

// Скрывает элемент при скролле вниз и показывает при скролле вверх (мобильный UX-паттерн)
function useHideOnScrollDown({ threshold = 5, topOffset = 50 } = {}) {
    const [hidden, setHidden] = useState(false);
    const lastY = useRef(0);

    useEffect(() => {
        lastY.current = window.scrollY;
        const onScroll = () => {
            const y = window.scrollY;
            const delta = y - lastY.current;

            if (y < topOffset) {
                setHidden(false);
                lastY.current = y;
                return;
            }
            if (Math.abs(delta) < threshold) return;

            setHidden(delta > 0);
            lastY.current = y;
        };
        window.addEventListener('scroll', onScroll, { passive: true });
        return () => window.removeEventListener('scroll', onScroll);
    }, [threshold, topOffset]);

    return hidden;
}




export default function UserHeader() {
    const { auth, headerMenuItems = [] } = usePage().props;
    const user = auth?.user;

    // Преобразуем данные из БД в формат для рендеринга
    const navLinks = headerMenuItems.map((item) => ({
        href: item.url,
        label: item.title,
        icon: item.icon ? iconMap[item.icon] : null,
        badgeText: item.badge_text,
        badgeColor: item.badge_color,
        openInNewTab: item.open_in_new_tab,
    }));
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
    const [catalogOpen, setCatalogOpen] = useState(false);
    const favCount = useFavoritesStore((s) => s.ids.size);
    const { openLogin, openRegister } = useAuthDialog();
    const headerHidden = useHideOnScrollDown();

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
                bg="bg"
                shadow="md"
                position="sticky"
                top="0"
                zIndex="50"
                _dark={{ bg: 'gray.900' }}
                transform={{ base: headerHidden ? 'translateY(-100%)' : 'translateY(0)', md: 'none' }}
                transition="transform 0.25s ease"
                willChange="transform"
            >
                <Box maxW="1360px" mx="auto" px={{ base: '3', md: '6' }} py="1">
                    <Flex align="center" gap="3">
                        {/* Logo */}
                        <Link href="/">
                            <Box as="img" src="/images/logo.png" alt="Pecado" h="10" objectFit="contain" flexShrink="0" />
                        </Link>

                        {/* Catalog Button — на всех ширинах; текст «Каталог» скрыт на <lg */}
                        <Button
                            onClick={openCatalog}
                            size="sm"
                            bg="#9e1b32"
                            color="white"
                            _hover={{ bg: '#7a1527' }}
                            flexShrink="0"
                            aria-label="Открыть каталог"
                        >
                            <LuMenu />
                            <Text display={{ base: 'none', lg: 'inline' }} textTransform="uppercase" fontSize="xs" letterSpacing="0.05em">Каталог</Text>
                        </Button>

                        {/* Search — takes remaining space */}
                        <Search />

                        {/* Desktop Actions — lg+ */}
                        <HStack as="nav" gap="2" display={{ base: 'none', lg: 'flex' }} flexShrink="0">

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
                                            <Tooltip content={user.client_status_name || 'Статус не назначен'} positioning={{ placement: 'bottom' }}>
                                                <Flex
                                                    align="center"
                                                    justify="center"
                                                    w="7"
                                                    h="7"
                                                    borderRadius="full"
                                                    bg="gray.200"
                                                    _dark={{ bg: 'gray.600' }}
                                                    flexShrink="0"
                                                    border={user.client_status_color ? '2px solid' : 'none'}
                                                    borderColor={user.client_status_color || 'transparent'}
                                                    boxShadow={user.client_status_color ? `0 0 0 1px ${user.client_status_color}30, 0 0 8px ${user.client_status_color}40` : 'none'}
                                                    transition="all 0.3s"
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
                                            </Tooltip>
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
                                        variant="ghost"
                                        colorPalette="gray"
                                        size="sm"
                                        onClick={openLogin}
                                    >
                                        <LuUser />
                                        Войти
                                    </Button>
                                    <Button
                                        variant="outline"
                                        colorPalette="pecado"
                                        size="sm"
                                        onClick={openRegister}
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
                        <Flex justify="space-between" align="center" gap="3" wrap="nowrap">
                            {navLinks.map((item) => {
                                const IconComp = item.icon;
                                return (
                                    <Link
                                        key={item.href}
                                        href={item.href}
                                        {...(item.openInNewTab ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
                                    >
                                        <HStack gap="1.5">
                                            {IconComp && <IconComp size={14} />}
                                            <Text
                                                fontSize="xs"
                                                fontWeight="500"
                                                textTransform="uppercase"
                                                letterSpacing="0.04em"
                                                color="gray.600"
                                                _hover={{ color: 'pecado.500' }}
                                                _dark={{ color: 'gray.400', _hover: { color: 'pecado.300' } }}
                                                transition="colors 0.2s"
                                                whiteSpace="nowrap"
                                            >
                                                {item.label}
                                            </Text>
                                            {item.badgeText && (
                                                <Badge
                                                    size="xs"
                                                    variant="solid"
                                                    bg={item.badgeColor || '#e53e3e'}
                                                    color="white"
                                                    borderRadius="full"
                                                    px="1.5"
                                                    fontSize="2xs"
                                                    lineHeight="1"
                                                >
                                                    {item.badgeText}
                                                </Badge>
                                            )}
                                        </HStack>
                                    </Link>
                                );
                            })}
                        </Flex>
                    </Box>
                </Box>
            </Box>

            {/* Mobile Drawer */}
            <Drawer.Root open={mobileMenuOpen} onOpenChange={(e) => setMobileMenuOpen(e.open)} placement="end">
                <Portal>
                    <Drawer.Backdrop />
                    <Drawer.Positioner>
                        <Drawer.Content>
                            <Drawer.Header borderBottom="1px solid" borderColor="border.muted">
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

                                        </>
                                    ) : (
                                        <HStack gap="2" mb="2">
                                            <Button size="sm" colorPalette="pecado" variant="solid" flex="1" onClick={() => { setMobileMenuOpen(false); openLogin(); }}>
                                                Войти
                                            </Button>
                                            <Button size="sm" variant="outline" flex="1" onClick={() => { setMobileMenuOpen(false); openRegister(); }}>
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

                                    {navLinks.map((item) => {
                                        const IconComp = item.icon;
                                        return (
                                            <Link
                                                key={item.href}
                                                href={item.href}
                                                onClick={() => setMobileMenuOpen(false)}
                                                {...(item.openInNewTab ? { target: '_blank', rel: 'noopener noreferrer' } : {})}
                                            >
                                                <HStack px="3" py="2.5" borderRadius="md" _hover={{ bg: 'gray.50' }} _dark={{ _hover: { bg: 'gray.800' } }}>
                                                    {IconComp && <IconComp size={18} />}
                                                    <Text fontSize="sm" fontWeight="500">{item.label}</Text>
                                                    {item.badgeText && (
                                                        <Badge
                                                            size="xs"
                                                            variant="solid"
                                                            bg={item.badgeColor || '#e53e3e'}
                                                            color="white"
                                                            borderRadius="full"
                                                            px="1.5"
                                                            fontSize="2xs"
                                                        >
                                                            {item.badgeText}
                                                        </Badge>
                                                    )}
                                                </HStack>
                                            </Link>
                                        );
                                    })}

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
            <AuthDialog />
        </>
    );
}
