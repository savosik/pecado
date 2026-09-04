import { memo, useEffect, useRef, useState } from 'react';
import { Box, Spinner } from '@chakra-ui/react';
import { usePage } from '@inertiajs/react';
import QuantityControl from '@/components/common/QuantityControl';
import { useCartStore } from '@/stores/useCartStore';

/**
 * Счётчик количества уценённой партии в корзине.
 *
 * Полный аналог CartQuantityControl, но привязан к product_defect_id: в корзину
 * ложится конкретная партия некондиции, а не товар. Рамка всегда фиолетовая —
 * тот же цвет, что у статуса и цены уценки, чтобы клиент видел, что жмёт
 * «плюс» именно на уценке.
 *
 * @param {{
 *   defect: { id: number, available_quantity?: number },
 *   size?: 'xs' | 'sm' | 'md' | 'lg' | 'xl',
 *   fullWidth?: boolean,
 * }} props
 */
function DefectQuantityControl({ defect, size = 'sm', fullWidth = false }) {
    const { auth } = usePage().props;
    const user = auth?.user && (auth.user.status === 'active' || auth.user.is_staff) ? auth.user : null;

    const defectId = Number(defect?.id) || null;

    const [qty, setQty] = useState(0);
    const [syncing, setSyncing] = useState(false);
    const initRef = useRef(false);

    useEffect(() => {
        if (!user || !defectId) return undefined;

        const store = useCartStore.getState();
        if (!initRef.current) {
            store.init(user);
            initRef.current = true;
        }

        setQty(store.getDefectQuantity(defectId));
        setSyncing(store.isSyncingDefect(defectId));

        return useCartStore.subscribe((state) => {
            setQty(state.defectQuantities[defectId] || 0);
            setSyncing(state.syncingDefects.has(defectId));
        });
    }, [defectId, user]);

    if (!user || !defectId) return null;

    // Потолок — свободный остаток партии + уже лежащее в корзине (иначе своя же
    // позиция «съела» бы остаток и потолок оказался бы ниже текущего qty).
    const max = Math.max(0, Number(defect.available_quantity || 0)) + qty;

    return (
        <Box position="relative" w={fullWidth ? '100%' : undefined} display={fullWidth ? 'block' : 'inline-block'}>
            <Box
                borderWidth="2px"
                borderColor="purple.400"
                _dark={{ borderColor: 'purple.300' }}
                rounded="md"
                overflow="hidden"
            >
                <QuantityControl
                    value={qty}
                    onChange={(value) => useCartStore.getState().setDefectQuantity(defectId, value)}
                    min={0}
                    max={max > 0 ? max : undefined}
                    size={size}
                    fullWidth={fullWidth}
                    outerBorder={false}
                />
            </Box>
            {syncing && (
                <Box position="absolute" top="50%" right="-6" transform="translateY(-50%)" pointerEvents="none" aria-hidden="true">
                    <Spinner size="xs" color="pecado.500" />
                </Box>
            )}
        </Box>
    );
}

export default memo(DefectQuantityControl);
