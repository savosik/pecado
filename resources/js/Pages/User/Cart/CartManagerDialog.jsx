import { useState, useCallback } from 'react';
import { router } from '@inertiajs/react';
import {
    Dialog, Portal, Button, Box, Flex, Text, Input, IconButton,
    VStack, HStack, Badge, Separator, CloseButton,
} from '@chakra-ui/react';
import {
    LuPlus, LuPencil, LuTrash2, LuCheck, LuX,
} from 'react-icons/lu';
import ConfirmDialog from '@/components/common/ConfirmDialog';
import { pluralize } from '@/utils/pluralize';
import { toastSuccess, toastError } from '@/utils/toast';

/**
 * Диалог управления корзинами.
 *
 * @param {{
 *   open: boolean,
 *   onClose: () => void,
 *   carts: Array<{ id: number, name: string, is_active: boolean, items_count: number }>,
 *   currentCartId: number,
 * }} props
 */
export default function CartManagerDialog({ open, onClose, carts = [] }) {
    // ── State ──
    const [editingId, setEditingId] = useState(null);
    const [editName, setEditName] = useState('');
    const [newCartName, setNewCartName] = useState('');
    const [showNewInput, setShowNewInput] = useState(false);
    const [deleteCartId, setDeleteCartId] = useState(null);
    const [processing, setProcessing] = useState(false);

    const canDelete = carts.length > 1;

    // ── Handlers ──
    const handleSwitch = useCallback((cartId) => {
        if (processing) return;
        setProcessing(true);
        router.post(`/cart/${cartId}/switch`, {}, {
            preserveScroll: true,
            onSuccess: () => toastSuccess('Корзина переключена'),
            onError: () => toastError('Ошибка', 'Не удалось переключить корзину.'),
            onFinish: () => setProcessing(false),
        });
    }, [processing]);

    const startRename = useCallback((cart) => {
        setEditingId(cart.id);
        setEditName(cart.name);
    }, []);

    const cancelRename = useCallback(() => {
        setEditingId(null);
        setEditName('');
    }, []);

    const saveRename = useCallback(() => {
        if (!editName.trim() || processing) return;
        setProcessing(true);
        router.patch(`/cart/${editingId}`, { name: editName.trim() }, {
            preserveScroll: true,
            onSuccess: () => toastSuccess('Корзина переименована'),
            onError: () => toastError('Ошибка', 'Не удалось переименовать корзину.'),
            onFinish: () => {
                setProcessing(false);
                setEditingId(null);
                setEditName('');
            },
        });
    }, [editingId, editName, processing]);

    const handleCreate = useCallback(() => {
        if (processing) return;
        const name = newCartName.trim() || null;
        setProcessing(true);
        router.post('/cart', { name }, {
            onSuccess: () => toastSuccess('Корзина создана'),
            onError: () => toastError('Ошибка', 'Не удалось создать корзину.'),
            onFinish: () => {
                setProcessing(false);
                setNewCartName('');
                setShowNewInput(false);
            },
        });
    }, [newCartName, processing]);

    const confirmDelete = useCallback(() => {
        if (!deleteCartId || processing) return;
        setProcessing(true);
        router.delete(`/cart/${deleteCartId}`, {
            preserveScroll: true,
            onSuccess: () => toastSuccess('Корзина удалена'),
            onError: () => toastError('Ошибка', 'Не удалось удалить корзину.'),
            onFinish: () => {
                setProcessing(false);
                setDeleteCartId(null);
            },
        });
    }, [deleteCartId, processing]);

    return (
        <>
            <Dialog.Root open={open} onOpenChange={({ open: isOpen }) => !isOpen && onClose()}>
                <Portal>
                    <Dialog.Backdrop />
                    <Dialog.Positioner>
                        <Dialog.Content maxW={{ base: '100vw', md: '480px' }} maxH={{ base: '100vh', md: '80vh' }} m={{ base: '0', md: 'auto' }} borderRadius={{ base: '0', md: 'lg' }}>
                            <Dialog.Header>
                                <Dialog.Title>Управление корзинами</Dialog.Title>
                                <Dialog.CloseTrigger asChild>
                                    <CloseButton size="sm" />
                                </Dialog.CloseTrigger>
                            </Dialog.Header>
                            <Dialog.Body>
                                <VStack gap="2" align="stretch">
                                    {carts.map((cart) => (
                                        <Box
                                            key={cart.id}
                                            p="3"
                                            borderRadius="lg"
                                            border="1px solid"
                                            borderColor={cart.is_active ? 'pecado.200' : 'gray.200'}
                                            bg={cart.is_active ? 'pecado.50' : 'transparent'}
                                            _dark={{
                                                borderColor: cart.is_active ? 'pecado.700' : 'gray.700',
                                                bg: cart.is_active ? 'pecado.900/20' : 'transparent',
                                            }}
                                        >
                                            <Flex align="center" gap="2">
                                                {/* Active indicator + name */}
                                                <Box flex="1" minW="0">
                                                    {editingId === cart.id ? (
                                                        <HStack gap="1">
                                                            <Input
                                                                size="sm"
                                                                value={editName}
                                                                onChange={(e) => setEditName(e.target.value)}
                                                                onKeyDown={(e) => {
                                                                    if (e.key === 'Enter') saveRename();
                                                                    if (e.key === 'Escape') cancelRename();
                                                                }}
                                                                autoFocus
                                                                maxLength={255}
                                                            />
                                                            <IconButton
                                                                size="xs"
                                                                variant="ghost"
                                                                colorPalette="green"
                                                                onClick={saveRename}
                                                                disabled={processing}
                                                                aria-label="Сохранить"
                                                            >
                                                                <LuCheck />
                                                            </IconButton>
                                                            <IconButton
                                                                size="xs"
                                                                variant="ghost"
                                                                colorPalette="gray"
                                                                onClick={cancelRename}
                                                                aria-label="Отмена"
                                                            >
                                                                <LuX />
                                                            </IconButton>
                                                        </HStack>
                                                    ) : (
                                                        <Flex align="center" gap="2">
                                                            <Text
                                                                fontWeight={cart.is_active ? '600' : '400'}
                                                                fontSize="sm"
                                                                truncate
                                                            >
                                                                {cart.name}
                                                            </Text>
                                                            {cart.is_active && (
                                                                <Badge colorPalette="green" variant="subtle" size="sm">
                                                                    Активная
                                                                </Badge>
                                                            )}
                                                        </Flex>
                                                    )}
                                                    {editingId !== cart.id && (
                                                        <Text fontSize="xs" color="fg.muted" mt="0.5">
                                                            {cart.items_count}{' '}
                                                            {pluralize(cart.items_count, 'товар', 'товара', 'товаров')}
                                                        </Text>
                                                    )}
                                                </Box>

                                                {/* Actions */}
                                                {editingId !== cart.id && (
                                                    <HStack gap="0.5" flexShrink="0">
                                                        {!cart.is_active && (
                                                            <Button
                                                                size="xs"
                                                                variant="outline"
                                                                colorPalette="pecado"
                                                                onClick={() => handleSwitch(cart.id)}
                                                                disabled={processing}
                                                            >
                                                                Выбрать
                                                            </Button>
                                                        )}
                                                        <IconButton
                                                            size="xs"
                                                            variant="ghost"
                                                            colorPalette="gray"
                                                            onClick={() => startRename(cart)}
                                                            aria-label="Переименовать"
                                                        >
                                                            <LuPencil />
                                                        </IconButton>
                                                        {canDelete && (
                                                            <IconButton
                                                                size="xs"
                                                                variant="ghost"
                                                                colorPalette="red"
                                                                onClick={() => setDeleteCartId(cart.id)}
                                                                disabled={processing}
                                                                aria-label="Удалить"
                                                            >
                                                                <LuTrash2 />
                                                            </IconButton>
                                                        )}
                                                    </HStack>
                                                )}
                                            </Flex>
                                        </Box>
                                    ))}
                                </VStack>

                                <Separator my="3" />

                                {/* Create new cart */}
                                {showNewInput ? (
                                    <HStack gap="2">
                                        <Input
                                            size="sm"
                                            placeholder="Название корзины"
                                            value={newCartName}
                                            onChange={(e) => setNewCartName(e.target.value)}
                                            onKeyDown={(e) => {
                                                if (e.key === 'Enter') handleCreate();
                                                if (e.key === 'Escape') {
                                                    setShowNewInput(false);
                                                    setNewCartName('');
                                                }
                                            }}
                                            autoFocus
                                            maxLength={255}
                                        />
                                        <Button
                                            size="sm"
                                            colorPalette="pecado"
                                            onClick={handleCreate}
                                            disabled={processing}
                                        >
                                            Создать
                                        </Button>
                                        <IconButton
                                            size="sm"
                                            variant="ghost"
                                            onClick={() => {
                                                setShowNewInput(false);
                                                setNewCartName('');
                                            }}
                                            aria-label="Отмена"
                                        >
                                            <LuX />
                                        </IconButton>
                                    </HStack>
                                ) : (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        colorPalette="pecado"
                                        onClick={() => setShowNewInput(true)}
                                        w="full"
                                    >
                                        <LuPlus />
                                        Создать корзину
                                    </Button>
                                )}
                            </Dialog.Body>

                        </Dialog.Content>
                    </Dialog.Positioner>
                </Portal>
            </Dialog.Root>

            {/* Confirm delete dialog */}
            <ConfirmDialog
                open={deleteCartId !== null}
                onClose={() => setDeleteCartId(null)}
                onConfirm={confirmDelete}
                title="Удалить корзину?"
                description="Все товары в этой корзине будут удалены. Это действие нельзя отменить."
                confirmLabel="Удалить"
                cancelLabel="Отмена"
                colorPalette="red"
            />
        </>
    );
}
