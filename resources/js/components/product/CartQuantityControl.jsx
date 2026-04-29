import { memo, useEffect, useState, useRef, useCallback } from 'react';
import { Box, Spinner } from '@chakra-ui/react';
import { usePage } from '@inertiajs/react';
import { useCartStore } from '@/stores/useCartStore';
import QuantityControl from '@/components/common/QuantityControl';
import { Tooltip } from '@/components/ui/tooltip';
import { cartFrameProps, cartFrameState, cartFrameTooltip, splitQty } from '@/utils/cartFrame';

/**
 * Контрол количества товара, привязанный к Zustand-стору корзины.
 * Всегда показывает счётчик [−][N][+]. Вокруг — цветная рамка-индикатор:
 *   серая (qty=0) → зелёная (всё со склада) → градиент → оранжевая (всё в предзаказ).
 *
 * Локально clamp-ит значение до stockQuantity + preorderQuantity, поэтому
 * сервер не присылает spillover/clamped — тосты отключены. Превышение
 * запускает shake-анимацию рамки. Гостям возвращает null.
 *
 * @param {{
 *   productId: number,
 *   stockQuantity?: number,
 *   preorderQuantity?: number,
 *   disabled?: boolean,
 *   size?: 'xs' | 'sm' | 'md' | 'lg' | 'xl',
 *   fullWidth?: boolean,
 *   variant?: 'full' | 'compact',
 * }} props
 */
function CartQuantityControl({
    productId,
    stockQuantity = 0,
    preorderQuantity = 0,
    disabled = false,
    size = 'md',
    fullWidth = false,
    variant = 'full',
}) {
    const { auth } = usePage().props;
    const user = auth?.user && (auth.user.status === 'active' || auth.user.is_admin) ? auth.user : null;

    const [qty, setQty] = useState(0);
    const [syncing, setSyncing] = useState(false);
    const initRef = useRef(false);

    useEffect(() => {
        if (!user) return;

        const store = useCartStore.getState();
        if (!initRef.current) {
            store.init(user);
            initRef.current = true;
        }

        setQty(store.getQuantity(productId));
        setSyncing(store.isSyncing(productId));

        const unsub = useCartStore.subscribe((state) => {
            const pid = Number(productId);
            setQty(state.quantities[pid] || 0);
            setSyncing(state.syncing.has(pid));
        });

        return unsub;
    }, [productId, user]);

    // Pulse-glow при первом добавлении товара пользователем (qty 0 → ≥1).
    const [glowing, setGlowing] = useState(false);
    useEffect(() => {
        if (!glowing) return;
        const t = setTimeout(() => setGlowing(false), 600);
        return () => clearTimeout(t);
    }, [glowing]);

    // Shake-анимация рамки при попытке заказать больше, чем доступно.
    const [shakeKey, setShakeKey] = useState(0);
    const triggerShake = useCallback(() => {
        setShakeKey((k) => k + 1);
    }, []);

    const maxTotal = Math.max(0, Number(stockQuantity || 0)) + Math.max(0, Number(preorderQuantity || 0));

    const handleChange = useCallback((value) => {
        if (qty === 0 && value > 0) {
            setGlowing(true);
        }
        useCartStore.getState().setQuantity(productId, value);
    }, [productId, qty]);

    const handleLimitReached = useCallback((which) => {
        if (which === 'max') triggerShake();
    }, [triggerShake]);

    if (!user) return null;

    const isCompact = variant === 'compact';
    const { instock, preorder } = splitQty(qty, stockQuantity);
    const frame = cartFrameProps(instock, preorder);
    const state = cartFrameState(instock, preorder);
    const glowColor = state === 'preorder' ? 'rgba(251, 146, 60, 0.45)' : 'rgba(34, 197, 94, 0.45)';
    const outerGlow = glowing ? `0 0 0 6px ${glowColor}` : null;
    const frameBoxShadow = outerGlow || frame.boxShadow;
    const tooltipText = cartFrameTooltip(instock, preorder);

    const frameBox = (
        <Box
            key={shakeKey}
            borderWidth="2px"
            rounded="md"
            overflow="hidden"
            display={isCompact ? 'inline-block' : 'block'}
            transition="border-color 220ms ease-out, box-shadow 360ms ease-out, background 220ms ease-out"
            animation={shakeKey ? 'cart-shake 360ms ease-in-out' : undefined}
            {...frame}
            boxShadow={frameBoxShadow}
        >
            <QuantityControl
                value={qty}
                onChange={handleChange}
                onLimitReached={handleLimitReached}
                min={0}
                max={maxTotal > 0 ? maxTotal : undefined}
                size={size}
                fullWidth={isCompact ? false : fullWidth}
                outerBorder={false}
            />
        </Box>
    );

    return (
        <Box
            w={isCompact ? undefined : (fullWidth ? '100%' : undefined)}
            display={isCompact ? 'inline-block' : 'block'}
            position="relative"
        >
            {tooltipText ? (
                <Tooltip content={tooltipText} positioning={{ placement: 'top' }} openDelay={250} closeDelay={0}>
                    {frameBox}
                </Tooltip>
            ) : (
                frameBox
            )}
            {syncing && (
                <Box
                    position="absolute"
                    top="50%"
                    right="-6"
                    transform="translateY(-50%)"
                    pointerEvents="none"
                    aria-hidden="true"
                >
                    <Spinner size="xs" color="pecado.500" />
                </Box>
            )}
            {disabled && (
                <Box
                    position="absolute"
                    inset="0"
                    bg="whiteAlpha.600"
                    _dark={{ bg: 'blackAlpha.600' }}
                    rounded="md"
                />
            )}
        </Box>
    );
}

export default memo(CartQuantityControl);
