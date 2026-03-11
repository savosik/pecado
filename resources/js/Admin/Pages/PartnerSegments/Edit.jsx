import { useRef } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions } from '@/Admin/Components';
import { EntitySelector } from '@/Admin/Components/EntitySelector';
import {
    Box, Card, Input, Stack, SimpleGrid,
    HStack, Text, IconButton, VStack, Badge,
} from '@chakra-ui/react';
import { FaTimes } from 'react-icons/fa';
import { toaster } from '@/components/ui/toaster';

export default function Edit({ segment }) {
    const { data, setData, post, processing, errors, transform } = useForm({
        _method: 'PUT',
        name: segment.name || '',
        uuid: segment.uuid || '',
        users: segment.users || [],
    });

    const closeAfterSaveRef = useRef(false);

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;

        transform((data) => ({
            ...data,
            _close: closeAfterSaveRef.current ? 1 : 0,
            user_ids: data.users.map(u => u.id),
        }));

        post(route('admin.partner-segments.update', segment.id), {
            onSuccess: () => {
                toaster.create({
                    title: 'Сегмент обновлён',
                    description: 'Информация о сегменте партнёров успешно обновлена',
                    type: 'success',
                });
            },
            onError: () => {
                toaster.create({
                    title: 'Ошибка',
                    description: 'Не удалось обновить сегмент. Проверьте правильность заполнения полей.',
                    type: 'error',
                });
            },
        });
    };

    const handleSaveAndClose = (e) => {
        handleSubmit(e, true);
    };

    const handleUserSelect = (user) => {
        if (!user) return;
        if (data.users.find(u => u.id === user.id)) return; // уже добавлен
        setData('users', [...data.users, user]);
    };

    const handleUserRemove = (userId) => {
        setData('users', data.users.filter(u => u.id !== userId));
    };

    return (
        <>
            <PageHeader
                title={`Редактирование: ${segment.name}`}
                description="Изменение информации о сегменте партнёров"
            />

            <form onSubmit={handleSubmit}>
                <Card.Root>
                    <Card.Body>
                        <Stack gap={6}>
                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Название" error={errors.name} required>
                                    <Input
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="Например: Уровень Голд"
                                    />
                                </FormField>

                                <FormField label="UUID (из 1С)" error={errors.uuid}>
                                    <Input
                                        value={data.uuid}
                                        onChange={(e) => setData('uuid', e.target.value)}
                                        placeholder="seg-part-001"
                                        fontFamily="mono"
                                    />
                                </FormField>
                            </SimpleGrid>

                            {segment.updated_at && (
                                <Text fontSize="xs" color="fg.muted">
                                    Последнее обновление: {segment.updated_at}
                                </Text>
                            )}

                            <Box>
                                <FormField label="Партнёры в сегменте" error={errors.users}>
                                    <EntitySelector
                                        value={null}
                                        onChange={handleUserSelect}
                                        searchUrl={route('admin.partner-segments.search-users')}
                                        placeholder="Начните вводить имя или email партнёра..."
                                        displayField="label"
                                        error={errors.users}
                                    />

                                    {data.users.length > 0 && (
                                        <VStack align="stretch" gap={2} mt={3}>
                                            {data.users.map((user) => (
                                                <HStack
                                                    key={user.id}
                                                    p={3}
                                                    borderWidth="1px"
                                                    borderRadius="md"
                                                    justify="space-between"
                                                >
                                                    <Box>
                                                        <Text fontSize="sm" fontWeight="medium">{user.name}</Text>
                                                        <Text fontSize="xs" color="fg.muted">{user.email}</Text>
                                                    </Box>
                                                    <IconButton
                                                        aria-label="Удалить"
                                                        size="sm"
                                                        variant="ghost"
                                                        colorPalette="red"
                                                        onClick={() => handleUserRemove(user.id)}
                                                    >
                                                        <FaTimes />
                                                    </IconButton>
                                                </HStack>
                                            ))}
                                        </VStack>
                                    )}

                                    {data.users.length > 0 && (
                                        <Badge mt={2} colorPalette="green">
                                            Выбрано: {data.users.length}
                                        </Badge>
                                    )}
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
