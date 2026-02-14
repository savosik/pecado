import { useEffect, useMemo, useRef, useState } from 'react';
import { Box, Flex, Text } from '@chakra-ui/react';
import { usePage } from '@inertiajs/react';
import CartQuantityControl from './CartQuantityControl';

/**
 * MobileActionsBar — sticky-панель внизу экрана для мобильных.
 * Появляется когда основная секция (data-sticky-anchor) уходит из видимости.
 */
export default function MobileActionsBar({ productId, name, price, isPreorder = false, inStock = true }) {
    const { currency } = usePage().props;
    const currencySymbol = currency?.symbol || '₽';
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        const anchor = document.querySelector('[data-sticky-anchor="true"]');
        if (!anchor) { setVisible(false); return; }
        const obs = new IntersectionObserver((entries) => {
            const e = entries[0];
            setVisible(!(e.isIntersecting && e.intersectionRatio > 0.1));
        }, { threshold: [0, 0.1, 1] });
        obs.observe(anchor);
        return () => obs.disconnect();
    }, []);

    const formatPrice = useMemo(() => {
        return (value) => {
            if (value === null || value === undefined) return '';
            return Number(value).toLocaleString('ru-RU') + '\u00A0' + currencySymbol;
        };
    }, [currencySymbol]);

    return (
        <Box
            position="fixed" bottom="0" left="0" right="0"
            zIndex="40"
            borderTopWidth="1px" borderColor="gray.200"
            bg="white/95" _dark={{ bg: 'gray.900/95', borderColor: 'gray.700' }}
            css={{ backdropFilter: 'blur(8px)' }}
            display={{ md: 'none' }}
            transform={visible ? 'translateY(0)' : 'translateY(100%)'}
            transition="transform 0.3s"
            aria-hidden={!visible}
        >
            <Flex px="4" py="3" align="center" gap="3">
                <Box minW="0" flex="1">
                    <Text fontSize="xs" color="gray.500" truncate>{name}</Text>
                    <Text fontSize="lg" fontWeight="600">{formatPrice(price)}</Text>
                </Box>
                {(inStock || isPreorder) && price > 0 && (
                    <Box flexShrink={0}>
                        <CartQuantityControl productId={productId} />
                    </Box>
                )}
            </Flex>
        </Box>
    );
}
