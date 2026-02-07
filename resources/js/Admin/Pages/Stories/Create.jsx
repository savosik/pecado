import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components';
import { FormField } from '@/Admin/Components/FormField';
import { Card, Input, Button, HStack, Stack } from '@chakra-ui/react';
import { Switch } from '@/components/ui/switch';
import { LuSave, LuX } from 'react-icons/lu';
import { router } from '@inertiajs/react';
import { useEffect } from 'react';

export default function Create() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        slug: '',
        is_active: true,
        sort_order: 0,
    });

    // Auto-generate slug from name
    useEffect(() => {
        if (data.name && !data.slug) {
            const slug = data.name
                .toLowerCase()
                .replace(/[^a-z0-9а-я]+/g, '-')
                .replace(/(^-|-$)/g, '');
            setData('slug', slug);
        }
    }, [data.name]);

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('admin.stories.store'));
    };

    return (
        <AdminLayout>
            <PageHeader
                title="Создать сторис"
                description="После создания вы сможете добавить слайды"
            />

            <Card.Root as="form" onSubmit={handleSubmit}>
                <Card.Body>
                    <Stack gap={4}>
                        <FormField label="Название" required error={errors.name}>
                            <Input
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder="Название сториса"
                            />
                        </FormField>

                        <FormField label="Slug" error={errors.slug}>
                            <Input
                                value={data.slug}
                                onChange={(e) => setData('slug', e.target.value)}
                                placeholder="URL-адрес (автоматически)"
                            />
                        </FormField>

                        <FormField label="Активность" error={errors.is_active}>
                            <Switch
                                checked={data.is_active}
                                onCheckedChange={(e) => setData('is_active', e.checked)}
                            >
                                Активен
                            </Switch>
                        </FormField>

                        <FormField label="Порядок сортировки" error={errors.sort_order}>
                            <Input
                                type="number"
                                value={data.sort_order}
                                onChange={(e) => setData('sort_order', parseInt(e.target.value) || 0)}
                            />
                        </FormField>
                    </Stack>
                </Card.Body>

                <Card.Footer>
                    <HStack gap={3}>
                        <Button
                            type="submit"
                            colorPalette="blue"
                            loading={processing}
                        >
                            <LuSave />
                            Создать
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => router.visit(route('admin.stories.index'))}
                        >
                            <LuX />
                            Отмена
                        </Button>
                    </HStack>
                </Card.Footer>
            </Card.Root>
        </AdminLayout>
    );
}
