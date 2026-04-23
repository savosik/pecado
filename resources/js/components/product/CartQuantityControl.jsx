import { memo, useEffect, useState, useRef, useCallback } from 'react';
import { Box, Button, IconButton, Spinner } from '@chakra-ui/react';
import { LuShoppingCart } from 'react-icons/lu';
import { usePage } from '@inertiajs/react';
import { useCartStore } from '@/stores/useCartStore';
import QuantityControl from '@/components/common/QuantityControl';
import { LOGIN_URL } from '@/constants/user';
import { toastInfo } from '@/utils/toast';

const SIZE_MAP = {
    xs: { btn: 'xs', icon: 12 },
    sm: { btn: 'sm', icon: 14 },
    md: { btn: 'md', icon: 16 },
    lg: { btn: 'lg', icon: 18 },
    xl: { btn: 'xl', icon: 20 },
};

/**
 * Контрол количества товара, привязанный к Zustand-стору корзины.
 * Показывает кнопку «В корзину» (qty=0) или pill [−][×][+].
 * Поддерживает spillover toast и индикатор синхронизации.
 *
 * @param {{
 *   productId: number,
 *   disabled?: boolean,
 *   size?: 'xs' | 'sm' | 'md' | 'lg' | 'xl',
 *   fullWidth?: boolean,
 *   variant?: 'full' | 'compact',
 * }} props
 */
function CartQuantityControl({ productId, disabled = false, size = 'md', fullWidth = false, variant = 'full' }) {
    const { auth } = usePage().props;
    const user = auth?.user?.status === 'active' ? auth.user : null;

    const [qty, setQty] = useState(0);
    const [syncing, setSyncing] = useState(false);
    const initRef = useRef(false);
    const pidRef = useRef(Number(productId));

    const s = SIZE_MAP[size] || SIZE_MAP.md;

    // Обновляем pid ref при смене productId
    useEffect(() => {
        pidRef.current = Number(productId);
    }, [productId]);

    useEffect(() => {
        if (!user) return;

        const store = useCartStore.getState();
        if (!initRef.current) {
            store.init(user);
            initRef.current = true;
        }

        // Инициализировать из текущего состояния стора
        setQty(store.getQuantity(productId));
        setSyncing(store.isSyncing(productId));

        const unsub = useCartStore.subscribe((state) => {
            const pid = Number(productId);
            setQty(state.quantities[pid] || 0);
            setSyncing(state.syncing.has(pid));
        });

        return unsub;
    }, [productId, user]);

    // Слушаем cart:clamped и cart:spillover для информативных тостов
    useEffect(() => {
        const onClamped = (e) => {
            const { productId: clampedPid, requested, clamped, instock, preorder } = e.detail;
            if (clampedPid !== pidRef.current) return;

            const excess = requested - clamped;
            const parts = [];
            if (instock > 0) parts.push(`${instock} со склада`);
            if (preorder > 0) parts.push(`${preorder} по предзаказу`);
            if (excess > 0) parts.push(`${excess} недоступно`);

            toastInfo(
                'Количество скорректировано',
                `Запрошено ${requested}. Установлено ${clamped}: ${parts.join(', ')}.`,
            );
        };

        const onSpillover = (e) => {
            const { productId: spillPid, instock, preorder } = e.detail;
            if (spillPid !== pidRef.current) return;

            const parts = [];
            if (instock > 0) parts.push(`${instock} со склада`);
            parts.push(`${preorder} по предзаказу`);

            toastInfo(
                'Распределение товара',
                `Будет ${parts.join(' и ')}.`,
            );
        };

        window.addEventListener('cart:clamped', onClamped);
        window.addEventListener('cart:spillover', onSpillover);
        return () => {
            window.removeEventListener('cart:clamped', onClamped);
            window.removeEventListener('cart:spillover', onSpillover);
        };
    }, []);

    const handleChange = useCallback((value) => {
        useCartStore.getState().setQuantity(productId, value);
    }, [productId]);

    const handleAdd = useCallback((e) => {
        e.preventDefault();
        e.stopPropagation();
        if (!user) {
            window.location.href = LOGIN_URL;
            return;
        }
        useCartStore.getState().setQuantity(productId, 1);
    }, [user, productId]);

    const isCompact = variant === 'compact';

    // Для гостей или если qty = 0 — кнопка «В корзину»
    if (!user || qty <= 0) {
        if (isCompact) {
            return (
                <IconButton
                    aria-label="В корзину"
                    size={s.btn}
                    bg="#9e1b32"
                    color="white"
                    _hover={{ bg: '#7a1527' }}
                    variant="solid"
                    borderRadius="md"
                    onClick={handleAdd}
                    disabled={disabled}
                >
                    <LuShoppingCart size={s.icon} />
                </IconButton>
            );
        }

        return (
            <Button
                size={s.btn}
                bg="#9e1b32"
                color="white"
                _hover={{ bg: '#7a1527' }}
                variant="solid"
                w="100%"
                borderRadius="md"
                fontWeight="600"
                onClick={handleAdd}
                disabled={disabled}
            >
                <LuShoppingCart size={s.icon} />
                В корзину
            </Button>
        );
    }

    // Pill-контрол с индикатором синхронизации
    return (
        <Box
            w={isCompact ? undefined : '100%'}
            display={isCompact ? 'inline-block' : 'block'}
            position="relative"
        >
            <QuantityControl
                value={qty}
                onChange={handleChange}
                min={0}
                size={size}
                fullWidth={isCompact ? false : fullWidth}
            />
            {syncing && (
                <Box
                    position="absolute"
                    top="50%"
                    right="-6"
                    transform="translateY(-50%)"
                >
                    <Spinner size="xs" color="pecado.500" />
                </Box>
            )}
        </Box>
    );
}

export default memo(CartQuantityControl);
