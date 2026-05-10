import { useRef } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, ImageUploader, PhoneInput } from '@/Admin/Components';
import { Box, Card, SimpleGrid, Input, Stack } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';

export default function Create() {
    const { data, setData, post, processing, errors, transform } = useForm({
        name: '',
        phone: '',
        email: '',
        photo: null,
    });

    const closeAfterSaveRef = useRef(false);

    transform((data) => ({
        ...data,
        _close: closeAfterSaveRef.current ? 1 : 0,
    }));

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;
        post(route('admin.personal-managers.store'), {
            onSuccess: () => {
                toaster.create({
                    title: 'Менеджер создан',
                    description: 'Персональный менеджер успешно добавлен',
                    type: 'success',
                });
            },
            onError: () => {
                toaster.create({
                    title: 'Ошибка',
                    description: 'Не удалось создать менеджера. Проверьте правильность заполнения полей.',
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
                title="Добавить персонального менеджера"
                description="Создание новой карточки менеджера"
            />

            <form onSubmit={handleSubmit}>
                <Card.Root>
                    <Card.Body>
                        <Stack gap={6}>
                            <FormField
                                label="Имя"
                                required
                                error={errors.name}
                            >
                                <Input
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    placeholder="Например: Иван Петров"
                                />
                            </FormField>

                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Телефон" error={errors.phone}>
                                    <PhoneInput
                                        value={data.phone}
                                        onChange={(value) => setData('phone', value)}
                                    />
                                </FormField>

                                <FormField label="Email" error={errors.email}>
                                    <Input
                                        type="email"
                                        value={data.email}
                                        onChange={(e) => setData('email', e.target.value)}
                                        placeholder="manager@example.com"
                                    />
                                </FormField>
                            </SimpleGrid>

                            <Box>
                                <Box fontSize="lg" fontWeight="semibold" mb={4}>
                                    Фото менеджера
                                </Box>
                                <ImageUploader
                                    onChange={(file) => setData('photo', file)}
                                    maxPreviewWidth="200px"
                                    aspectRatio="1"
                                    placeholder="Загрузить фото"
                                    error={errors.photo}
                                />
                            </Box>
                        </Stack>
                    </Card.Body>

                    <Card.Footer>
                        <FormActions
                            onSaveAndClose={handleSaveAndClose}
                            loading={processing}
                            onCancel={() => window.history.back()}
                            submitLabel="Создать менеджера"
                        />
                    </Card.Footer>
                </Card.Root>
            </form>
        </>
    );
}

Create.layout = (page) => <AdminLayout>{page}</AdminLayout>;
