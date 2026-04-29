import { useState } from 'react';
import { Box, Flex, Heading, Text, Button } from '@chakra-ui/react';
import { LuSettings2 } from 'react-icons/lu';
import { pluralize } from '@/utils/pluralize';
import { useCartStore } from '@/stores/useCartStore';
import CartManagerDialog from './CartManagerDialog';

/**
 * Заголовок страницы корзины — компактная одна строка.
 * Счётчик «N товаров» считается оптимистично от стора, без серверного reload.
 */
export default function CartHeader({ cart, userCarts = [] }) {
    const [managerOpen, setManagerOpen] = useState(false);

    const name = cart?.name;
    const showName = name && name !== 'Корзина';
    const totalQty = useCartStore((s) => Object.values(s.quantities).reduce((a, b) => a + b, 0));

    return (
        <Box mb="2">
            <Flex
                direction={{ base: 'column', md: 'row' }}
                justify="space-between"
                align={{ base: 'flex-start', md: 'center' }}
                gap="2"
            >
                <Flex align="baseline" gap="3" wrap="wrap">
                    <Heading as="h1" size={{ base: 'xl', md: '3xl' }} fontWeight="bold" color="fg">
                        {showName ? `Корзина «${name}»` : 'Корзина'}
                    </Heading>
                    <Text fontSize="sm" color="fg.muted">
                        {totalQty} {pluralize(totalQty, 'товар', 'товара', 'товаров')}
                    </Text>
                </Flex>

                <Button
                    size="sm"
                    variant="ghost"
                    colorPalette="gray"
                    onClick={() => setManagerOpen(true)}
                    flexShrink={0}
                >
                    <LuSettings2 />
                    Управление корзинами
                </Button>
            </Flex>

            <CartManagerDialog
                open={managerOpen}
                onClose={() => setManagerOpen(false)}
                carts={userCarts}
            />
        </Box>
    );
}
