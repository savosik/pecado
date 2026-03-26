import { useEffect } from 'react';
import { Box, Flex, Text } from '@chakra-ui/react';
import { Link, usePage } from '@inertiajs/react';
import { LuHouse, LuGrid2X2, LuShoppingCart, LuHeart, LuUser, LuLogIn } from 'react-icons/lu';
import { useFavoritesStore } from '@/stores/useFavoritesStore';
import { useCartStore } from '@/stores/useCartStore';

/**
 * Бейдж-счётчик (красный кружок с числом).
 */
function NavBadge({ count }) {
    if (!count || count <= 0) return null;
    return (
        <Box
            as="span"
            position="absolute"
            top="-6px"
            right="-4px"
            bg="red.500"
            color="white"
            fontSize="10px"
            fontWeight="600"
            borderRadius="full"
            minW="18px"
            h="18px"
            lineHeight="18px"
            textAlign="center"
            px="4px"
            boxShadow="sm"
            pointerEvents="none"
        >
            {count > 99 ? '99+' : count}
        </Box>
    );
}

/**
 * Один пункт нижней навигации.
 */
function NavItem({ href, onClick, icon: Icon, label, badge, isActive }) {
    const content = (
        <Flex
            direction="column"
            align="center"
            justify="center"
            py="2"
            gap="0.5"
            color={isActive ? 'pecado.500' : 'gray.500'}
            _dark={{ color: isActive ? 'pecado.300' : 'gray.400' }}
            transition="colors 0.2s"
        >
            <Box as="span" position="relative" display="inline-flex">
                <Icon size={22} />
                <NavBadge count={badge} />
            </Box>
            <Text fontSize="10px" lineHeight="1.2" fontWeight="500">
                {label}
            </Text>
        </Flex>
    );

    if (onClick) {
        return (
            <Box
                as="button"
                type="button"
                onClick={onClick}
                w="100%"
                aria-label={label}
                _hover={{ '& svg': { color: 'pecado.500' } }}
            >
                {content}
            </Box>
        );
    }

    return (
        <Link href={href || '/'} style={{ width: '100%' }} aria-label={label}>
            {content}
        </Link>
    );
}

/**
 * Нижнее мобильное меню — фиксированная панель внизу экрана.
 * Показывается только на мобильных (скрывается на lg+).
 */
export default function MobileNav() {
    const { auth, url } = usePage().props;
    const user = auth?.user;
    const currentPath = typeof window !== 'undefined' ? window.location.pathname : (url || '/');

    const favCount = useFavoritesStore((s) => s.ids.size);
    const cartTotalQty = useCartStore((s) => s.getTotalQuantity());

    // Инициализация сторов при наличии пользователя
    useEffect(() => {
        if (!user) return;
        useFavoritesStore.getState().loadOnce(user);
        useCartStore.getState().init(user);
    }, [user?.id]);

    const openCatalog = () => {
        document.dispatchEvent(new Event('catalog:open'));
    };

    // Общие пункты
    const items = [
        { key: 'home', href: '/', icon: LuHouse, label: 'Главная', active: currentPath === '/' },
        { key: 'catalog', onClick: openCatalog, icon: LuGrid2X2, label: 'Каталог' },
    ];

    if (user) {
        items.push(
            { key: 'cart', href: '/cart', icon: LuShoppingCart, label: 'Корзина', badge: cartTotalQty, active: currentPath.startsWith('/cart') },
            { key: 'favorites', href: '/favorites', icon: LuHeart, label: 'Избранное', badge: favCount, active: currentPath.startsWith('/favorites') },
            { key: 'cabinet', href: '/cabinet/dashboard', icon: LuUser, label: 'Кабинет', active: currentPath.startsWith('/cabinet') },
        );
    } else {
        items.push(
            { key: 'login', href: '/login', icon: LuLogIn, label: 'Войти', active: currentPath === '/login' },
        );
    }

    const cols = items.length;

    return (
        <Box
            as="nav"
            position="fixed"
            bottom="0"
            left="0"
            right="0"
            zIndex="45"
            display={{ base: 'block', lg: 'none' }}
            bg="white" _dark={{ bg: "gray.800" }}
            _dark={{ bg: 'gray.900' }}
            borderTop="1px solid"
            borderColor="gray.200" _dark={{ borderColor: "gray.700" }}
            _darkBorderColor="gray.700"
            boxShadow="0 -1px 6px rgba(0,0,0,0.06)"
        >
            <Flex
                maxW="1360px"
                mx="auto"
                css={{
                    display: 'grid',
                    gridTemplateColumns: `repeat(${cols}, 1fr)`,
                }}
            >
                {items.map((item) => (
                    <NavItem
                        key={item.key}
                        href={item.href}
                        onClick={item.onClick}
                        icon={item.icon}
                        label={item.label}
                        badge={item.badge}
                        isActive={item.active}
                    />
                ))}
            </Flex>
        </Box>
    );
}
