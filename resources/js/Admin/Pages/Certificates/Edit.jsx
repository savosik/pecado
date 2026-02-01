import { useForm, router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions } from '@/Admin/Components';
import { Box, Card, Input, Stack, SimpleGrid, Text, HStack, IconButton, Badge } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';
import { LuFile, LuX } from 'react-icons/lu';

export default function Edit({ certificate }) {
    const { data, setData, post, processing, errors } = useForm({
        _method: 'PUT',
        name: certificate.name || '',
        external_id: certificate.external_id || '',
        type: certificate.type || '',
        issued_at: certificate.issued_at || '',
        files: [],
    });

    const handleSubmit = (e) => {
        e.preventDefault();
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

    const handleFileChange = (e) => {
        setData('files', Array.from(e.target.files));
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

    return (
        <AdminLayout>
            <Box p={6}>
                <PageHeader title="Редактировать сертификат" description="Изменение данных сертификата" />

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
                                </SimpleGrid>

                                <Box>
                                    <Text fontWeight="medium" mb={2}>Существующие файлы:</Text>
                                    <Stack gap={2}>
                                        {certificate.media?.map(m => (
                                            <HStack key={m.id} p={2} borderWidth="1px" borderRadius="md" justify="space-between">
                                                <HStack>
                                                    <LuFile />
                                                    <Text fontSize="sm">{m.name}</Text>
                                                </HStack>
                                                <IconButton
                                                    size="xs"
                                                    variant="ghost"
                                                    colorPalette="red"
                                                    onClick={() => removeMedia(m.id)}
                                                    aria-label="Удалить файл"
                                                >
                                                    <LuX />
                                                </IconButton>
                                            </HStack>
                                        ))}
                                        {(!certificate.media || certificate.media.length === 0) && (
                                            <Text fontSize="sm" color="fg.muted">Файлы не загружены</Text>
                                        )}
                                    </Stack>
                                </Box>

                                <FormField label="Добавить новые файлы" error={errors.files}>
                                    <Input
                                        type="file"
                                        multiple
                                        onChange={handleFileChange}
                                        p={1}
                                    />
                                </FormField>
                            </Stack>
                        </Card.Body>

                        <Card.Footer>
                            <FormActions
                                loading={processing}
                                onCancel={() => window.history.back()}
                                submitLabel="Сохранить изменения"
                            />
                        </Card.Footer>
                    </Card.Root>
                </form>
            </Box>
        </AdminLayout>
    );
}
