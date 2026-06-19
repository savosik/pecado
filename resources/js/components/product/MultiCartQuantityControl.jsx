import { memo, useCallback, useEffect, useRef, useState } from 'react';
import { Box, Flex, HStack, Text, Spinner, IconButton } from '@chakra-ui/react';
import { LuChevronDown, LuChevronUp } from 'react-icons/lu';
import { usePage } from '@inertiajs/react';
import { Tooltip } from '@/components/ui/tooltip';
import QuantityControl from '@/components/common/QuantityControl';
import CartQuantityControl from './CartQuantityControl';
import { cartFrameProps, splitQty } from '@/utils/cartFrame';

const DEBOUNCE_MS = 700;

/**
 * Мульти-корзинный контрол добавления товара.
 *
 * Сверху — обычный счётчик активной корзины (тот же CartQuantityControl,
 * что и везде, синхронизирован с глобальным стором). Если у пользователя
 * больше одной корзины, рядом появляется кнопка-«шеврон», раскрывающая
 * выпадающую панель со счётчиками остальных корзин — товар можно сразу
 * набросать в несколько корзин.
 *
 * Активная корзина управляется через useCartStore; неактивные — напрямую
 * через POST /api/cart/carts/{cart}/set-product-quantity (этот компонент
 * хранит их количества локально и шлёт debounced-запросы).
 *
 * @param {{
 *   productId: number,
 *   stockQuantity?: number,
 *   preorderQuantity?: number,
 *   disabled?: boolean,
 *   size?: 'xs' | 'sm' | 'md' | 'lg' | 'xl',
 *   fullWidth?: boolean,
 * }} props
 */
function MultiCartQuantityControl({
    productId,
    stockQuantity = 0,
    preorderQuantity = 0,
    disabled = false,
    size = 'md',
    fullWidth = false,
}) {
    const { auth } = usePage().props;
    const user = auth?.user && (auth.user.status === 'active' || auth.user.is_admin) ? auth.user : null;

    const [open, setOpen] = useState(false);
    // Список корзин с количеством этого товара в каждой.
    const [carts, setCarts] = useState([]);
    const [maxTotal, setMaxTotal] = useState(
        Math.max(0, Number(stockQuantity || 0)) + Math.max(0, Number(preorderQuantity || 0)),
    );

    const wrapperRef = useRef(null);

    const fetchCarts = useCallback(async () => {
        if (!user || !productId) return;
        try {
            const { data } = await window.axios.get(`/api/cart/product-quantities/${productId}`);
            if (data && Array.isArray(data.carts)) {
                setCarts(data.carts);
                if (typeof data.max_total === 'number') setMaxTotal(data.max_total);
            }
        } catch {
            // ignore — контрол просто останется без выпадающей панели
        }
    }, [user, productId]);

    // Первичная загрузка — чтобы знать число корзин (видимость шеврона).
    useEffect(() => {
        fetchCarts();
    }, [fetchCarts]);

    // Закрытие по клику вне контрола.
    useEffect(() => {
        if (!open) return undefined;
        const onPointerDown = (e) => {
            // Кнопки счётчика пересоздаются при переходе количества 0↔1
            // (появляется/исчезает обёртка-подсказка). К моменту, когда этот
            // обработчик отрабатывает, старый узел уже оторван от DOM, и
            // contains() ложно вернул бы «снаружи» → панель бы захлопнулась.
            // Игнорируем такие оторванные цели.
            if (e.target && e.target.isConnected === false) return;
            if (wrapperRef.current && !wrapperRef.current.contains(e.target)) {
                setOpen(false);
            }
        };
        const onKey = (e) => {
            if (e.key === 'Escape') setOpen(false);
        };
        document.addEventListener('pointerdown', onPointerDown);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('pointerdown', onPointerDown);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    const toggle = useCallback(() => {
        setOpen((prev) => {
            const next = !prev;
            if (next) fetchCarts(); // освежаем количества при раскрытии
            return next;
        });
    }, [fetchCarts]);

    // Локальное обновление количества конкретной корзины (после ответа сервера).
    const applyCartUpdate = useCallback((cartId, patch) => {
        setCarts((prev) => prev.map((c) => (c.id === cartId ? { ...c, ...patch } : c)));
    }, []);

    if (!user) return null;

    const otherCarts = carts.filter((c) => !c.is_active);
    const hasMultiple = carts.length > 1;
    // Счётчики в выпадающей панели — на размер компактнее основного,
    // чтобы названию корзины оставалось место.
    const rowSize = { xl: 'lg', lg: 'md', md: 'sm', sm: 'xs', xs: 'xs' }[size] || 'sm';

    return (
        <Box ref={wrapperRef} position="relative" w={fullWidth ? '100%' : undefined}>
            <HStack gap="1.5" align="stretch">
                <Box flex="1" minW="0">
                    <CartQuantityControl
                        productId={productId}
                        stockQuantity={stockQuantity}
                        preorderQuantity={preorderQuantity}
                        disabled={disabled}
                        size={size}
                        fullWidth={fullWidth}
                    />
                </Box>

                {hasMultiple && (
                    <Tooltip
                        content={open ? 'Скрыть другие корзины' : 'Добавить в другие корзины'}
                        positioning={{ placement: 'top' }}
                        openDelay={300}
                        closeDelay={0}
                    >
                        <IconButton
                            aria-label={open ? 'Скрыть другие корзины' : 'Добавить в другие корзины'}
                            variant="outline"
                            size={size}
                            flexShrink="0"
                            onClick={toggle}
                            aria-expanded={open}
                        >
                            {open ? <LuChevronUp /> : <LuChevronDown />}
                        </IconButton>
                    </Tooltip>
                )}
            </HStack>

            {open && otherCarts.length > 0 && (
                <Box
                    position="absolute"
                    top="100%"
                    left="0"
                    mt="2"
                    zIndex="20"
                    w="20rem"
                    maxW="calc(100vw - 1.5rem)"
                    bg="bg"
                    _dark={{ bg: 'gray.800', borderColor: 'gray.700' }}
                    borderWidth="1px"
                    borderColor="border"
                    rounded="lg"
                    boxShadow="lg"
                    p="2"
                >
                    <Text fontSize="xs" fontWeight="600" color="fg.muted" px="1" pb="1.5">
                        Другие корзины
                    </Text>
                    <Box spaceY="1.5">
                        {otherCarts.map((cart) => (
                            <PerCartRow
                                key={cart.id}
                                cart={cart}
                                productId={productId}
                                stockQuantity={stockQuantity}
                                maxTotal={maxTotal}
                                size={rowSize}
                                disabled={disabled}
                                onUpdate={applyCartUpdate}
                            />
                        ))}
                    </Box>
                </Box>
            )}
        </Box>
    );
}

/**
 * Строка одной (неактивной) корзины в выпадающей панели: название + счётчик.
 * Хранит количество локально, синхронизирует с сервером debounced-запросом.
 */
function PerCartRow({ cart, productId, stockQuantity, maxTotal, size, disabled, onUpdate }) {
    const [qty, setQty] = useState(Number(cart.quantity || 0));
    const [split, setSplit] = useState({
        instock: Number(cart.instock || 0),
        preorder: Number(cart.preorder || 0),
    });
    const [syncing, setSyncing] = useState(false);
    const timerRef = useRef(null);

    // Если родитель освежил данные корзины (повторный fetch при раскрытии) —
    // подхватываем, но только когда нет незавершённого debounce/запроса.
    useEffect(() => {
        if (timerRef.current || syncing) return;
        setQty(Number(cart.quantity || 0));
        setSplit({ instock: Number(cart.instock || 0), preorder: Number(cart.preorder || 0) });
    }, [cart.quantity, cart.instock, cart.preorder, syncing]);

    useEffect(() => () => {
        if (timerRef.current) clearTimeout(timerRef.current);
    }, []);

    const handleChange = useCallback((value) => {
        const next = Math.max(0, Math.floor(value));
        setQty(next);
        // Оптимистичный split до ответа сервера.
        setSplit(splitQty(next, stockQuantity));

        if (timerRef.current) clearTimeout(timerRef.current);
        timerRef.current = setTimeout(async () => {
            timerRef.current = null;
            setSyncing(true);
            try {
                const { data } = await window.axios.post(
                    `/api/cart/carts/${cart.id}/set-product-quantity`,
                    { product_id: productId, quantity: next },
                );
                const inS = Math.max(0, Number(data?.instock || 0));
                const pre = Math.max(0, Number(data?.preorder || 0));
                const clamped = Number(data?.clamped ?? next);
                // Не перетираем, если пользователь успел наклацать новое значение.
                if (timerRef.current === null) {
                    setQty(clamped);
                    setSplit({ instock: inS, preorder: pre });
                }
                onUpdate(cart.id, { quantity: clamped, instock: inS, preorder: pre });
            } catch {
                // ignore — следующее изменение догонит сервер
            } finally {
                setSyncing(false);
            }
        }, DEBOUNCE_MS);
    }, [cart.id, productId, stockQuantity, onUpdate]);

    const frame = cartFrameProps(split.instock, split.preorder);

    return (
        <Box
            p="2"
            rounded="md"
            borderWidth="1px"
            borderColor="border"
            _dark={{ borderColor: 'gray.700' }}
        >
            <Flex align="center" gap="1.5" mb="1">
                <Text fontSize="xs" color="fg.muted" truncate flex="1" minW="0">
                    {cart.name}
                </Text>
                {syncing && <Spinner size="xs" color="pecado.500" flexShrink="0" />}
            </Flex>
            <Box
                borderWidth="2px"
                rounded="md"
                overflow="hidden"
                transition="border-color 220ms ease-out, background 220ms ease-out"
                {...frame}
            >
                <QuantityControl
                    value={qty}
                    onChange={handleChange}
                    min={0}
                    max={maxTotal > 0 ? maxTotal : undefined}
                    size={size}
                    fullWidth
                    disabled={disabled}
                    outerBorder={false}
                />
            </Box>
        </Box>
    );
}

export default memo(MultiCartQuantityControl);
