import { useRef } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, ImageUploader } from '@/Admin/Components';
import { Box, Card, SimpleGrid, Input, Textarea, Stack } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';

export default function Create() {
    const { data, setData, post, processing, errors, transform } = useForm({
        name: '',
        description: '',
        amount_from: '',
        external_id: '',
        image: null,
    });

    const closeAfterSaveRef = useRef(false);

    transform((data) => ({
        ...data,
        _close: closeAfterSaveRef.current ? 1 : 0,
    }));

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;
        post(route('admin.client-statuses.store'), {
            onSuccess: () => {
                toaster.create({
                    title: 'Статус создан',
                    description: 'Статус клиента успешно добавлен в систему',
                    type: 'success',
                });
            },
            onError: () => {
                toaster.create({
                    title: 'Ошибка',
                    description: 'Не удалось создать статус. Проверьте правильность заполнения полей.',
                    type: 'error',
                });
            },
        });
    };

    const handleSaveAndClose = (e) => {
        handleSubmit(e, true);
    };

    return (
        <>
            <PageHeader
                title="Создать статус клиента"
                description="Добавление нового статуса клиента"
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
                            submitLabel="Создать статус"
                        />
                    </Card.Footer>
                </Card.Root>
            </form>
        </>
    );
}

Create.layout = (page) => <AdminLayout>{page}</AdminLayout>;
