import { useEffect, useState, useRef } from 'react';
import { Box, Button } from '@chakra-ui/react';
import { LuShoppingCart } from 'react-icons/lu';
import { usePage } from '@inertiajs/react';
import { useCartStore } from '@/stores/useCartStore';
import QuantityControl from '@/components/common/QuantityControl';
import { LOGIN_URL } from '@/constants/user';

/**
 * Контрол количества товара, привязанный к Zustand-стору корзины.
 * Показывает кнопку «В корзину» (qty=0) или pill [−][×][+].
 *
 * @param {{ productId: number, disabled?: boolean }} props
 */
export default function CartQuantityControl({ productId, disabled = false }) {
    const { auth } = usePage().props;
    const user = auth?.user || null;

    const [qty, setQty] = useState(0);
    const initRef = useRef(false);

    useEffect(() => {
        if (!user) return;
        const store = useCartStore.getState();
        if (!initRef.current) {
            store.init(user);
            initRef.current = true;
        }
        setQty(store.getQuantity(productId));

        const unsub = useCartStore.subscribe((state) => {
            const item = state.items[Number(productId)];
            setQty(item ? item.quantity : 0);
        });
        return unsub;
    }, [productId, user]);

    const handleChange = (value) => {
        useCartStore.getState().setQuantity(productId, value);
    };

    const handleAdd = (e) => {
        e.preventDefault();
        e.stopPropagation();
        if (!user) {
            // Для гостей — перенаправляем на логин (или показываем уведомление)
            window.location.href = LOGIN_URL;
            return;
        }
        useCartStore.getState().setQuantity(productId, 1);
    };

    // Для гостей или если qty = 0 — кнопка «В корзину»
    if (!user || qty <= 0) {
        return (
            <Button
                size="sm"
                colorPalette="pink"
                variant="solid"
                w="100%"
                borderRadius="md"
                fontWeight="600"
                onClick={handleAdd}
                disabled={disabled}
            >
                <LuShoppingCart size={14} />
                В корзину
            </Button>
        );
    }

    // Pill-контрол
    return (
        <Box w="100%">
            <QuantityControl
                value={qty}
                onChange={handleChange}
                min={0}
                size="sm"
            />
        </Box>
    );
}
