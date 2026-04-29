import { useMemo } from 'react';
import { Box, Flex, Text, Button } from '@chakra-ui/react';
import { Link, usePage } from '@inertiajs/react';
import { LuShoppingBag, LuCreditCard } from 'react-icons/lu';
import { useCartStore } from '@/stores/useCartStore';

/**
 * Итоговая карточка корзины.
 *
 * Считает суммы оптимистично от стора + цен из cartDetails.items
 * (цены не меняются между reload, qty актуальный — мгновенный апдейт).
 */
export default function CartSummary({ cartDetails, hasItems }) {
    const { currency } = usePage().props;
    const currencySymbol = currency?.symbol ?? '₽';

    const quantities = useCartStore((s) => s.quantities);

    const totals = useMemo(() => {
        const items = cartDetails?.items ?? [];
        // Свернём цены товара в одну запись по pid
        const priceByPid = new Map();
        for (const it of items) {
            const pid = Number(it.product?.id);
            if (!pid) continue;
            const priceR = Number(it.price_regular ?? it.price ?? 0);
            const priceD = Number(it.price_discounted ?? it.price ?? 0);
            const cur = priceByPid.get(pid);
            // Приоритет — instock, иначе первый увиденный
            if (!cur || it.item_type === 'instock') {
                priceByPid.set(pid, { priceR, priceD });
            }
        }

        let totalRegular = 0;
        let totalDiscounted = 0;
        for (const [pidStr, qty] of Object.entries(quantities)) {
            if (qty <= 0) continue;
            const p = priceByPid.get(Number(pidStr));
            if (!p) continue; // pid отсутствует в текущем cartDetails
            totalRegular += p.priceR * qty;
            totalDiscounted += p.priceD * qty;
        }

        return { totalRegular, totalDiscounted };
    }, [cartDetails?.items, quantities]);

    if (!hasItems) return null;

    const hasDiscount = totals.totalRegular > 0 && totals.totalRegular !== totals.totalDiscounted;
    const savings = hasDiscount ? Math.max(0, totals.totalRegular - totals.totalDiscounted) : 0;

    return (
        <Box
            p={{ base: '4', md: '5' }}
            bg="bg"
            borderWidth="1px"
            borderColor="border"
            rounded="lg"
            shadow="sm"
        >
            <Flex
                direction={{ base: 'column', md: 'row' }}
                justify="space-between"
                align={{ base: 'stretch', md: 'center' }}
                gap="4"
            >
                <Box>
                    <Text fontSize="xs" color="fg.muted" mb="0.5">
                        Итого ({currencySymbol})
                    </Text>
                    {hasDiscount && (
                        <Text fontSize="xs" color="fg.muted" textDecoration="line-through" lineHeight="1">
                            {totals.totalRegular.toLocaleString('ru-RU')}
                        </Text>
                    )}
                    <Text fontSize="2xl" fontWeight="bold" lineHeight="1.2">
                        {totals.totalDiscounted.toLocaleString('ru-RU')}
                    </Text>
                    {hasDiscount && (
                        <Text fontSize="xs" color="green.600">
                            Экономия {savings.toLocaleString('ru-RU')} {currencySymbol}
                        </Text>
                    )}
                </Box>

                <Flex gap="2" flexShrink={0} direction={{ base: 'column', sm: 'row' }}>
                    <Button asChild variant="outline" size="md">
                        <Link href="/products">
                            <LuShoppingBag size={16} />
                            Продолжить покупки
                        </Link>
                    </Button>
                    <Button asChild colorPalette="pecado" size="md">
                        <Link href="/checkout">
                            <LuCreditCard size={16} />
                            Оформить заказ
                        </Link>
                    </Button>
                </Flex>
            </Flex>
        </Box>
    );
}
