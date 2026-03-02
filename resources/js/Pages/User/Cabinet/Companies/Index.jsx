import { useState } from 'react';
import {
    Box, Flex, Text, Card, HStack, VStack, Badge, Button,
    IconButton, Table, Dialog, Portal,
} from '@chakra-ui/react';
import { Head, Link, router } from '@inertiajs/react';
import CabinetLayout from '../CabinetLayout';
import { LuPlus, LuPencil, LuTrash2, LuBuilding2 } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';
import axios from 'axios';

export default function Index({ companies = [] }) {
    const [deleteCompany, setDeleteCompany] = useState(null);

    const confirmDelete = async () => {
        if (!deleteCompany) return;
        try {
            await axios.delete(`/cabinet/companies/${deleteCompany.id}`);
            toaster.create({ title: 'Компания удалена', type: 'success' });
            setDeleteCompany(null);
            router.visit('/cabinet/companies');
        } catch (e) {
            toaster.create({ title: e.response?.data?.message || 'Ошибка удаления', type: 'error' });
            setDeleteCompany(null);
        }
    };

    return (
        <CabinetLayout
            title="Мои компании"
            actions={
                <Button
                    as={Link}
                    href="/cabinet/companies/create"
                    bg="#9e1b32"
                    color="white"
                    _hover={{ bg: '#7a1527' }}
                    size="sm"
                >
                    <LuPlus /> Добавить
                </Button>
            }
        >
            <Head title="Мои компании — Pecado" />

            {companies.length === 0 ? (
                <Card.Root borderRadius="xl" border="1px solid" borderColor="gray.100" _dark={{ bg: 'gray.800', borderColor: 'gray.700' }}>
                    <Card.Body p="8" textAlign="center">
                        <VStack gap="3">
                            <Flex
                                align="center" justify="center" w="14" h="14" borderRadius="full"
                                bg="gray.100" _dark={{ bg: 'gray.700' }}
                            >
                                <LuBuilding2 size={24} color="gray" />
                            </Flex>
                            <Text color="gray.500">Компаний пока нет</Text>
                            <Button
                                as={Link}
                                href="/cabinet/companies/create"
                                bg="#9e1b32"
                                color="white"
                                _hover={{ bg: '#7a1527' }}
                                size="sm"
                            >
                                <LuPlus /> Добавить компанию
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
                                    <Table.ColumnHeader>ИНН</Table.ColumnHeader>
                                    <Table.ColumnHeader>Страна</Table.ColumnHeader>
                                    <Table.ColumnHeader>Счетов</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Действия</Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {companies.map((c) => (
                                    <Table.Row key={c.id}>
                                        <Table.Cell>
                                            <Box>
                                                <Text fontWeight="600" fontSize="sm">{c.name}</Text>
                                                {c.legal_name && (
                                                    <Text fontSize="xs" color="gray.400">{c.legal_name}</Text>
                                                )}
                                            </Box>
                                        </Table.Cell>
                                        <Table.Cell fontSize="sm">{c.tax_id || '—'}</Table.Cell>
                                        <Table.Cell fontSize="sm">{c.country || '—'}</Table.Cell>
                                        <Table.Cell>
                                            <Badge colorPalette="blue" variant="subtle">{c.bank_accounts_count || 0}</Badge>
                                        </Table.Cell>
                                        <Table.Cell textAlign="right">
                                            <HStack gap="1" justify="flex-end">
                                                <IconButton
                                                    as={Link}
                                                    href={`/cabinet/companies/${c.id}/edit`}
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
                                                    onClick={() => setDeleteCompany(c)}
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
                        {companies.map((c) => (
                            <Flex key={c.id} p="4" align="center" justify="space-between">
                                <Box flex="1" minW="0">
                                    <Text fontWeight="600" fontSize="sm" noOfLines={1}>{c.name}</Text>
                                    <HStack gap="2" mt="1">
                                        {c.tax_id && <Text fontSize="xs" color="gray.400">ИНН: {c.tax_id}</Text>}
                                        <Badge colorPalette="blue" variant="subtle" fontSize="xs">{c.bank_accounts_count || 0} счетов</Badge>
                                    </HStack>
                                </Box>
                                <HStack gap="1" flexShrink="0">
                                    <IconButton
                                        as={Link}
                                        href={`/cabinet/companies/${c.id}/edit`}
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
                                        onClick={() => setDeleteCompany(c)}
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
            <Dialog.Root open={!!deleteCompany} onOpenChange={({ open }) => !open && setDeleteCompany(null)}>
                <Portal>
                    <Dialog.Backdrop />
                    <Dialog.Positioner>
                        <Dialog.Content>
                            <Dialog.Header>
                                <Dialog.Title>Удаление компании</Dialog.Title>
                            </Dialog.Header>
                            <Dialog.Body>
                                <Text>
                                    Удалить компанию «{deleteCompany?.name}»? Все банковские счета также будут удалены.
                                </Text>
                            </Dialog.Body>
                            <Dialog.Footer>
                                <Button variant="outline" onClick={() => setDeleteCompany(null)}>Отмена</Button>
                                <Button colorPalette="red" onClick={confirmDelete}>Удалить</Button>
                            </Dialog.Footer>
                        </Dialog.Content>
                    </Dialog.Positioner>
                </Portal>
            </Dialog.Root>
        </CabinetLayout>
    );
}
