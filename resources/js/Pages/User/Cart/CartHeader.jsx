import { useState } from 'react';
import { Box, Flex, Heading, Text, Button, IconButton } from '@chakra-ui/react';
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
    // Считаем как бейдж корзины в навигации (обычные + уценка), иначе
    // клиент видит два разных числа для одной корзины.
    const totalQty = useCartStore((s) =>
        Object.values(s.quantities).reduce((a, b) => a + b, 0)
        + Object.values(s.defectQuantities).reduce((a, b) => a + b, 0));

    return (
        <Box mb="2">
            <Flex
                direction="row"
                justify="space-between"
                align="center"
                gap="2"
            >
                <Flex align="baseline" gap="2" wrap="wrap" minW="0">
                    <Heading as="h1" size={{ base: 'xl', md: '3xl' }} fontWeight="bold" color="fg">
                        {showName ? `Корзина «${name}»` : 'Корзина'}
                    </Heading>
                    <Text fontSize="sm" color="fg.muted">
                        {totalQty} {pluralize(totalQty, 'товар', 'товара', 'товаров')}
                    </Text>
                </Flex>

                {/* На base — иконка-кнопка справа от заголовка, на md+ — кнопка с текстом */}
                <IconButton
                    aria-label="Управление корзинами"
                    title="Управление корзинами"
                    size="sm"
                    variant="ghost"
                    colorPalette="gray"
                    display={{ base: 'inline-flex', md: 'none' }}
                    onClick={() => setManagerOpen(true)}
                >
                    <LuSettings2 />
                </IconButton>
                <Button
                    size="sm"
                    variant="ghost"
                    colorPalette="gray"
                    display={{ base: 'none', md: 'inline-flex' }}
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
