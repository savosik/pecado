import { Box, Flex, Text, Button } from '@chakra-ui/react';
import { Link, usePage } from '@inertiajs/react';
import { LuShoppingBag, LuCreditCard } from 'react-icons/lu';

/**
 * Итоговая карточка корзины: суммы, экономия, кнопки действий.
 */
export default function CartSummary({ cartDetails, hasItems }) {
    const { currency } = usePage().props;
    const currencySymbol = currency?.symbol ?? '₽';

    if (!hasItems) return null;

    const totalRegular = Number(cartDetails?.total_amount_regular ?? 0);
    const totalDiscounted = Number(cartDetails?.total_amount_discounted ?? 0);
    const hasDiscount = totalRegular > 0 && totalRegular !== totalDiscounted;
    const savings = hasDiscount ? Math.max(0, totalRegular - totalDiscounted) : 0;

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
                {/* Суммы */}
                <Box>
                    <Text fontSize="xs" color="fg.muted" mb="0.5">
                        Итого ({currencySymbol})
                    </Text>
                    {hasDiscount && (
                        <Text fontSize="xs" color="fg.muted" textDecoration="line-through" lineHeight="1">
                            {totalRegular.toLocaleString('ru-RU')}
                        </Text>
                    )}
                    <Text fontSize="2xl" fontWeight="bold" lineHeight="1.2">
                        {totalDiscounted.toLocaleString('ru-RU')}
                    </Text>
                    {hasDiscount && (
                        <Text fontSize="xs" color="green.600">
                            Экономия {savings.toLocaleString('ru-RU')} {currencySymbol}
                        </Text>
                    )}
                </Box>

                {/* Кнопки */}
                <Flex gap="2" flexShrink={0} direction={{ base: 'column', sm: 'row' }}>
                    <Button
                        asChild
                        variant="outline"
                        size="md"
                    >
                        <Link href="/products">
                            <LuShoppingBag size={16} />
                            Продолжить покупки
                        </Link>
                    </Button>
                    <Button
                        asChild
                        colorPalette="pecado"
                        size="md"
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
