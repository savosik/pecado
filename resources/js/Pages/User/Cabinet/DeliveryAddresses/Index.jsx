import { useState } from 'react';
import {
    Box, Flex, Text, Card, HStack, VStack, Button,
    IconButton, Table, Dialog, Portal,
} from '@chakra-ui/react';
import { Head, Link, router } from '@inertiajs/react';
import CabinetLayout from '../CabinetLayout';
import { LuPlus, LuPencil, LuTrash2, LuMapPin } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';
import axios from 'axios';

export default function Index({ addresses = [] }) {
    const [deleteAddress, setDeleteAddress] = useState(null);

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

            {addresses.length === 0 ? (
                <Card.Root borderRadius="xl" border="1px solid" borderColor="gray.100" _dark={{ bg: 'gray.800', borderColor: 'gray.700' }}>
                    <Card.Body p="8" textAlign="center">
                        <VStack gap="3">
                            <Flex
                                align="center" justify="center" w="14" h="14" borderRadius="full"
                                bg="gray.100" _dark={{ bg: 'gray.700' }}
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
                <Card.Root borderRadius="xl" border="1px solid" borderColor="gray.100" _dark={{ bg: 'gray.800', borderColor: 'gray.700' }} overflow="hidden">
                    {/* Desktop Table */}
                    <Box display={{ base: 'none', md: 'block' }}>
                        <Table.Root size="sm">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeader>Название</Table.ColumnHeader>
                                    <Table.ColumnHeader>Адрес</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Действия</Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {addresses.map((a) => (
                                    <Table.Row key={a.id}>
                                        <Table.Cell>
                                            <Text fontWeight="600" fontSize="sm">{a.name}</Text>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Text fontSize="sm" color="gray.600" _dark={{ color: 'gray.400' }} noOfLines={2}>{a.address}</Text>
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
                    <VStack display={{ base: 'flex', md: 'none' }} gap="0" align="stretch" separator={<Box borderTop="1px solid" borderColor="gray.100" _dark={{ borderColor: 'gray.700' }} />}>
                        {addresses.map((a) => (
                            <Flex key={a.id} p="4" align="center" justify="space-between">
                                <Box flex="1" minW="0">
                                    <Text fontWeight="600" fontSize="sm" noOfLines={1}>{a.name}</Text>
                                    <Text fontSize="xs" color="gray.400" noOfLines={2} mt="1">{a.address}</Text>
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
