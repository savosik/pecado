import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions } from '@/Admin/Components';
import { Card, Input, Stack, Textarea } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';

export default function Create() {
    const { data, setData, post, processing, errors } = useForm({
        title: '',
        task_body: '',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('admin.agent-topics.store'), {
            onError: () => {
                toaster.create({
                    title: 'Ошибка при создании топика',
                    description: 'Проверьте правильность заполнения полей',
                    type: 'error',
                });
            },
        });
    };

    return (
        <>
            <PageHeader title="Создать топик" />

            <Card.Root>
                <Card.Body>
                    <form onSubmit={handleSubmit}>
                        <Stack gap={6}>
                            <FormField label="Название" error={errors.title} required>
                                <Input
                                    value={data.title}
                                    onChange={(e) => setData('title', e.target.value)}
                                    placeholder="Краткая суть задачи, например: Сверка остатков по складу Тюмень"
                                />
                            </FormField>

                            <FormField
                                label="Постановка задачи для агентов"
                                error={errors.task_body}
                                required
                                helpText="Markdown. Опишите, что нужно сделать, и критерии готовности — оба агента увидят этот текст по своим ссылкам."
                            >
                                <Textarea
                                    value={data.task_body}
                                    onChange={(e) => setData('task_body', e.target.value)}
                                    placeholder={'Что сделать...\n\nКритерии готовности:\n- ...'}
                                    rows={12}
                                />
                            </FormField>

                            <FormActions
                                submitLabel="Создать топик"
                                onCancel={() => window.history.back()}
                                isLoading={processing}
                                showSaveAndClose={false}
                            />
                        </Stack>
                    </form>
                </Card.Body>
            </Card.Root>
        </>
    );
}

Create.layout = (page) => <AdminLayout>{page}</AdminLayout>;
