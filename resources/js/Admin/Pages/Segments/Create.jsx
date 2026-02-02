import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, ImageUploader } from '@/Admin/Components';
import { Box, Card, Input, Stack, SimpleGrid } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';

export default function Create() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        meta_title: '',
        meta_description: '',
        desktop_image: null,
        mobile_image: null,
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('admin.segments.store'), {
            onSuccess: () => {
                toaster.create({
                    title: 'Сегмент создан',
                    description: 'Сегмент успешно добавлен в систему',
                    type: 'success',
                });
            },
        });
    };

    return (
        <AdminLayout
            breadcrumbs={[
                { label: 'Главная', href: route('admin.dashboard') },
                { label: 'Сегменты', href: route('admin.segments.index') },
                { label: 'Создать' },
            ]}
        >
            <Box p={6}>
                <PageHeader title="Создать сегмент" description="Добавление нового сегмента или коллекции" />

                <form onSubmit={handleSubmit}>
                    <Card.Root>
                        <Card.Body>
                            <Stack gap={6}>
                                <FormField label="Название *" error={errors.name}>
                                    <Input
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="Например: Новая коллекция"
                                    />
                                </FormField>

                                <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                    <FormField label="Meta Title" error={errors.meta_title}>
                                        <Input
                                            value={data.meta_title}
                                            onChange={(e) => setData('meta_title', e.target.value)}
                                            placeholder="SEO заголовок"
                                        />
                                    </FormField>

                                    <FormField label="Meta Description" error={errors.meta_description}>
                                        <Input
                                            value={data.meta_description}
                                            onChange={(e) => setData('meta_description', e.target.value)}
                                            placeholder="SEO описание"
                                        />
                                    </FormField>
                                </SimpleGrid>

                                <SimpleGrid columns={{ base: 1, md: 2 }} gap={6}>
                                    <Box>
                                        <Box fontWeight="medium" mb={2}>Баннер (Десктоп)</Box>
                                        <ImageUploader
                                            onChange={(file) => setData('desktop_image', file)}
                                            placeholder="Загрузить баннер для ПК"
                                            error={errors.desktop_image}
                                            aspectRatio="16/9"
                                        />
                                    </Box>
                                    <Box>
                                        <Box fontWeight="medium" mb={2}>Баннер (Мобильный)</Box>
                                        <ImageUploader
                                            onChange={(file) => setData('mobile_image', file)}
                                            placeholder="Загрузить баннер для мобильных"
                                            error={errors.mobile_image}
                                            aspectRatio="3/4"
                                        />
                                    </Box>
                                </SimpleGrid>
                            </Stack>
                        </Card.Body>

                        <Card.Footer>
                            <FormActions
                                loading={processing}
                                onCancel={() => window.history.back()}
                                submitLabel="Создать сегмент"
                            />
                        </Card.Footer>
                    </Card.Root>
                </form>
            </Box>
        </AdminLayout>
    );
}
