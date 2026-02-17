import { useState } from 'react';
import { Box, Flex, Heading, Text, Badge, Button } from '@chakra-ui/react';
import { LuSettings2 } from 'react-icons/lu';
import { pluralize } from '@/utils/pluralize';
import CartManagerDialog from './CartManagerDialog';

/**
 * Заголовок страницы корзины.
 *
 * @param {{
 *   cart: { id: number, name: string, is_active: boolean },
 *   cartDetails: { total_quantity: number, instock_quantity: number, preorder_quantity: number },
 *   userCarts: Array<{ id: number, name: string, is_active: boolean, items_count: number }>,
 * }} props
 */
export default function CartHeader({ cart, cartDetails, userCarts = [] }) {
    const [managerOpen, setManagerOpen] = useState(false);

    const name = cart?.name;
    const showName = name && name !== 'Корзина';

    const totalQty = cartDetails?.total_quantity ?? 0;
    const instockQty = cartDetails?.instock_quantity ?? 0;
    const preorderQty = cartDetails?.preorder_quantity ?? 0;

    return (
        <Box mb="6">
            <Flex
                direction={{ base: 'column', md: 'row' }}
                justify="space-between"
                align={{ base: 'flex-start', md: 'center' }}
                gap="3"
            >
                <Box>
                    <Heading as="h1" size={{ base: 'xl', md: '3xl' }} fontWeight="bold" color="fg">
                        {showName ? `Корзина «${name}»` : 'Корзина'}
                    </Heading>
                    <Text mt="1" color="fg.muted" fontSize={{ base: 'sm', md: 'md' }}>
                        {totalQty} {pluralize(totalQty, 'товар', 'товара', 'товаров')}
                    </Text>
                </Box>

                {/* Cart management button */}
                <Button
                    size="sm"
                    variant="outline"
                    colorPalette="gray"
                    onClick={() => setManagerOpen(true)}
                    w={{ base: '100%', md: 'auto' }}
                >
                    <LuSettings2 />
                    Управление корзинами
                </Button>
            </Flex>

            {/* Badges */}
            <Flex gap="2" mt="3" wrap="wrap">
                {instockQty > 0 && (
                    <Badge colorPalette="green" variant="subtle" px="2" py="0.5" fontSize="xs">
                        В наличии: {instockQty}
                    </Badge>
                )}
                {preorderQty > 0 && (
                    <Badge colorPalette="orange" variant="subtle" px="2" py="0.5" fontSize="xs">
                        Предзаказ: {preorderQty}
                    </Badge>
                )}
            </Flex>

            <CartManagerDialog
                open={managerOpen}
                onClose={() => setManagerOpen(false)}
                carts={userCarts}
            />
        </Box>
    );
}
