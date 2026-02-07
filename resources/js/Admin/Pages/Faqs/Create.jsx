import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions } from '@/Admin/Components';
import { Card, Input, Textarea, Stack } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';

export default function Create() {
    const { data, setData, post, processing, errors } = useForm({
        title: '',
        content: '',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('admin.faqs.store'), {
            onSuccess: () => {
                toaster.create({
                    title: 'FAQ успешно создан',
                    type: 'success',
                });
            },
            onError: () => {
                toaster.create({
                    title: 'Ошибка при создании FAQ',
                    description: 'Проверьте правильность заполнения полей',
                    type: 'error',
                });
            },
        });
    };

    return (
        <AdminLayout>
            <PageHeader title="Создать FAQ" />

            <Card.Root>
                <Card.Body>
                    <form onSubmit={handleSubmit}>
                        <Stack gap={6}>
                            <FormField label="Вопрос *" error={errors.title} required>
                                <Input
                                    value={data.title}
                                    onChange={(e) => setData('title', e.target.value)}
                                />
                            </FormField>

                            <FormField label="Ответ *" error={errors.content} required>
                                <Textarea
                                    value={data.content}
                                    onChange={(e) => setData('content', e.target.value)}
                                    rows={8}
                                />
                            </FormField>

                            <FormActions
                                submitLabel="Создать FAQ"
                                onCancel={() => window.history.back()}
                                processing={processing}
                            />
                        </Stack>
                    </form>
                </Card.Body>
            </Card.Root>
        </AdminLayout>
    );
}
