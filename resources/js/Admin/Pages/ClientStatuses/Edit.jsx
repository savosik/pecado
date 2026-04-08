import { useRef } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, ImageUploader } from '@/Admin/Components';
import { Box, Card, SimpleGrid, Input, Textarea, Stack, HStack, Badge } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';

export default function Edit({ clientStatus }) {
    const { data, setData, post, processing, errors, transform } = useForm({
        name: clientStatus.name || '',
        color: clientStatus.color || '#C0C0C0',
        description: clientStatus.description || '',
        amount_from: clientStatus.amount_from || '',
        external_id: clientStatus.external_id || '',
        image: null,
        _method: 'PUT',
    });

    const closeAfterSaveRef = useRef(false);

    transform((data) => ({
        ...data,
        _close: closeAfterSaveRef.current ? 1 : 0,
    }));

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;
        post(route('admin.client-statuses.update', clientStatus.id), {
            onSuccess: () => {
                toaster.create({
                    title: 'Статус обновлён',
                    description: 'Статус клиента успешно обновлён',
                    type: 'success',
                });
            },
            onError: () => {
                toaster.create({
                    title: 'Ошибка',
                    description: 'Не удалось обновить статус. Проверьте правильность заполнения полей.',
                    type: 'error',
                });
            },
        });
    };

    const handleSaveAndClose = (e) => {
        handleSubmit(e, true);
    };

    const handleDeleteMedia = async () => {
        if (!clientStatus.image_id) return;
        try {
            const response = await fetch(route('admin.client-statuses.media.delete', clientStatus.id), {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
                },
                body: JSON.stringify({ media_id: clientStatus.image_id }),
            });
            if (response.ok) {
                toaster.create({
                    title: 'Изображение удалено',
                    type: 'success',
                });
                window.location.reload();
            }
        } catch {
            toaster.create({
                title: 'Ошибка удаления',
                type: 'error',
            });
        }
    };

    return (
        <>
            <PageHeader
                title={`Редактирование: ${clientStatus.name}`}
                description="Изменение статуса клиента"
            />

            <form onSubmit={handleSubmit}>
                <Card.Root>
                    <Card.Body>
                        <Stack gap={6}>
                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField
                                    label="Название"
                                    required
                                    error={errors.name}
                                >
                                    <Input
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="Введите название статуса"
                                    />
                                </FormField>

                                <FormField
                                    label="Сумма от"
                                    error={errors.amount_from}
                                    helperText="Минимальная сумма покупок для получения статуса"
                                >
                                    <Input
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        value={data.amount_from}
                                        onChange={(e) => setData('amount_from', e.target.value)}
                                        placeholder="0.00"
                                    />
                                </FormField>
                            </SimpleGrid>

                            <FormField
                                label="Цвет статуса"
                                error={errors.color}
                                helperText="HEX цвет для отображения рамки аватарки клиента"
                            >
                                <HStack gap={3}>
                                    <Box
                                        as="input"
                                        type="color"
                                        value={data.color || '#C0C0C0'}
                                        onChange={(e) => setData('color', e.target.value)}
                                        w="50px"
                                        h="40px"
                                        p="1"
                                        borderRadius="lg"
                                        border="1px solid"
                                        borderColor="gray.200"
                                        cursor="pointer"
                                    />
                                    <Input
                                        value={data.color || ''}
                                        onChange={(e) => setData('color', e.target.value)}
                                        placeholder="#FFD700"
                                        maxLength={7}
                                        w="140px"
                                        fontFamily="mono"
                                    />
                                    {data.color && (
                                        <Badge
                                            px={3}
                                            py={1}
                                            borderRadius="full"
                                            bg={data.color}
                                            color="white"
                                            fontSize="xs"
                                            fontWeight="600"
                                            textShadow="0 1px 2px rgba(0,0,0,0.3)"
                                        >
                                            Превью
                                        </Badge>
                                    )}
                                </HStack>
                            </FormField>

                            <FormField
                                label="Внешний ИД"
                                error={errors.external_id}
                                helperText="Идентификатор из внешней системы"
                            >
                                <Input
                                    value={data.external_id}
                                    onChange={(e) => setData('external_id', e.target.value)}
                                    placeholder="Внешний идентификатор"
                                />
                            </FormField>

                            <FormField
                                label="Описание"
                                error={errors.description}
                            >
                                <Textarea
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    placeholder="Описание статуса клиента"
                                    rows={4}
                                />
                            </FormField>

                            <Box>
                                <Box fontSize="lg" fontWeight="semibold" mb={4}>
                                    Изображение статуса
                                </Box>
                                <ImageUploader
                                    onChange={(file) => setData('image', file)}
                                    currentImageUrl={clientStatus.image_url}
                                    onDeleteCurrent={clientStatus.image_id ? handleDeleteMedia : undefined}
                                    maxPreviewWidth="200px"
                                    aspectRatio="1"
                                    placeholder="Загрузить изображение"
                                    error={errors.image}
                                />
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
