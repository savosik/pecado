import { useState, useCallback } from 'react';
import {
    Dialog, Portal, Button, Box, Text, VStack, CloseButton,
} from '@chakra-ui/react';
import { LuArrowRight } from 'react-icons/lu';
import { pluralize } from '@/utils/pluralize';

/**
 * Диалог выбора корзины для переноса товаров.
 *
 * @param {{
 *   open: boolean,
 *   onClose: () => void,
 *   onConfirm: (targetCartId: number) => void,
 *   carts: Array<{ id: number, name: string, is_active: boolean, items_count: number }>,
 *   currentCartId: number,
 *   selectedCount: number,
 * }} props
 */
export default function MoveToCartDialog({
    open,
    onClose,
    onConfirm,
    carts = [],
    currentCartId,
    selectedCount = 0,
}) {
    const [targetId, setTargetId] = useState(null);

    const otherCarts = carts.filter((c) => c.id !== currentCartId);

    const handleConfirm = useCallback(() => {
        if (targetId) {
            onConfirm(targetId);
            setTargetId(null);
        }
    }, [targetId, onConfirm]);

    const handleClose = useCallback(() => {
        setTargetId(null);
        onClose();
    }, [onClose]);

    return (
        <Dialog.Root open={open} onOpenChange={({ open: isOpen }) => !isOpen && handleClose()}>
            <Portal>
                <Dialog.Backdrop />
                <Dialog.Positioner>
                    <Dialog.Content maxW="420px">
                        <Dialog.Header>
                            <Dialog.Title>Перенести в другую корзину</Dialog.Title>
                            <Dialog.CloseTrigger asChild>
                                <CloseButton size="sm" />
                            </Dialog.CloseTrigger>
                        </Dialog.Header>
                        <Dialog.Body>
                            <Text fontSize="sm" color="fg.muted" mb="4">
                                Выберите корзину, в которую перенести{' '}
                                {selectedCount} {pluralize(selectedCount, 'товар', 'товара', 'товаров')}:
                            </Text>
                            <VStack gap="2" align="stretch">
                                {otherCarts.map((cart) => (
                                    <Box
                                        key={cart.id}
                                        as="button"
                                        type="button"
                                        onClick={() => setTargetId(cart.id)}
                                        p="3"
                                        borderRadius="lg"
                                        border="1px solid"
                                        borderColor={targetId === cart.id ? 'pecado.500' : 'border'}
                                        bg={targetId === cart.id ? 'pecado.50' : 'transparent'}
                                        _dark={{
                                            borderColor: targetId === cart.id ? 'pecado.400' : 'border',
                                            bg: targetId === cart.id ? 'pecado.900/20' : 'transparent',
                                        }}
                                        _hover={{ borderColor: 'pecado.300' }}
                                        textAlign="left"
                                        cursor="pointer"
                                        transition="all 0.15s"
                                    >
                                        <Text fontWeight="medium" fontSize="sm">
                                            {cart.name}
                                        </Text>
                                        <Text fontSize="xs" color="fg.muted">
                                            {cart.items_count} шт.
                                        </Text>
                                    </Box>
                                ))}
                                {otherCarts.length === 0 && (
                                    <Text fontSize="sm" color="fg.muted" textAlign="center" py="4">
                                        Нет других корзин. Создайте новую корзину в управлении корзинами.
                                    </Text>
                                )}
                            </VStack>
                        </Dialog.Body>
                        <Dialog.Footer>
                            <Button variant="outline" size="sm" onClick={handleClose}>
                                Отмена
                            </Button>
                            <Button
                                colorPalette="pecado"
                                size="sm"
                                onClick={handleConfirm}
                                disabled={!targetId}
                            >
                                <LuArrowRight size={14} />
                                Перенести
                            </Button>
                        </Dialog.Footer>
                    </Dialog.Content>
                </Dialog.Positioner>
            </Portal>
        </Dialog.Root>
    );
}
