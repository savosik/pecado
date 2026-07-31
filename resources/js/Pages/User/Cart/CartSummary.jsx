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
export default function CartSummary({ cartDetails, hasItems, defectTotals = { qty: 0, amount: 0 }, promoAmount = 0 }) {
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

        // Уценка (партии) не живёт в store-агрегации — добавляем её сумму
        // напрямую. Скидки у уценки нет, поэтому regular и discounted равны.
        totalRegular += defectTotals.amount;
        totalDiscounted += defectTotals.amount;

        return { totalRegular, totalDiscounted };
    }, [cartDetails?.items, quantities, defectTotals]);

    if (!hasItems) return null;

    const hasDiscount = totals.totalRegular > 0 && totals.totalRegular !== totals.totalDiscounted;
    const savings = hasDiscount ? Math.max(0, totals.totalRegular - totals.totalDiscounted) : 0;

    return (
        <Box
            px={{ base: '3', md: '5' }}
            py={{ base: '3', md: '5' }}
            bg="bg"
            borderTopWidth="1px"
            borderBottomWidth={{ base: '1px', md: '1px' }}
            borderLeftWidth={{ base: '0', md: '1px' }}
            borderRightWidth={{ base: '0', md: '1px' }}
            borderStyle="solid"
            borderColor="border.muted"
            rounded={{ base: 'none', md: 'lg' }}
            shadow={{ base: 'none', md: 'sm' }}
            position={{ base: 'sticky', md: 'static' }}
            bottom={{ base: '56px', md: 'auto' }}
            zIndex={{ base: '40', md: 'auto' }}
        >
            <Flex
                direction="row"
                justify="space-between"
                align="center"
                gap="3"
            >
                {/* Итого + сумма */}
                <Box minW="0" flex="1">
                    <Flex align="baseline" gap="2" wrap="wrap" rowGap="0">
                        <Text fontSize="xs" color="fg.muted" lineHeight="1">
                            Итого
                        </Text>
                        {hasDiscount && (
                            <Text fontSize="xs" color="fg.muted" textDecoration="line-through" lineHeight="1">
                                {totals.totalRegular.toLocaleString('ru-RU')}
                            </Text>
                        )}
                    </Flex>
                    <Text fontSize={{ base: 'xl', md: '2xl' }} fontWeight="bold" lineHeight="1.2" mt="0.5">
                        {totals.totalDiscounted.toLocaleString('ru-RU')} {currencySymbol}
                    </Text>
                    {hasDiscount && (
                        <Text fontSize="2xs" color="green.600" lineHeight="1.2" mt="0.5">
                            Экономия {savings.toLocaleString('ru-RU')} {currencySymbol}
                        </Text>
                    )}
                    {/* Промо уезжает отдельным заказом, поэтому и в итоге стоит
                        отдельной строкой: смешанная сумма ввела бы в заблуждение */}
                    {promoAmount > 0 && (
                        <Text fontSize="2xs" color="fg.muted" lineHeight="1.2" mt="0.5">
                            Промо-позиции (отдельный заказ): {promoAmount.toLocaleString('ru-RU')} {currencySymbol}
                        </Text>
                    )}
                </Box>

                {/* Кнопки действий */}
                <Flex gap="2" flexShrink={0} direction={{ base: 'row', md: 'row' }} align="center">
                    <Button
                        asChild
                        variant="outline"
                        size={{ base: 'sm', md: 'md' }}
                        display={{ base: 'none', sm: 'inline-flex' }}
                    >
                        <Link href="/products">
                            <LuShoppingBag size={16} />
                            Продолжить покупки
                        </Link>
                    </Button>
                    <Button
                        asChild
                        colorPalette="pecado"
                        size={{ base: 'md', md: 'md' }}
                    >
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
