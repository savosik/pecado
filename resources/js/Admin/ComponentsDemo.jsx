import React, { useState } from 'react';
import { AdminLayout } from './Layouts/AdminLayout';
import {
    Box,
    VStack,
    Heading,
    Text,
    Input,
    Button,
    Badge,
    HStack,
} from '@chakra-ui/react';
import { PageHeader } from './Components/PageHeader';
import { DataTable } from './Components/DataTable';
import { FormField } from './Components/FormField';
import { FormActions } from './Components/FormActions';
import { SearchInput } from './Components/SearchInput';
import { FilterPanel } from './Components/FilterPanel';
import { ConfirmDialog } from './Components/ConfirmDialog';
import { ImageUploader } from './Components/ImageUploader';
import { LuPencil, LuTrash2 } from 'react-icons/lu';
import { useForm } from '@inertiajs/react';

/**
 * ComponentsDemo - страница для тестирования и демонстрации всех компонентов Phase 3
 */
export default function ComponentsDemo() {
    const [searchValue, setSearchValue] = useState('');
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [selectedImage, setSelectedImage] = useState(null);

    const form = useForm({
        name: '',
        email: '',
    });

    // Тестовые данные для таблицы
    const testData = [
        { id: 1, name: 'Товар 1', category: 'Электроника', price: 15990, status: 'active' },
        { id: 2, name: 'Товар 2', category: 'Одежда', price: 2990, status: 'inactive' },
        { id: 3, name: 'Товар 3', category: 'Книги', price: 590, status: 'active' },
        { id: 4, name: 'Товар 4', category: 'Электроника', price: 45990, status: 'active' },
        { id: 5, name: 'Товар 5', category: 'Товары для дома', price: 3490, status: 'draft' },
    ];

    // Конфигурация колонок таблицы
    const columns = [
        { key: 'id', label: 'ID', sortable: true },
        { key: 'name', label: 'Название', sortable: true },
        { key: 'category', label: 'Категория', sortable: false },
        {
            key: 'price',
            label: 'Цена',
            sortable: true,
            render: (value) => `${value.toLocaleString('ru-RU')} ₽`,
        },
        {
            key: 'status',
            label: 'Статус',
            sortable: false,
            render: (value) => {
                const colorMap = {
                    active: 'green',
                    inactive: 'red',
                    draft: 'gray',
                };
                const labelMap = {
                    active: 'Активен',
                    inactive: 'Неактивен',
                    draft: 'Черновик',
                };
                return (
                    <Badge colorPalette={colorMap[value]}>
                        {labelMap[value]}
                    </Badge>
                );
            },
        },
        {
            key: 'actions',
            label: 'Действия',
            render: (_, row) => (
                <HStack gap={1}>
                    <Button size="sm" variant="ghost">
                        <LuPencil />
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        colorPalette="red"
                        onClick={() => setConfirmOpen(true)}
                    >
                        <LuTrash2 />
                    </Button>
                </HStack>
            ),
        },
    ];

    // Тестовая пагинация
    const pagination = {
        current_page: 1,
        last_page: 3,
        per_page: 5,
        total: 15,
        from: 1,
        to: 5,
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        console.log('Form submitted:', form.data);
    };

    const handleBulkDelete = (ids) => {
        console.log('Bulk delete:', ids);
    };

    return (
        <AdminLayout>
            <Box>
                <PageHeader
                    title="Демонстрация компонентов"
                    description="Тестовая страница для проверки переиспользуемых компонентов Phase 3"
                    createLabel="Создать запись"
                    onCreate={() => console.log('Create clicked')}
                />

                <VStack align="stretch" gap={8}>
                    {/* 1. PageHeader - уже выше */}
                    <Box
                        bg="bg.panel"
                        p={6}
                        borderRadius="md"
                        borderWidth="1px"
                        borderColor="border.muted"
                    >
                        <Heading size="md" mb={3}>
                            1. PageHeader
                        </Heading>
                        <Text color="fg.muted" fontSize="sm">
                            Компонент заголовка страницы с кнопкой "Создать" (виден выше)
                        </Text>
                    </Box>

                    {/* 2. FilterPanel & SearchInput */}
                    <Box
                        bg="bg.panel"
                        p={6}
                        borderRadius="md"
                        borderWidth="1px"
                        borderColor="border.muted"
                    >
                        <Heading size="md" mb={3}>
                            2. FilterPanel + SearchInput
                        </Heading>
                        <FilterPanel onClear={() => setSearchValue('')}>
                            <SearchInput
                                value={searchValue}
                                onChange={setSearchValue}
                                placeholder="Поиск товаров..."
                            />
                            <Input placeholder="Дополнительный фильтр" />
                        </FilterPanel>
                        <Text color="fg.muted" fontSize="sm" mt={2}>
                            Текущий поиск: {searchValue || 'нет'}
                        </Text>
                    </Box>

                    {/* 3. DataTable */}
                    <Box
                        bg="bg.panel"
                        p={6}
                        borderRadius="md"
                        borderWidth="1px"
                        borderColor="border.muted"
                    >
                        <Heading size="md" mb={3}>
                            3. DataTable
                        </Heading>
                        <DataTable
                            columns={columns}
                            data={testData}
                            pagination={pagination}
                            selectable
                            bulkActions={[
                                {
                                    label: 'Удалить выбранные',
                                    action: handleBulkDelete,
                                    colorPalette: 'red',
                                },
                            ]}
                        />
                    </Box>

                    {/* 4. Form Components */}
                    <Box
                        bg="bg.panel"
                        p={6}
                        borderRadius="md"
                        borderWidth="1px"
                        borderColor="border.muted"
                    >
                        <Heading size="md" mb={3}>
                            4. FormField + FormActions
                        </Heading>
                        <Box as="form" onSubmit={handleSubmit}>
                            <VStack align="stretch" gap={4}>
                                <FormField
                                    label="Название"
                                    name="name"
                                    required
                                    error={form.errors.name}
                                    helpText="Введите название товара"
                                >
                                    <Input
                                        value={form.data.name}
                                        onChange={(e) => form.setData('name', e.target.value)}
                                    />
                                </FormField>

                                <FormField
                                    label="Email"
                                    name="email"
                                    error={form.errors.email}
                                >
                                    <Input
                                        type="email"
                                        value={form.data.email}
                                        onChange={(e) => form.setData('email', e.target.value)}
                                    />
                                </FormField>

                                <FormActions
                                    isLoading={form.processing}
                                    onCancel={() => form.reset()}
                                />
                            </VStack>
                        </Box>
                    </Box>

                    {/* 5. ImageUploader */}
                    <Box
                        bg="bg.panel"
                        p={6}
                        borderRadius="md"
                        borderWidth="1px"
                        borderColor="border.muted"
                    >
                        <Heading size="md" mb={3}>
                            5. ImageUploader
                        </Heading>
                        <ImageUploader
                            name="image"
                            value={selectedImage}
                            onChange={setSelectedImage}
                        />
                    </Box>

                    {/* 6. ConfirmDialog */}
                    <Box
                        bg="bg.panel"
                        p={6}
                        borderRadius="md"
                        borderWidth="1px"
                        borderColor="border.muted"
                    >
                        <Heading size="md" mb={3}>
                            6. ConfirmDialog
                        </Heading>
                        <Button onClick={() => setConfirmOpen(true)} colorPalette="red">
                            Открыть диалог подтверждения
                        </Button>
                        <ConfirmDialog
                            open={confirmOpen}
                            onClose={() => setConfirmOpen(false)}
                            onConfirm={() => {
                                console.log('Confirmed!');
                                setConfirmOpen(false);
                            }}
                            title="Удалить запись?"
                            description="Это действие нельзя отменить. Вы уверены, что хотите удалить эту запись?"
                        />
                    </Box>
                </VStack>
            </Box>
        </AdminLayout>
    );
}
