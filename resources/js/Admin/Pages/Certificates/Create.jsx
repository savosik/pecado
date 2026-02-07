import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, FileUploader, ProductSelector } from '@/Admin/Components';
import { Box, Card, Input, Stack, SimpleGrid } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';

export default function Create() {
    const { data, setData, post, processing, errors, transform } = useForm({
        name: '',
        external_id: '',
        type: '',
        issued_at: '',
        expires_at: '',
        files: [],
        products: [],
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        transform((data) => ({
            ...data,
            products: data.products.map(p => p.id),
        }));

        post(route('admin.certificates.store'), {
            onSuccess: () => {
                toaster.create({
                    title: 'Сертификат создан',
                    description: 'Сертификат успешно добавлен в систему',
                    type: 'success',
                });
            },
        });
    };



    return (
        <>
            <PageHeader title="Создать сертификат" description="Добавление нового сертификата соответствия" />

            <form onSubmit={handleSubmit}>
                <Card.Root>
                    <Card.Body>
                        <Stack gap={6}>
                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Название *" error={errors.name}>
                                    <Input
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="Например: Сертификат качества №123"
                                    />
                                </FormField>

                                <FormField label="Внешний ID" error={errors.external_id}>
                                    <Input
                                        value={data.external_id}
                                        onChange={(e) => setData('external_id', e.target.value)}
                                        placeholder="ID из внешней системы"
                                    />
                                </FormField>

                                <FormField label="Тип сертификата *" error={errors.type}>
                                    <Input
                                        value={data.type}
                                        onChange={(e) => setData('type', e.target.value)}
                                        placeholder="Например: ISO 9001"
                                    />
                                </FormField>

                                <FormField label="Дата выдачи" error={errors.issued_at}>
                                    <Input
                                        type="date"
                                        value={data.issued_at}
                                        onChange={(e) => setData('issued_at', e.target.value)}
                                    />
                                </FormField>

                                <FormField label="Действует до" error={errors.expires_at}>
                                    <Input
                                        type="date"
                                        value={data.expires_at}
                                        onChange={(e) => setData('expires_at', e.target.value)}
                                    />
                                </FormField>
                            </SimpleGrid>

                            <FileUploader
                                name="files"
                                label="Файлы сертификата"
                                value={data.files}
                                onChange={(files) => setData('files', files)}
                                error={errors.files}
                                maxFiles={5}
                                maxSize={10}
                            />


                            <Box>
                                <FormField label="Привязанные товары" error={errors.products}>
                                    <ProductSelector
                                        value={data.products}
                                        onChange={(products) => setData('products', products)}
                                        error={errors.products}
                                    />
                                </FormField>
                            </Box>
                        </Stack>
                    </Card.Body>

                    <Card.Footer>
                        <FormActions
                            loading={processing}
                            onCancel={() => window.history.back()}
                            submitLabel="Создать сертификат"
                        />
                    </Card.Footer>
                </Card.Root>
            </form>
        </>
    );
}

Create.layout = (page) => <AdminLayout>{page}</AdminLayout>;
