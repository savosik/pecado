import { useRef } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, ProductSelector, EntitySelector } from '@/Admin/Components';
import { Box, Card, Input, NativeSelect, Stack, SimpleGrid, Text } from '@chakra-ui/react';
import { Checkbox } from '@/components/ui/checkbox';
import { toaster } from '@/components/ui/toaster';

export default function Edit({ discount, typeOptions }) {
    const { data, setData, post, processing, errors, transform } = useForm({
        _method: 'PUT',
        name: discount.name || '',
        type: discount.type || '',
        external_id: discount.external_id || '',
        percentage: discount.percentage || '',
        is_posted: discount.is_posted || false,
        starts_at: discount.starts_at || '',
        ends_at: discount.ends_at || '',
        products: discount.products || [],
        users: discount.users || [],
        product_segments: discount.product_segments || [],
        partner_segments: discount.partner_segments || [],
    });

    const closeAfterSaveRef = useRef(false);

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;

        transform((data) => ({
            ...data,
            _close: closeAfterSaveRef.current ? 1 : 0,
            product_ids: data.products.map(p => p.id),
            user_ids: data.users.map(u => u.id),
            product_segment_ids: data.product_segments.map(s => s.id),
            partner_segment_ids: data.partner_segments.map(s => s.id),
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

    const handleAddProductSegment = (segment) => {
        if (segment && !data.product_segments.find(s => s.id === segment.id)) {
            setData('product_segments', [...data.product_segments, segment]);
        }
    };

    const handleRemoveProductSegment = (segmentId) => {
        setData('product_segments', data.product_segments.filter(s => s.id !== segmentId));
    };

    const handleAddPartnerSegment = (segment) => {
        if (segment && !data.partner_segments.find(s => s.id === segment.id)) {
            setData('partner_segments', [...data.partner_segments, segment]);
        }
    };

    const handleRemovePartnerSegment = (segmentId) => {
        setData('partner_segments', data.partner_segments.filter(s => s.id !== segmentId));
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
                                        placeholder="UUID из 1С"
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

                                <FormField label="Тип скидки" error={errors.type}>
                                    <NativeSelect.Root>
                                        <NativeSelect.Field
                                            value={data.type}
                                            onChange={(e) => setData('type', e.target.value)}
                                        >
                                            {typeOptions.map((opt) => (
                                                <option key={opt.value} value={opt.value}>{opt.label}</option>
                                            ))}
                                        </NativeSelect.Field>
                                    </NativeSelect.Root>
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

                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Дата начала" error={errors.starts_at}>
                                    <Input
                                        type="datetime-local"
                                        value={data.starts_at}
                                        onChange={(e) => setData('starts_at', e.target.value)}
                                    />
                                </FormField>

                                <FormField label="Дата окончания" error={errors.ends_at}>
                                    <Input
                                        type="datetime-local"
                                        value={data.ends_at}
                                        onChange={(e) => setData('ends_at', e.target.value)}
                                    />
                                </FormField>
                            </SimpleGrid>

                            {/* Товары */}
                            <Box>
                                <FormField label="Привязанные товары" error={errors.products}>
                                    <ProductSelector
                                        value={data.products}
                                        onChange={(products) => setData('products', products)}
                                        error={errors.products}
                                    />
                                </FormField>
                            </Box>

                            {/* Партнёры */}
                            <Box>
                                <FormField label="Партнёры со скидкой" error={errors.users}>
                                    <Stack gap={3} w="100%">
                                        <EntitySelector
                                            searchUrl="admin.discounts.search-users"
                                            placeholder="Введите имя или email партнёра..."
                                            onChange={handleAddUser}
                                        />

                                        {data.users.length > 0 && (
                                            <Box>
                                                <Text fontSize="sm" fontWeight="medium" mb={2}>
                                                    Выбрано партнёров: {data.users.length}
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
                                                                <Text fontWeight="medium">{user.name}</Text>
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

                            {/* Сегменты номенклатуры */}
                            <Box>
                                <FormField label="Сегменты товаров" error={errors.product_segments}>
                                    <Stack gap={3} w="100%">
                                        <EntitySelector
                                            searchUrl="admin.discounts.search-product-segments"
                                            placeholder="Поиск сегмента товаров..."
                                            onChange={handleAddProductSegment}
                                        />

                                        {data.product_segments.length > 0 && (
                                            <Box>
                                                <Text fontSize="sm" fontWeight="medium" mb={2}>
                                                    Выбрано сегментов товаров: {data.product_segments.length}
                                                </Text>
                                                <Stack gap={2}>
                                                    {data.product_segments.map((segment) => (
                                                        <Box
                                                            key={segment.id}
                                                            p={2}
                                                            borderWidth="1px"
                                                            borderRadius="md"
                                                            display="flex"
                                                            justifyContent="space-between"
                                                            alignItems="center"
                                                        >
                                                            <Box>
                                                                <Text fontWeight="medium">{segment.name}</Text>
                                                                {segment.uuid && (
                                                                    <Text fontSize="xs" color="fg.muted">UUID: {segment.uuid}</Text>
                                                                )}
                                                            </Box>
                                                            <Text
                                                                as="button"
                                                                type="button"
                                                                color="red.500"
                                                                cursor="pointer"
                                                                onClick={() => handleRemoveProductSegment(segment.id)}
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

                            {/* Сегменты партнёров */}
                            <Box>
                                <FormField label="Сегменты партнёров" error={errors.partner_segments}>
                                    <Stack gap={3} w="100%">
                                        <EntitySelector
                                            searchUrl="admin.discounts.search-partner-segments"
                                            placeholder="Поиск сегмента партнёров..."
                                            onChange={handleAddPartnerSegment}
                                        />

                                        {data.partner_segments.length > 0 && (
                                            <Box>
                                                <Text fontSize="sm" fontWeight="medium" mb={2}>
                                                    Выбрано сегментов партнёров: {data.partner_segments.length}
                                                </Text>
                                                <Stack gap={2}>
                                                    {data.partner_segments.map((segment) => (
                                                        <Box
                                                            key={segment.id}
                                                            p={2}
                                                            borderWidth="1px"
                                                            borderRadius="md"
                                                            display="flex"
                                                            justifyContent="space-between"
                                                            alignItems="center"
                                                        >
                                                            <Box>
                                                                <Text fontWeight="medium">{segment.name}</Text>
                                                                {segment.uuid && (
                                                                    <Text fontSize="xs" color="fg.muted">UUID: {segment.uuid}</Text>
                                                                )}
                                                            </Box>
                                                            <Text
                                                                as="button"
                                                                type="button"
                                                                color="red.500"
                                                                cursor="pointer"
                                                                onClick={() => handleRemovePartnerSegment(segment.id)}
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
