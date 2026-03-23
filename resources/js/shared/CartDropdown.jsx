import { useState, useCallback, useEffect } from 'react';
import {
    Box, Flex, Text, Button, IconButton, Input, VStack, HStack,
    Badge, Separator, Menu, Portal, Spinner,
} from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import {
    LuShoppingCart, LuPlus, LuPencil, LuTrash2,
    LuCheck, LuX, LuChevronRight,
} from 'react-icons/lu';
import axios from 'axios';
import { useCartStore } from '@/stores/useCartStore';
import ConfirmDialog from '@/components/common/ConfirmDialog';
import HeaderIconButton from '@/components/common/HeaderIconButton';
import { pluralize } from '@/utils/pluralize';
import { toastSuccess, toastError } from '@/utils/toast';

/**
 * Мини-корзина в шапке — dropdown с управлением корзинами.
 */
export default function CartDropdown() {
    const totalQty = useCartStore((s) => s.getTotalQuantity());

    const [carts, setCarts] = useState([]);
    const [loading, setLoading] = useState(false);
    const [loaded, setLoaded] = useState(false);

    // Rename state
    const [editingId, setEditingId] = useState(null);
    const [editName, setEditName] = useState('');

    // Create state
    const [showNewInput, setShowNewInput] = useState(false);
    const [newCartName, setNewCartName] = useState('');

    // Delete state
    const [deleteCartId, setDeleteCartId] = useState(null);

    const [processing, setProcessing] = useState(false);

    // ── Fetch carts on open ──
    const fetchCarts = useCallback(async () => {
        setLoading(true);
        try {
            const { data } = await axios.get('/api/cart/user-carts');
            setCarts(Array.isArray(data) ? data : []);
            setLoaded(true);
        } catch {
            toastError('Ошибка', 'Не удалось загрузить список корзин.');
        } finally {
            setLoading(false);
        }
    }, []);

    const handleOpenChange = useCallback(({ open }) => {
        if (open) {
            fetchCarts();
        } else {
            // Reset inline edit state
            setEditingId(null);
            setEditName('');
            setShowNewInput(false);
            setNewCartName('');
        }
    }, [fetchCarts]);

    // Refresh cart list when quantities change (e.g. from another tab or add-to-cart)
    useEffect(() => {
        const handler = () => {
            if (loaded) fetchCarts();
        };
        window.addEventListener('cart:changed', handler);
        return () => window.removeEventListener('cart:changed', handler);
    }, [loaded, fetchCarts]);

    // ── Cart CRUD ──
    const handleSwitch = useCallback(async (cartId) => {
        if (processing) return;
        setProcessing(true);
        try {
            await axios.post(`/api/cart/carts/${cartId}/switch`);
            setCarts((prev) =>
                prev.map((c) => ({ ...c, is_active: c.id === cartId })),
            );
            // Re-sync cart store quantities for the new active cart
            useCartStore.getState()._serverSync();
            toastSuccess('Корзина переключена');
        } catch {
            toastError('Ошибка', 'Не удалось переключить корзину.');
        } finally {
            setProcessing(false);
        }
    }, []);

    const handleCreate = useCallback(async () => {
        if (processing) return;
        setProcessing(true);
        try {
            const { data } = await axios.post('/api/cart/carts', {
                name: newCartName.trim() || null,
            });
            if (data.cart) {
                // New cart becomes active, deactivate others
                setCarts((prev) => [
                    ...prev.map((c) => ({ ...c, is_active: false })),
                    data.cart,
                ]);
            } else {
                await fetchCarts();
            }
            setNewCartName('');
            setShowNewInput(false);
            useCartStore.getState()._serverSync();
            toastSuccess('Корзина создана');
        } catch {
            toastError('Ошибка', 'Не удалось создать корзину.');
        } finally {
            setProcessing(false);
        }
    }, [newCartName, processing, fetchCarts]);

    const handleRename = useCallback(async () => {
        if (!editName.trim() || processing) return;
        setProcessing(true);
        try {
            await axios.patch(`/api/cart/carts/${editingId}`, { name: editName.trim() });
            setCarts((prev) =>
                prev.map((c) => (c.id === editingId ? { ...c, name: editName.trim() } : c)),
            );
            setEditingId(null);
            setEditName('');
        } catch {
            toastError('Ошибка', 'Не удалось переименовать корзину.');
        } finally {
            setProcessing(false);
        }
    }, [editingId, editName, processing]);

    const confirmDelete = useCallback(async () => {
        if (!deleteCartId || processing) return;
        setProcessing(true);
        try {
            await axios.delete(`/api/cart/carts/${deleteCartId}`);
            setCarts((prev) => {
                const remaining = prev.filter((c) => c.id !== deleteCartId);
                // If deleted was active, first remaining becomes active
                const hasActive = remaining.some((c) => c.is_active);
                if (!hasActive && remaining.length > 0) {
                    return remaining.map((c, i) => i === 0 ? { ...c, is_active: true } : c);
                }
                return remaining;
            });
            useCartStore.getState()._serverSync();
            toastSuccess('Корзина удалена');
        } catch (err) {
            const msg = err?.response?.data?.message || 'Не удалось удалить корзину.';
            toastError('Ошибка', msg);
        } finally {
            setProcessing(false);
            setDeleteCartId(null);
        }
    }, [deleteCartId]);

    const canDelete = carts.length > 1;

    return (
        <>
            <Menu.Root onOpenChange={handleOpenChange} positioning={{ placement: 'bottom-end' }}>
                <Menu.Trigger asChild>
                    <HeaderIconButton
                        icon={LuShoppingCart}
                        count={totalQty}
                        badgeColor="pecado.solid"
                        aria-label="Корзина"
                    />
                </Menu.Trigger>
                <Portal>
                    <Menu.Positioner>
                        <Menu.Content minW={{ base: '280px', sm: '300px' }} maxW={{ base: '95vw', sm: '380px' }} zIndex="popover" p="0">
                            {/* Header */}
                            <Box px="3" py="2" borderBottom="1px solid" borderColor="gray.100" _dark={{ borderColor: 'gray.700' }}>
                                <Flex align="center" justify="space-between">
                                    <Text fontWeight="600" fontSize="sm">Мои корзины</Text>
                                    <Text fontSize="xs" color="fg.muted">
                                        {totalQty} {pluralize(totalQty, 'товар', 'товара', 'товаров')}
                                    </Text>
                                </Flex>
                            </Box>

                            {/* Cart list */}
                            <Box px="2" py="2" maxH="320px" overflowY="auto">
                                {loading && !loaded ? (
                                    <Flex justify="center" py="4">
                                        <Spinner size="sm" />
                                    </Flex>
                                ) : (
                                    <VStack gap="1" align="stretch">
                                        {carts.map((cart) => (
                                            <Box
                                                key={cart.id}
                                                px="2"
                                                py="1.5"
                                                borderRadius="md"
                                                bg={cart.is_active ? 'pecado.50' : 'transparent'}
                                                _dark={{ bg: cart.is_active ? 'pecado.900/20' : 'transparent' }}
                                                _hover={{ bg: cart.is_active ? 'pecado.50' : 'gray.50', _dark: { bg: cart.is_active ? 'pecado.900/20' : 'gray.800' } }}
                                            >
                                                {editingId === cart.id ? (
                                                    <HStack gap="1">
                                                        <Input
                                                            size="xs"
                                                            value={editName}
                                                            onChange={(e) => setEditName(e.target.value)}
                                                            onKeyDown={(e) => {
                                                                e.stopPropagation();
                                                                if (e.key === 'Enter') { e.preventDefault(); handleRename(); }
                                                                if (e.key === 'Escape') { setEditingId(null); setEditName(''); }
                                                            }}
                                                            onClick={(e) => e.stopPropagation()}
                                                            autoFocus
                                                            maxLength={255}
                                                        />
                                                        <IconButton size="2xs" variant="ghost" colorPalette="green" onClick={(e) => { e.stopPropagation(); handleRename(); }} aria-label="Сохранить">
                                                            <LuCheck size={14} />
                                                        </IconButton>
                                                        <IconButton size="2xs" variant="ghost" onClick={(e) => { e.stopPropagation(); setEditingId(null); setEditName(''); }} aria-label="Отмена">
                                                            <LuX size={14} />
                                                        </IconButton>
                                                    </HStack>
                                                ) : (
                                                    <Flex
                                                        align="center"
                                                        gap="2"
                                                        cursor={cart.is_active ? 'default' : 'pointer'}
                                                        onClick={() => !cart.is_active && handleSwitch(cart.id)}
                                                    >
                                                        <Box flex="1" minW="0">
                                                            <Flex align="center" gap="1.5">
                                                                <Text fontSize="sm" fontWeight={cart.is_active ? '600' : '400'} truncate>
                                                                    {cart.name}
                                                                </Text>
                                                                {cart.is_active && (
                                                                    <Badge colorPalette="green" variant="subtle" size="sm" fontSize="2xs">
                                                                        ✓
                                                                    </Badge>
                                                                )}
                                                            </Flex>
                                                            <Text fontSize="xs" color="fg.muted">
                                                                {cart.items_count} {pluralize(cart.items_count, 'товар', 'товара', 'товаров')}
                                                            </Text>
                                                        </Box>
                                                        <HStack gap="0" flexShrink="0">
                                                            <IconButton
                                                                size="2xs"
                                                                variant="ghost"
                                                                colorPalette="gray"
                                                                onClick={(e) => { e.stopPropagation(); setEditingId(cart.id); setEditName(cart.name); }}
                                                                aria-label="Переименовать"
                                                            >
                                                                <LuPencil size={14} />
                                                            </IconButton>
                                                            {canDelete && (
                                                                <IconButton
                                                                    size="2xs"
                                                                    variant="ghost"
                                                                    colorPalette="red"
                                                                    onClick={(e) => { e.stopPropagation(); setDeleteCartId(cart.id); }}
                                                                    disabled={processing}
                                                                    aria-label="Удалить"
                                                                >
                                                                    <LuTrash2 size={14} />
                                                                </IconButton>
                                                            )}
                                                        </HStack>
                                                    </Flex>
                                                )}
                                            </Box>
                                        ))}
                                    </VStack>
                                )}
                            </Box>

                            <Separator />

                            {/* Create new cart */}
                            <Box px="2" py="2">
                                {showNewInput ? (
                                    <HStack gap="1">
                                        <Input
                                            size="xs"
                                            placeholder="Название корзины"
                                            value={newCartName}
                                            onChange={(e) => setNewCartName(e.target.value)}
                                            onKeyDown={(e) => {
                                                e.stopPropagation();
                                                if (e.key === 'Enter') { e.preventDefault(); handleCreate(); }
                                                if (e.key === 'Escape') { setShowNewInput(false); setNewCartName(''); }
                                            }}
                                            onClick={(e) => e.stopPropagation()}
                                            autoFocus
                                            maxLength={255}
                                        />
                                        <IconButton size="2xs" colorPalette="pecado" onClick={(e) => { e.stopPropagation(); handleCreate(); }} disabled={processing} aria-label="Создать">
                                            <LuCheck size={14} />
                                        </IconButton>
                                        <IconButton size="2xs" variant="ghost" onClick={(e) => { e.stopPropagation(); setShowNewInput(false); setNewCartName(''); }} aria-label="Отмена">
                                            <LuX size={14} />
                                        </IconButton>
                                    </HStack>
                                ) : (
                                    <Button
                                        size="xs"
                                        variant="ghost"
                                        colorPalette="pecado"
                                        w="full"
                                        justifyContent="flex-start"
                                        onClick={(e) => { e.stopPropagation(); setShowNewInput(true); }}
                                    >
                                        <LuPlus size={14} />
                                        Создать корзину
                                    </Button>
                                )}
                            </Box>

                            <Separator />

                            {/* Link to full cart page */}
                            <Box px="2" py="2">
                                <Button
                                    asChild
                                    size="sm"
                                    variant="solid"
                                    colorPalette="pecado"
                                    w="full"
                                >
                                    <Link href="/cart">
                                        Перейти в корзину
                                        <LuChevronRight size={16} />
                                    </Link>
                                </Button>
                            </Box>
                        </Menu.Content>
                    </Menu.Positioner>
                </Portal>
            </Menu.Root>

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
