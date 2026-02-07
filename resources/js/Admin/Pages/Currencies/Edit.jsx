import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions } from '@/Admin/Components';
import { Box, Card, Input, Stack, SimpleGrid, Checkbox } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';

export default function Edit({ currency }) {
    const { data, setData, put, processing, errors } = useForm({
        code: currency.code || '',
        name: currency.name || '',
        symbol: currency.symbol || '',
        is_base: currency.is_base || false,
        exchange_rate: currency.exchange_rate || '1.00',
        correction_factor: currency.correction_factor || '1.0000',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        put(route('admin.currencies.update', currency.id), {
            onSuccess: () => {
                toaster.create({
                    title: 'Валюта успешно обновлена',
                    type: 'success',
                });
            },
            onError: () => {
                toaster.create({
                    title: 'Ошибка при обновлении валюты',
                    description: 'Проверьте правильность заполнения полей',
                    type: 'error',
                });
            },
        });
    };

    return (
        <AdminLayout>
            <PageHeader title={`Редактировать валюту: ${currency.code}`} />

            <Card.Root>
                <Card.Body>
                    <form onSubmit={handleSubmit}>
                        <Stack gap={6}>
                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Код валюты *" error={errors.code} required>
                                    <Input
                                        value={data.code}
                                        onChange={(e) => setData('code', e.target.value.toUpperCase())}
                                        placeholder="USD"
                                        maxLength={10}
                                    />
                                </FormField>

                                <FormField label="Название *" error={errors.name} required>
                                    <Input
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="Доллар США"
                                    />
                                </FormField>
                            </SimpleGrid>

                            <SimpleGrid columns={{ base: 1, md: 3 }} gap={4}>
                                <FormField label="Символ *" error={errors.symbol} required>
                                    <Input
                                        value={data.symbol}
                                        onChange={(e) => setData('symbol', e.target.value)}
                                        placeholder="$"
                                        maxLength={10}
                                    />
                                </FormField>

                                <FormField label="Курс обмена *" error={errors.exchange_rate} required>
                                    <Input
                                        type="number"
                                        step="0.0000000001"
                                        value={data.exchange_rate}
                                        onChange={(e) => setData('exchange_rate', e.target.value)}
                                        placeholder="1.00"
                                    />
                                </FormField>

                                <FormField label="Коррекционный фактор" error={errors.correction_factor}>
                                    <Input
                                        type="number"
                                        step="0.0001"
                                        value={data.correction_factor}
                                        onChange={(e) => setData('correction_factor', e.target.value)}
                                        placeholder="1.0000"
                                    />
                                </FormField>
                            </SimpleGrid>

                            <Box>
                                <Checkbox
                                    checked={data.is_base}
                                    onCheckedChange={(e) => setData('is_base', e.checked)}
                                >
                                    Базовая валюта системы
                                </Checkbox>
                            </Box>

                            <FormActions
                                submitLabel="Сохранить изменения"
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
