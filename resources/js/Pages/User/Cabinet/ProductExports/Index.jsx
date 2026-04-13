import { useState } from 'react';
import {
    Box, Flex, Text, Card, HStack, VStack, Badge, Button,
    IconButton, Table, Input, Dialog, Portal,
} from '@chakra-ui/react';
import { Head, Link, router } from '@inertiajs/react';
import CabinetLayout from '../CabinetLayout';
import { LuPlus, LuPencil, LuTrash2, LuCopy, LuSearch, LuFileDown } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';

const formatColors = {
    json: 'blue',
    csv: 'green',
    xml: 'orange',
    xls: 'purple',
};

export default function Index({ exports, filters }) {
    const [searchQuery, setSearchQuery] = useState(filters?.search || '');
    const [deleteExport, setDeleteExport] = useState(null);

    const handleSearch = (value) => {
        setSearchQuery(value);
        router.get(
            route('cabinet.product-exports.index'),
            { search: value || undefined },
            { preserveState: true, replace: true }
        );
    };

    const copyUrl = (url) => {
        navigator.clipboard.writeText(url);
        toaster.create({ title: 'Ссылка скопирована', type: 'success' });
    };

    const confirmDelete = () => {
        if (!deleteExport) return;
        router.delete(`/cabinet/product-exports/${deleteExport.id}`, {
            onSuccess: () => {
                toaster.create({ title: 'Выгрузка удалена', type: 'success' });
                setDeleteExport(null);
            },
            onError: () => {
                toaster.create({ title: 'Ошибка удаления', type: 'error' });
                setDeleteExport(null);
            },
        });
    };

    return (
        <CabinetLayout
            title="Конструктор выгрузок"
            actions={
                <Button
                    as={Link}
                    href="/cabinet/product-exports/create"
                    bg="#9e1b32"
                    color="white"
                    _hover={{ bg: '#7a1527' }}
                    size="sm"
                >
                    <LuPlus /> Создать выгрузку
                </Button>
            }
        >
            <Head title="Конструктор выгрузок — Pecado" />

            <Text fontSize="sm" color="gray.500" mb="4">
                Создавайте произвольные выгрузки с выбором полей, фильтров и формата.
            </Text>

            {/* Search */}
            <Box mb={4}>
                <HStack
                    bg={{ base: 'white', _dark: 'gray.800' }}
                    border="1px solid"
                    borderColor={{ base: 'gray.200', _dark: 'gray.700' }}
                    borderRadius="xl"
                    px={3}
                    _dark={{ bg: 'gray.800', borderColor: 'gray.700' }}
                >
                    <LuSearch size={16} color="gray" />
                    <Input
                        variant="unstyled"
                        value={searchQuery}
                        onChange={(e) => handleSearch(e.target.value)}
                        placeholder="Поиск по названию..."
                        py={2.5}
                        fontSize="sm"
                    />
                </HStack>
            </Box>

            {exports.data.length === 0 ? (
                <Card.Root bg={{ base: 'white', _dark: 'gray.800' }} borderRadius="xl" border="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }} _dark={{ bg: 'gray.800', borderColor: 'gray.700' }}>
                    <Card.Body p="8" textAlign="center">
                        <VStack gap="3">
                            <Flex
                                align="center" justify="center" w="14" h="14" borderRadius="full"
                                bg="gray.100" _dark={{ bg: 'gray.700' }}
                            >
                                <LuFileDown size={24} color="gray" />
                            </Flex>
                            <Text color="gray.500">Выгрузок пока нет</Text>
                            <Text fontSize="xs" color="gray.400">
                                Создайте произвольную выгрузку с выбором полей, фильтров и формата
                            </Text>
                            <Button
                                as={Link}
                                href="/cabinet/product-exports/create"
                                bg="#9e1b32"
                                color="white"
                                _hover={{ bg: '#7a1527' }}
                                size="sm"
                            >
                                <LuPlus /> Создать выгрузку
                            </Button>
                        </VStack>
                    </Card.Body>
                </Card.Root>
            ) : (
                <Card.Root bg={{ base: 'white', _dark: 'gray.800' }} borderRadius="xl" border="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }} _dark={{ bg: 'gray.800', borderColor: 'gray.700' }} overflow="hidden">
                    {/* Desktop Table */}
                    <Box display={{ base: 'none', md: 'block' }}>
                        <Table.Root bg={{ base: 'white', _dark: 'gray.800' }} size="sm">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeader>Название</Table.ColumnHeader>
                                    <Table.ColumnHeader>Формат</Table.ColumnHeader>
                                    <Table.ColumnHeader>Фильтры</Table.ColumnHeader>
                                    <Table.ColumnHeader>Полей</Table.ColumnHeader>
                                    <Table.ColumnHeader>Ссылка</Table.ColumnHeader>
                                    <Table.ColumnHeader>Статус</Table.ColumnHeader>
                                    <Table.ColumnHeader>Скачана</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Действия</Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {exports.data.map((exp) => {
                                    const filtersCount = exp.filters?.conditions?.length || exp.filters?.length || 0;
                                    return (
                                        <Table.Row key={exp.id}>
                                            <Table.Cell>
                                                <Text fontWeight="600" fontSize="sm">{exp.name}</Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Badge colorPalette={formatColors[exp.format] || 'gray'} variant="subtle" size="sm">
                                                    {exp.format?.toUpperCase()}
                                                </Badge>
                                            </Table.Cell>
                                            <Table.Cell fontSize="sm" color="gray.500">{filtersCount}</Table.Cell>
                                            <Table.Cell fontSize="sm" color="gray.500">{exp.fields?.length || 0}</Table.Cell>
                                            <Table.Cell>
                                                <HStack gap={1}>
                                                    <Text fontSize="xs" color="blue.500" maxW="150px" truncate>
                                                        {exp.download_url}
                                                    </Text>
                                                    <IconButton
                                                        size="xs"
                                                        variant="ghost"
                                                        onClick={() => copyUrl(exp.download_url)}
                                                        aria-label="Копировать ссылку"
                                                    >
                                                        <LuCopy />
                                                    </IconButton>
                                                </HStack>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Badge colorPalette={exp.is_active ? 'green' : 'gray'} variant="subtle" size="sm">
                                                    {exp.is_active ? 'Активна' : 'Неактивна'}
                                                </Badge>
                                            </Table.Cell>
                                            <Table.Cell fontSize="sm" color="gray.500">
                                                {exp.last_downloaded_at
                                                    ? new Date(exp.last_downloaded_at).toLocaleDateString('ru-RU')
                                                    : '—'}
                                            </Table.Cell>
                                            <Table.Cell textAlign="right">
                                                <HStack gap="1" justify="flex-end">
                                                    <IconButton
                                                        as={Link}
                                                        href={`/cabinet/product-exports/${exp.id}/edit`}
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
                                                        onClick={() => setDeleteExport(exp)}
                                                    >
                                                        <LuTrash2 />
                                                    </IconButton>
                                                </HStack>
                                            </Table.Cell>
                                        </Table.Row>
                                    );
                                })}
                            </Table.Body>
                        </Table.Root>
                    </Box>

                    {/* Mobile Cards */}
                    <VStack display={{ base: 'flex', md: 'none' }} gap="0" align="stretch"
                        separator={<Box borderTop="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }} _dark={{ borderColor: 'gray.700' }} />}
                    >
                        {exports.data.map((exp) => (
                            <Flex key={exp.id} p="4" align="center" justify="space-between">
                                <Box flex="1" minW="0">
                                    <Text fontWeight="600" fontSize="sm" noOfLines={1}>{exp.name}</Text>
                                    <HStack gap="2" mt="1">
                                        <Badge colorPalette={formatColors[exp.format] || 'gray'} variant="subtle" size="sm">
                                            {exp.format?.toUpperCase()}
                                        </Badge>
                                        <Badge colorPalette={exp.is_active ? 'green' : 'gray'} variant="subtle" size="sm">
                                            {exp.is_active ? 'Активна' : 'Неактивна'}
                                        </Badge>
                                    </HStack>
                                </Box>
                                <HStack gap="1" flexShrink="0">
                                    <IconButton
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => copyUrl(exp.download_url)}
                                        aria-label="Копировать ссылку"
                                    >
                                        <LuCopy />
                                    </IconButton>
                                    <IconButton
                                        as={Link}
                                        href={`/cabinet/product-exports/${exp.id}/edit`}
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
                                        onClick={() => setDeleteExport(exp)}
                                    >
                                        <LuTrash2 />
                                    </IconButton>
                                </HStack>
                            </Flex>
                        ))}
                    </VStack>

                    {/* Pagination */}
                    {exports.last_page > 1 && (
                        <Flex justify="center" p="3" borderTop="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }} _dark={{ borderColor: 'gray.700' }}>
                            <HStack gap="1">
                                {exports.links.map((link, i) => (
                                    <Button
                                        key={i}
                                        size="xs"
                                        variant={link.active ? 'solid' : 'ghost'}
                                        bg={link.active ? '#9e1b32' : undefined}
                                        color={link.active ? 'white' : undefined}
                                        disabled={!link.url}
                                        onClick={() => link.url && router.visit(link.url)}
                                        dangerouslySetInnerHTML={{ __html: link.label }}
                                    />
                                ))}
                            </HStack>
                        </Flex>
                    )}
                </Card.Root>
            )}

            {/* Delete Confirm Dialog */}
            <Dialog.Root open={!!deleteExport} onOpenChange={({ open }) => !open && setDeleteExport(null)}>
                <Portal>
                    <Dialog.Backdrop />
                    <Dialog.Positioner>
                        <Dialog.Content>
                            <Dialog.Header>
                                <Dialog.Title>Удаление выгрузки</Dialog.Title>
                            </Dialog.Header>
                            <Dialog.Body>
                                <Text>
                                    Удалить выгрузку «{deleteExport?.name}»? Ссылка для скачивания перестанет работать. Это действие нельзя отменить.
                                </Text>
                            </Dialog.Body>
                            <Dialog.Footer>
                                <Button variant="outline" onClick={() => setDeleteExport(null)}>Отмена</Button>
                                <Button colorPalette="red" onClick={confirmDelete}>Удалить</Button>
                            </Dialog.Footer>
                        </Dialog.Content>
                    </Dialog.Positioner>
                </Portal>
            </Dialog.Root>
        </CabinetLayout>
    );
}
