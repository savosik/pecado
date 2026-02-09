import { useRef } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, ProductSelector, EntitySelector } from '@/Admin/Components';
import { Box, Card, Input, Textarea, Stack, SimpleGrid, Text } from '@chakra-ui/react';
import { Checkbox } from '@/components/ui/checkbox';
import { toaster } from '@/components/ui/toaster';

export default function Edit({ discount }) {
    const { data, setData, post, processing, errors, transform } = useForm({
        _method: 'PUT',
        name: discount.name || '',
        external_id: discount.external_id || '',
        percentage: discount.percentage || '',
        description: discount.description || '',
        is_posted: discount.is_posted || false,
        products: discount.products || [],
        users: discount.users || [],
    });

    const closeAfterSaveRef = useRef(false);

    transform((data) => ({
        ...data,
        _close: closeAfterSaveRef.current ? 1 : 0,
    }));

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;
        transform((data) => ({
            ...data,
            products: data.products.map(p => p.id),
            users: data.users.map(u => u.id),
        }));

        post(route('admin.discounts.update', discount.id), {
            onSuccess: () => {
                toaster.create({
                    title: 'Скидка обновлена',
                    description: 'Информация о скидке успешно обновлена',
                    type: 'success',
                });
            },
            onError: () => {
                toaster.create({
                    title: 'Ошибка',
                    description: 'Не удалось обновить скидку. Проверьте правильность заполнения полей.',
                    type: 'error',
                });
            },
        });
    };

    const handleAddUser = (user) => {
        if (user && !data.users.find(u => u.id === user.id)) {
            setData('users', [...data.users, user]);
        }
    };

    const handleRemoveUser = (userId) => {
        setData('users', data.users.filter(u => u.id !== userId));
    };

    const handleSaveAndClose = (e) => {
        handleSubmit(e, true);
    };

    return (
        <>
            <PageHeader
                title={`Редактирование: ${discount.name || `Скидка ${discount.percentage}%`}`}
                description="Изменение информации о скидке"
            />

            <form onSubmit={handleSubmit}>
                <Card.Root>
                    <Card.Body>
                        <Stack gap={6}>
                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Название" error={errors.name}>
                                    <Input
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="Например: Скидка для VIP клиентов"
                                    />
                                </FormField>

                                <FormField label="Внешний ID" error={errors.external_id}>
                                    <Input
                                        value={data.external_id}
                                        onChange={(e) => setData('external_id', e.target.value)}
                                        placeholder="ID из внешней системы"
                                    />
                                </FormField>

                                <FormField label="Процент скидки" error={errors.percentage} required>
                                    <Input
                                        type="number"
                                        min="0"
                                        max="100"
                                        step="0.01"
                                        value={data.percentage}
                                        onChange={(e) => setData('percentage', e.target.value)}
                                        placeholder="10.50"
                                    />
                                </FormField>

                                <FormField label="Статус публикации" error={errors.is_posted}>
                                    <Checkbox
                                        checked={data.is_posted}
                                        onCheckedChange={(e) => setData('is_posted', e.checked)}
                                    >
                                        Опубликовано
                                    </Checkbox>
                                </FormField>
                            </SimpleGrid>

                            <FormField label="Описание" error={errors.description}>
                                <Textarea
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    placeholder="Описание условий скидки"
                                    rows={4}
                                />
                            </FormField>

                            <Box>
                                <FormField label="Привязанные товары" error={errors.products}>
                                    <ProductSelector
                                        value={data.products}
                                        onChange={(products) => setData('products', products)}
                                        error={errors.products}
                                    />
                                </FormField>
                            </Box>

                            <Box>
                                <FormField label="Пользователи со скидкой" error={errors.users}>
                                    <Stack gap={3}>
                                        <EntitySelector
                                            searchUrl="admin.discounts.search-users"
                                            placeholder="Поиск пользователя..."
                                            displayField="full_name"
                                            onChange={handleAddUser}
                                        />

                                        {data.users.length > 0 && (
                                            <Box>
                                                <Text fontSize="sm" fontWeight="medium" mb={2}>
                                                    Выбрано пользователей: {data.users.length}
                                                </Text>
                                                <Stack gap={2}>
                                                    {data.users.map((user) => (
                                                        <Box
                                                            key={user.id}
                                                            p={2}
                                                            borderWidth="1px"
                                                            borderRadius="md"
                                                            display="flex"
                                                            justifyContent="space-between"
                                                            alignItems="center"
                                                        >
                                                            <Box>
                                                                <Text fontWeight="medium">{user.full_name}</Text>
                                                                {user.email && (
                                                                    <Text fontSize="xs" color="fg.muted">{user.email}</Text>
                                                                )}
                                                            </Box>
                                                            <Text
                                                                as="button"
                                                                type="button"
                                                                color="red.500"
                                                                cursor="pointer"
                                                                onClick={() => handleRemoveUser(user.id)}
                                                                fontSize="sm"
                                                            >
                                                                Удалить
                                                            </Text>
                                                        </Box>
                                                    ))}
                                                </Stack>
                                            </Box>
                                        )}
                                    </Stack>
                                </FormField>
                            </Box>
                        </Stack>
                    </Card.Body>

                    <Card.Footer>
                        <FormActions
                            onSaveAndClose={handleSaveAndClose}
                            loading={processing}
                            onCancel={() => window.history.back()}
                            submitLabel="Сохранить изменения"
                        />
                    </Card.Footer>
                </Card.Root>
            </form>
        </>
    );
}

Edit.layout = (page) => <AdminLayout>{page}</AdminLayout>;
