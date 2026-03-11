import { useState, useRef } from 'react';
import {
    Box, Flex, Text, Card, HStack, VStack, Badge, Button,
    IconButton, Table, Input, Dialog, Portal,
} from '@chakra-ui/react';
import { Head, Link, router } from '@inertiajs/react';
import CabinetLayout from '../CabinetLayout';
import {
    LuPlus, LuEye, LuTrash2, LuShoppingCart, LuStar,
    LuPencil, LuCheck, LuX,
} from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';
import axios from 'axios';

export default function Index({ carts = [], cartsCount = 0 }) {
    const [editingId, setEditingId] = useState(null);
    const [editName, setEditName] = useState('');

    // Create dialog
    const [showCreate, setShowCreate] = useState(false);
    const [newCartName, setNewCartName] = useState('');
    const createInputRef = useRef(null);

    // Delete dialog
    const [deleteCart, setDeleteCart] = useState(null);

    const formatPrice = (val) =>
        new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'RUB', minimumFractionDigits: 0 }).format(val || 0);

    // === Create ===
    const openCreateDialog = () => {
        setNewCartName('');
        setShowCreate(true);
    };

    const confirmCreate = async () => {
        if (!newCartName.trim()) {
            toaster.create({ title: 'Введите название корзины', type: 'warning' });
            return;
        }
        setShowCreate(false);
        try {
            await axios.post('/api/cart/carts', { name: newCartName.trim() });
            toaster.create({ title: 'Корзина создана', type: 'success' });
            router.reload();
        } catch (e) {
            toaster.create({ title: e.response?.data?.message || 'Ошибка создания', type: 'error' });
        }
    };

    // === Delete ===
    const confirmDelete = async () => {
        if (!deleteCart) return;
        try {
            await axios.delete(`/cabinet/carts/${deleteCart.id}`);
            toaster.create({ title: 'Корзина удалена', type: 'success' });
            setDeleteCart(null);
            router.reload();
        } catch (e) {
            toaster.create({ title: e.response?.data?.message || 'Ошибка удаления', type: 'error' });
            setDeleteCart(null);
        }
    };

    // === Switch ===
    const handleSwitch = async (cart) => {
        try {
            await axios.post(`/cabinet/carts/${cart.id}/switch`);
            toaster.create({ title: `Корзина "${cart.name || '#' + cart.id}" стала активной`, type: 'success' });
            router.reload();
        } catch (e) {
            toaster.create({ title: e.response?.data?.message || 'Ошибка', type: 'error' });
        }
    };

    // === Rename ===
    const startEdit = (cart) => {
        setEditingId(cart.id);
        setEditName(cart.name || '');
    };

    const cancelEdit = () => {
        setEditingId(null);
        setEditName('');
    };

    const saveRename = async (cart) => {
        if (!editName.trim()) {
            toaster.create({ title: 'Введите название', type: 'warning' });
            return;
        }
        try {
            await axios.patch(`/cabinet/carts/${cart.id}/rename`, { name: editName.trim() });
            toaster.create({ title: 'Название обновлено', type: 'success' });
            setEditingId(null);
            router.reload();
        } catch (e) {
            toaster.create({ title: e.response?.data?.message || 'Ошибка', type: 'error' });
        }
    };

    const renderName = (cart) => {
        if (editingId === cart.id) {
            return (
                <HStack gap="1">
                    <Input
                        size="sm" w="180px"
                        value={editName}
                        onChange={(e) => setEditName(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && saveRename(cart)}
                        autoFocus
                    />
                    <IconButton size="xs" variant="ghost" colorPalette="green" onClick={() => saveRename(cart)}>
                        <LuCheck />
                    </IconButton>
                    <IconButton size="xs" variant="ghost" onClick={cancelEdit}>
                        <LuX />
                    </IconButton>
                </HStack>
            );
        }
        return (
            <HStack gap="1">
                <Text fontWeight="600" fontSize="sm">
                    {cart.name || `Корзина #${cart.id}`}
                </Text>
                {cart.is_active && (
                    <Badge colorPalette="green" variant="subtle" fontSize="xs">Активная</Badge>
                )}
                <IconButton
                    size="xs" variant="ghost" color="gray.400"
                    aria-label="Переименовать"
                    onClick={() => startEdit(cart)}
                >
                    <LuPencil />
                </IconButton>
            </HStack>
        );
    };

    return (
        <CabinetLayout
            title="Мои корзины"
            actions={
                <Button onClick={openCreateDialog} bg="#9e1b32" color="white" _hover={{ bg: '#7a1527' }} size="sm">
                    <LuPlus /> Создать
                </Button>
            }
        >
            <Head title="Мои корзины — Pecado" />

            {carts.length === 0 ? (
                <Card.Root borderRadius="xl" border="1px solid" borderColor="gray.100" _dark={{ bg: 'gray.800', borderColor: 'gray.700' }}>
                    <Card.Body p="8" textAlign="center">
                        <VStack gap="3">
                            <Flex align="center" justify="center" w="14" h="14" borderRadius="full" bg="gray.100" _dark={{ bg: 'gray.700' }}>
                                <LuShoppingCart size={24} color="gray" />
                            </Flex>
                            <Text color="gray.500">Корзин пока нет</Text>
                            <Button onClick={openCreateDialog} bg="#9e1b32" color="white" _hover={{ bg: '#7a1527' }} size="sm">
                                <LuPlus /> Создать корзину
                            </Button>
                        </VStack>
                    </Card.Body>
                </Card.Root>
            ) : (
                <Card.Root borderRadius="xl" border="1px solid" borderColor="gray.100" _dark={{ bg: 'gray.800', borderColor: 'gray.700' }} overflow="hidden">
                    {/* Desktop Table */}
                    <Box display={{ base: 'none', md: 'block' }}>
                        <Table.Root size="sm">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeader>Название</Table.ColumnHeader>
                                    <Table.ColumnHeader>Позиций</Table.ColumnHeader>
                                    <Table.ColumnHeader>Единиц</Table.ColumnHeader>
                                    <Table.ColumnHeader>Сумма</Table.ColumnHeader>
                                    <Table.ColumnHeader>Обновлена</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Действия</Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {carts.map((c) => (
                                    <Table.Row key={c.id}>
                                        <Table.Cell>{renderName(c)}</Table.Cell>
                                        <Table.Cell>
                                            <Badge colorPalette={c.items_count > 0 ? 'blue' : 'gray'} variant="subtle">
                                                {c.items_count}
                                            </Badge>
                                        </Table.Cell>
                                        <Table.Cell fontSize="sm">{c.total_quantity}</Table.Cell>
                                        <Table.Cell fontSize="sm" fontWeight="600">{formatPrice(c.total_amount)}</Table.Cell>
                                        <Table.Cell fontSize="xs" color="gray.400">{c.updated_at || '—'}</Table.Cell>
                                        <Table.Cell textAlign="right">
                                            <HStack gap="1" justify="flex-end">
                                                <IconButton
                                                    size="xs" variant="ghost"
                                                    aria-label={c.is_active ? 'Активная' : 'Сделать активной'}
                                                    title={c.is_active ? 'Активная' : 'Сделать активной'}
                                                    onClick={() => !c.is_active && handleSwitch(c)}
                                                    cursor={c.is_active ? 'default' : 'pointer'}
                                                    color={c.is_active ? 'yellow.500' : 'gray.400'}
                                                >
                                                    <LuStar style={c.is_active ? { fill: 'currentColor' } : {}} />
                                                </IconButton>
                                                <IconButton
                                                    as={Link} href={`/cabinet/carts/${c.id}`}
                                                    size="xs" variant="ghost" colorPalette="blue"
                                                    aria-label="Просмотр"
                                                >
                                                    <LuEye />
                                                </IconButton>
                                                {c.can_delete && (
                                                    <IconButton
                                                        size="xs" variant="ghost" colorPalette="red"
                                                        aria-label="Удалить"
                                                        onClick={() => setDeleteCart(c)}
                                                    >
                                                        <LuTrash2 />
                                                    </IconButton>
                                                )}
                                            </HStack>
                                        </Table.Cell>
                                    </Table.Row>
                                ))}
                            </Table.Body>
                        </Table.Root>
                    </Box>

                    {/* Mobile Cards */}
                    <VStack display={{ base: 'flex', md: 'none' }} gap="0" align="stretch" separator={<Box borderTop="1px solid" borderColor="gray.100" _dark={{ borderColor: 'gray.700' }} />}>
                        {carts.map((c) => (
                            <Box key={c.id} p="4">
                                <Flex justify="space-between" align="start" mb="2">
                                    <Box flex="1" minW="0">
                                        {renderName(c)}
                                        <HStack gap="3" flexWrap="wrap" mt="1">
                                            <Text fontSize="xs" color="gray.400">
                                                {c.items_count} позиций · {c.total_quantity} ед.
                                            </Text>
                                            <Text fontSize="sm" fontWeight="700" color="green.600">
                                                {formatPrice(c.total_amount)}
                                            </Text>
                                        </HStack>
                                    </Box>
                                    <HStack gap="1" flexShrink="0">
                                        <IconButton
                                            size="sm" variant="ghost"
                                            aria-label={c.is_active ? 'Активная' : 'Сделать активной'}
                                            onClick={() => !c.is_active && handleSwitch(c)}
                                            cursor={c.is_active ? 'default' : 'pointer'}
                                            color={c.is_active ? 'yellow.500' : 'gray.400'}
                                        >
                                            <LuStar style={c.is_active ? { fill: 'currentColor' } : {}} />
                                        </IconButton>
                                        <IconButton as={Link} href={`/cabinet/carts/${c.id}`} size="sm" variant="ghost" colorPalette="blue" aria-label="Просмотр">
                                            <LuEye />
                                        </IconButton>
                                        {c.can_delete && (
                                            <IconButton size="sm" variant="ghost" colorPalette="red" aria-label="Удалить" onClick={() => setDeleteCart(c)}>
                                                <LuTrash2 />
                                            </IconButton>
                                        )}
                                    </HStack>
                                </Flex>
                            </Box>
                        ))}
                    </VStack>
                </Card.Root>
            )}

            {/* Create Cart Dialog */}
            <Dialog.Root open={showCreate} onOpenChange={({ open }) => !open && setShowCreate(false)} initialFocusEl={() => createInputRef.current}>
                <Portal>
                    <Dialog.Backdrop />
                    <Dialog.Positioner>
                        <Dialog.Content>
                            <Dialog.Header>
                                <Dialog.Title>Новая корзина</Dialog.Title>
                            </Dialog.Header>
                            <Dialog.Body>
                                <Text mb="2" fontSize="sm" color="gray.500">
                                    Введите название для новой корзины
                                </Text>
                                <Input
                                    ref={createInputRef}
                                    value={newCartName}
                                    onChange={(e) => setNewCartName(e.target.value)}
                                    onKeyDown={(e) => e.key === 'Enter' && confirmCreate()}
                                    placeholder="Например: Для офиса"
                                />
                            </Dialog.Body>
                            <Dialog.Footer>
                                <Button variant="outline" onClick={() => setShowCreate(false)}>
                                    Отмена
                                </Button>
                                <Button bg="#9e1b32" color="white" _hover={{ bg: '#7a1527' }} onClick={confirmCreate}>
                                    Создать
                                </Button>
                            </Dialog.Footer>
                        </Dialog.Content>
                    </Dialog.Positioner>
                </Portal>
            </Dialog.Root>

            {/* Delete Confirm Dialog */}
            <Dialog.Root open={!!deleteCart} onOpenChange={({ open }) => !open && setDeleteCart(null)}>
                <Portal>
                    <Dialog.Backdrop />
                    <Dialog.Positioner>
                        <Dialog.Content>
                            <Dialog.Header>
                                <Dialog.Title>Удаление корзины</Dialog.Title>
                            </Dialog.Header>
                            <Dialog.Body>
                                <Text>
                                    Удалить корзину «{deleteCart?.name || `#${deleteCart?.id}`}»? Все товары из неё будут удалены.
                                </Text>
                            </Dialog.Body>
                            <Dialog.Footer>
                                <Button variant="outline" onClick={() => setDeleteCart(null)}>
                                    Отмена
                                </Button>
                                <Button colorPalette="red" onClick={confirmDelete}>
                                    Удалить
                                </Button>
                            </Dialog.Footer>
                        </Dialog.Content>
                    </Dialog.Positioner>
                </Portal>
            </Dialog.Root>
        </CabinetLayout>
    );
}
