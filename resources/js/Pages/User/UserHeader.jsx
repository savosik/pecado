import { useState, useCallback } from 'react';
import CatalogPanel from './CatalogPanel';
import CurrencySwitcher from './Components/CurrencySwitcher';
import {
    Box, Flex, HStack, Text, Input, IconButton, Button,
    Drawer, Portal, CloseButton, VStack, Separator,
    Badge, Image,
} from '@chakra-ui/react';
import { Link, usePage } from '@inertiajs/react';
import {
    LuSearch, LuHeart, LuShoppingCart, LuUser, LuMenu,
    LuHouse, LuGrid2X2, LuNewspaper, LuFileText, LuCircleHelp, LuMapPin,
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

    const openCatalog = useCallback(() => {
        setMobileMenuOpen(false);
        setCatalogOpen(true);
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
                        <Flex flex="1" position="relative">
                            <Input
                                placeholder="Поиск товаров..."
                                size="sm"
                                borderRadius="lg"
                                bg="gray.50"
                                _dark={{ bg: 'gray.800' }}
                                pr="10"
                            />
                            <IconButton
                                aria-label="Поиск"
                                size="sm"
                                variant="ghost"
                                position="absolute"
                                right="1"
                                top="50%"
                                transform="translateY(-50%)"
                                colorPalette="gray"
                            >
                                <LuSearch />
                            </IconButton>
                        </Flex>

                        {/* Desktop Actions — lg+ */}
                        <HStack as="nav" gap="2" display={{ base: 'none', lg: 'flex' }} flexShrink="0">
                            {user && <CurrencySwitcher />}
                            {user && (
                                <>
                                    <IconButton
                                        as={Link}
                                        href="/favorites"
                                        aria-label="Избранное"
                                        variant="ghost"
                                        colorPalette="gray"
                                        size="sm"
                                    >
                                        <LuHeart />
                                    </IconButton>

                                    <IconButton
                                        as={Link}
                                        href="/cart"
                                        aria-label="Корзина"
                                        variant="ghost"
                                        colorPalette="gray"
                                        size="sm"
                                    >
                                        <LuShoppingCart />
                                    </IconButton>
                                </>
                            )}

                            {user ? (
                                <Button
                                    as={Link}
                                    href="/cabinet/dashboard"
                                    variant="ghost"
                                    colorPalette="gray"
                                    size="sm"
                                >
                                    <LuUser />
                                    {user.name?.split(' ')[0] || 'Кабинет'}
                                </Button>
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
                            {navLinks.map((item) =>
                                item.href === '/products' ? (
                                    <Text
                                        key={item.href}
                                        as="button"
                                        onClick={openCatalog}
                                        fontSize="xs"
                                        fontWeight="500"
                                        textTransform="uppercase"
                                        letterSpacing="0.04em"
                                        color="gray.600"
                                        _hover={{ color: 'pecado.500' }}
                                        _dark={{ color: 'gray.400', _hover: { color: 'pecado.300' } }}
                                        transition="colors 0.2s"
                                        cursor="pointer"
                                    >
                                        {item.label}
                                    </Text>
                                ) : (
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
                                )
                            )}
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
                                        item.href === '/products' ? (
                                            <Box key={item.href} onClick={openCatalog} cursor="pointer">
                                                <HStack px="3" py="2.5" borderRadius="md" _hover={{ bg: 'gray.50' }} _dark={{ _hover: { bg: 'gray.800' } }}>
                                                    {item.icon && <item.icon size={18} />}
                                                    <Text fontSize="sm" fontWeight="500">{item.label}</Text>
                                                </HStack>
                                            </Box>
                                        ) : (
                                            <Link key={item.href} href={item.href} onClick={() => setMobileMenuOpen(false)}>
                                                <HStack px="3" py="2.5" borderRadius="md" _hover={{ bg: 'gray.50' }} _dark={{ _hover: { bg: 'gray.800' } }}>
                                                    {item.icon && <item.icon size={18} />}
                                                    <Text fontSize="sm" fontWeight="500">{item.label}</Text>
                                                </HStack>
                                            </Link>
                                        )
                                    ))}

                                    {user && (
                                        <>
                                            <Separator />

                                            <Link href="/favorites" onClick={() => setMobileMenuOpen(false)}>
                                                <HStack px="3" py="2.5" borderRadius="md" _hover={{ bg: 'gray.50' }} _dark={{ _hover: { bg: 'gray.800' } }}>
                                                    <LuHeart size={18} />
                                                    <Text fontSize="sm" fontWeight="500">Избранное</Text>
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
                                                    <LuUser size={18} />
                                                    <Text fontSize="sm" fontWeight="500">Личный кабинет</Text>
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
