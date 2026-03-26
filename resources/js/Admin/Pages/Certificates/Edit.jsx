import { useRef } from 'react';
import { useForm, router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, FileUploader, ProductSelector } from '@/Admin/Components';
import { Box, Card, Input, Stack, SimpleGrid, Text, HStack, IconButton, Badge } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';
import { LuFile, LuX } from 'react-icons/lu';

export default function Edit({ certificate }) {
    const { data, setData, post, processing, errors, transform } = useForm({
        _method: 'PUT',
        name: certificate.name || '',
        external_id: certificate.external_id || '',
        type: certificate.type || '',
        issued_at: certificate.issued_at || '',
        expires_at: certificate.expires_at || '',
        files: [],
        products: certificate.products || [],
    });

    const closeAfterSaveRef = useRef(false);

    transform((data) => ({
        ...data,
        _close: closeAfterSaveRef.current ? 1 : 0,
    }));

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;
        transform((data) => ({
            ...data,
            products: data.products.map(p => p.id),
        }));

        post(route('admin.certificates.update', certificate.id), {
            onSuccess: () => {
                toaster.create({
                    title: 'Сертификат обновлен',
                    description: 'Изменения успешно сохранены',
                    type: 'success',
                });
            },
        });
    };



    const removeMedia = (mediaId) => {
        if (confirm('Вы уверены, что хотите удалить этот файл?')) {
            router.post(route('admin.certificates.delete-media', certificate.id), {
                media_id: mediaId
            }, {
                onSuccess: () => {
                    toaster.create({
                        title: 'Файл удален',
                        type: 'success',
                    });
                }
            });
        }
    };

    const handleSaveAndClose = (e) => {
        handleSubmit(e, true);
    };

    return (
        <>
                <PageHeader title="Редактировать сертификат" description="Изменение данных сертификата" />

                <form onSubmit={handleSubmit}>
                    <Card.Root>
                        <Card.Body>
                            <Stack gap={6}>
                                <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                    <FormField label="Название" error={errors.name}>
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

                                    <FormField label="Тип сертификата" error={errors.type}>
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

                                <Box>
                                    <FileUploader
                                        name="files"
                                        label="Файлы сертификата"
                                        value={data.files}
                                        onChange={(files) => setData('files', files)}
                                        existingFiles={certificate.media?.map(m => ({
                                            id: m.id,
                                            url: m.original_url,
                                            name: m.file_name,
                                            size: m.size,
                                            mime_type: m.mime_type
                                        }))}
                                        onRemoveExisting={removeMedia}
                                        error={errors.files}
                                        maxFiles={10}
                                        maxSize={10}
                                    />
                                </Box>

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
