import { useRef } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, MarkdownEditor } from '@/Admin/Components';
import { Card, Input, Stack, Flex } from '@chakra-ui/react';
import { Switch } from '@/components/ui/switch';
import { toaster } from '@/components/ui/toaster';

export default function Create() {
    const { data, setData, post, processing, errors, transform } = useForm({
        title: '',
        content: '',
        sort_order: 0,
        is_published: true,
    });

    const closeAfterSaveRef = useRef(false);

    transform((data) => ({
        ...data,
        _close: closeAfterSaveRef.current ? 1 : 0,
    }));

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;
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

    const handleSaveAndClose = (e) => {
        handleSubmit(e, true);
    };

    return (
        <>
            <PageHeader title="Создать FAQ" />

            <Card.Root>
                <Card.Body>
                    <form onSubmit={handleSubmit}>
                        <Stack gap={6}>
                            <FormField label="Вопрос" error={errors.title} required>
                                <Input
                                    value={data.title}
                                    onChange={(e) => setData('title', e.target.value)}
                                />
                            </FormField>

                            <FormField label="Ответ" error={errors.content} required>
                                <MarkdownEditor
                                    value={data.content}
                                    onChange={(value) => setData('content', value)}
                                    placeholder="Введите ответ на вопрос..."
                                    context="faq answer"
                                />
                            </FormField>

                            <Flex gap={6}>
                                <FormField label="Порядок сортировки" error={errors.sort_order} flex="1">
                                    <Input
                                        type="number"
                                        min={0}
                                        value={data.sort_order}
                                        onChange={(e) => setData('sort_order', parseInt(e.target.value) || 0)}
                                    />
                                </FormField>

                                <FormField label="Опубликован" error={errors.is_published}>
                                    <Switch
                                        checked={data.is_published}
                                        onCheckedChange={(e) => setData('is_published', e.checked)}
                                        colorPalette="green"
                                        size="lg"
                                        mt="1"
                                    />
                                </FormField>
                            </Flex>

                            <FormActions
                                onSaveAndClose={handleSaveAndClose}
                                submitLabel="Создать FAQ"
                                onCancel={() => window.history.back()}
                                processing={processing}
                            />
                        </Stack>
                    </form>
                </Card.Body>
            </Card.Root>
        </>
    );
}

Create.layout = (page) => <AdminLayout>{page}</AdminLayout>;
