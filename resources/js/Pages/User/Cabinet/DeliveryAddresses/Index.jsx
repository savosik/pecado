import { useState } from 'react';
import {
    Box, Flex, Text, Card, HStack, VStack, Badge, Button,
    IconButton, Table, Dialog, Portal,
} from '@chakra-ui/react';
import { Head, Link, router } from '@inertiajs/react';
import CabinetLayout from '../CabinetLayout';
import { LuPlus, LuPencil, LuTrash2, LuMapPin } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';
import { Checkbox } from '@/components/ui/checkbox';
import axios from 'axios';

export default function Index({ addresses = [] }) {
    const [addressesData, setAddressesData] = useState(addresses);
    const [deleteAddress, setDeleteAddress] = useState(null);

    // Отразить серверную логику «единственный default»: у выбранного — val, у остальных — false.
    const handleToggled = (id, val) => {
        setAddressesData((prev) => prev.map((a) => ({
            ...a,
            is_default: a.id === id ? val : false,
        })));
    };

    const confirmDelete = async () => {
        if (!deleteAddress) return;
        try {
            await axios.delete(`/cabinet/delivery-addresses/${deleteAddress.id}`);
            toaster.create({ title: 'Адрес доставки удалён', type: 'success' });
            setDeleteAddress(null);
            router.visit('/cabinet/delivery-addresses');
        } catch (e) {
            toaster.create({ title: e.response?.data?.message || 'Ошибка удаления', type: 'error' });
            setDeleteAddress(null);
        }
    };

    return (
        <CabinetLayout
            title="Адреса доставки"
            actions={
                <Button
                    as={Link}
                    href="/cabinet/delivery-addresses/create"
                    bg="#9e1b32"
                    color="white"
                    _hover={{ bg: '#7a1527' }}
                    size="sm"
                >
                    <LuPlus /> Добавить
                </Button>
            }
        >
            <Head title="Адреса доставки — Pecado" />

            {addressesData.length === 0 ? (
                <Card.Root bg="bg" borderRadius="xl" border="1px solid" borderColor="border.muted">
                    <Card.Body p="8" textAlign="center">
                        <VStack gap="3">
                            <Flex
                                align="center" justify="center" w="14" h="14" borderRadius="full"
                                bg="bg.muted"
                            >
                                <LuMapPin size={24} color="gray" />
                            </Flex>
                            <Text color="gray.500">Адресов доставки пока нет</Text>
                            <Button
                                as={Link}
                                href="/cabinet/delivery-addresses/create"
                                bg="#9e1b32"
                                color="white"
                                _hover={{ bg: '#7a1527' }}
                                size="sm"
                            >
                                <LuPlus /> Добавить адрес
                            </Button>
                        </VStack>
                    </Card.Body>
                </Card.Root>
            ) : (
                <Card.Root bg="bg" borderRadius="xl" border="1px solid" borderColor="border.muted" overflow="hidden">
                    {/* Desktop Table */}
                    <Box display={{ base: 'none', md: 'block' }}>
                        <Table.Root bg="bg" size="sm">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeader>Название</Table.ColumnHeader>
                                    <Table.ColumnHeader>Адрес</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="center">По умолч.</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Действия</Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {addressesData.map((a) => (
                                    <Table.Row key={a.id}>
                                        <Table.Cell>
                                            <Text fontWeight="600" fontSize="sm">{a.name}</Text>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Text fontSize="sm" color="gray.600" _dark={{ color: 'gray.400' }} noOfLines={2}>{a.address}</Text>
                                        </Table.Cell>
                                        <Table.Cell textAlign="center">
                                            <DefaultToggle address={a} onToggled={handleToggled} />
                                        </Table.Cell>
                                        <Table.Cell textAlign="right">
                                            <HStack gap="1" justify="flex-end">
                                                <IconButton
                                                    as={Link}
                                                    href={`/cabinet/delivery-addresses/${a.id}/edit`}
                                                    size="xs"
                                                    variant="ghost"
                                                    colorPalette="blue"
                                                    aria-label="Редактировать"
                                                >
                                                    <LuPencil />
                                                </IconButton>
                                                <IconButton
                                                    size="xs"
                                                    variant="ghost"
                                                    colorPalette="red"
                                                    aria-label="Удалить"
                                                    onClick={() => setDeleteAddress(a)}
                                                >
                                                    <LuTrash2 />
                                                </IconButton>
                                            </HStack>
                                        </Table.Cell>
                                    </Table.Row>
                                ))}
                            </Table.Body>
                        </Table.Root>
                    </Box>

                    {/* Mobile Cards */}
                    <VStack display={{ base: 'flex', md: 'none' }} gap="0" align="stretch" separator={<Box borderTop="1px solid" borderColor="border.muted" />}>
                        {addressesData.map((a) => (
                            <Flex key={a.id} p="4" align="center" justify="space-between" gap="2">
                                <Box flex="1" minW="0">
                                    <HStack gap="2">
                                        <Text fontWeight="600" fontSize="sm" noOfLines={1}>{a.name}</Text>
                                        {a.is_default && (
                                            <Badge colorPalette="pecado" variant="subtle" fontSize="xs">По умолч.</Badge>
                                        )}
                                    </HStack>
                                    <Text fontSize="xs" color="gray.400" noOfLines={2} mt="1">{a.address}</Text>
                                    <Box mt="2">
                                        <DefaultToggle address={a} onToggled={handleToggled} withLabel />
                                    </Box>
                                </Box>
                                <HStack gap="1" flexShrink="0">
                                    <IconButton
                                        as={Link}
                                        href={`/cabinet/delivery-addresses/${a.id}/edit`}
                                        size="sm"
                                        variant="ghost"
                                        colorPalette="blue"
                                        aria-label="Редактировать"
                                    >
                                        <LuPencil />
                                    </IconButton>
                                    <IconButton
                                        size="sm"
                                        variant="ghost"
                                        colorPalette="red"
                                        aria-label="Удалить"
                                        onClick={() => setDeleteAddress(a)}
                                    >
                                        <LuTrash2 />
                                    </IconButton>
                                </HStack>
                            </Flex>
                        ))}
                    </VStack>
                </Card.Root>
            )}

            {/* Delete Confirm Dialog */}
            <Dialog.Root open={!!deleteAddress} onOpenChange={({ open }) => !open && setDeleteAddress(null)}>
                <Portal>
                    <Dialog.Backdrop />
                    <Dialog.Positioner>
                        <Dialog.Content>
                            <Dialog.Header>
                                <Dialog.Title>Удаление адреса доставки</Dialog.Title>
                            </Dialog.Header>
                            <Dialog.Body>
                                <Text>
                                    Удалить адрес «{deleteAddress?.name}»?
                                </Text>
                            </Dialog.Body>
                            <Dialog.Footer>
                                <Button variant="outline" onClick={() => setDeleteAddress(null)}>Отмена</Button>
                                <Button colorPalette="red" onClick={confirmDelete}>Удалить</Button>
                            </Dialog.Footer>
                        </Dialog.Content>
                    </Dialog.Positioner>
                </Portal>
            </Dialog.Root>
        </CabinetLayout>
    );
}

function DefaultToggle({ address, onToggled, withLabel = false }) {
    const [loading, setLoading] = useState(false);

    const handleToggle = async () => {
        setLoading(true);
        try {
            const { data } = await axios.post(`/cabinet/delivery-addresses/${address.id}/toggle-default`);
            onToggled(address.id, data.is_default);
            toaster.create({
                title: data.is_default ? 'Адрес по умолчанию установлен' : 'Адрес по умолчанию сброшен',
                type: 'success',
            });
        } catch {
            toaster.create({ title: 'Ошибка при изменении', type: 'error' });
        } finally {
            setLoading(false);
        }
    };

    return (
        <Checkbox
            checked={!!address.is_default}
            onChange={handleToggle}
            disabled={loading}
            aria-label="Адрес по умолчанию"
        >
            {withLabel ? 'По умолчанию' : undefined}
        </Checkbox>
    );
}
