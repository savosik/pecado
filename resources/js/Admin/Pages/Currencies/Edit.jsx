import { useRef } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions } from '@/Admin/Components';
import { Box, Card, Input, Stack, SimpleGrid, Text } from '@chakra-ui/react';
import { Checkbox } from '@/components/ui/checkbox';
import { toaster } from '@/components/ui/toaster';

export default function Edit({ currency }) {
    const { data, setData, put, processing, errors, transform } = useForm({
        code: currency.code || '',
        name: currency.name || '',
        symbol: currency.symbol || '',
        is_base: currency.is_base || false,
        official_rate: currency.official_rate || '',
        rate_coefficient: currency.rate_coefficient || '1.0000',
        exchange_rate: currency.exchange_rate || '1.00',
        exchange_rate_date: currency.exchange_rate_date || '',
    });

    const closeAfterSaveRef = useRef(false);

    transform((data) => ({
        ...data,
        _close: closeAfterSaveRef.current ? 1 : 0,
    }));

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;
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

    const handleSaveAndClose = (e) => {
        handleSubmit(e, true);
    };

    return (
        <>
            <PageHeader title={`Редактировать валюту: ${currency.code}`} />

            <Card.Root>
                <Card.Body>
                    <form onSubmit={handleSubmit}>
                        <Stack gap={6}>
                            {/* Основные данные */}
                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Код валюты" error={errors.code} required>
                                    <Input
                                        value={data.code}
                                        onChange={(e) => setData('code', e.target.value.toUpperCase())}
                                        placeholder="USD"
                                        maxLength={10}
                                    />
                                </FormField>

                                <FormField label="Название" error={errors.name} required>
                                    <Input
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="Доллар США"
                                    />
                                </FormField>
                            </SimpleGrid>

                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Символ" error={errors.symbol} required>
                                    <Input
                                        value={data.symbol}
                                        onChange={(e) => setData('symbol', e.target.value)}
                                        placeholder="$"
                                        maxLength={10}
                                    />
                                </FormField>

                                <FormField label="Дата курса (из 1С)" error={errors.exchange_rate_date}>
                                    <Input
                                        type="date"
                                        value={data.exchange_rate_date}
                                        readOnly
                                        disabled
                                    />
                                </FormField>
                            </SimpleGrid>

                            {/* Данные курса — из 1С (readonly) */}
                            <Box>
                                <Text fontSize="sm" fontWeight="semibold" mb={3} color="fg.muted">
                                    Курсовые данные (устанавливаются автоматически из 1С)
                                </Text>
                                <SimpleGrid columns={{ base: 1, md: 3 }} gap={4}>
                                    <FormField label="Официальный курс (НБ)" error={errors.official_rate}>
                                        <Input
                                            type="number"
                                            step="0.0000000001"
                                            value={data.official_rate}
                                            readOnly
                                            disabled
                                            placeholder="—"
                                        />
                                    </FormField>

                                    <FormField label="Поправочный коэффициент" error={errors.rate_coefficient}>
                                        <Input
                                            type="number"
                                            step="0.0001"
                                            value={data.rate_coefficient}
                                            onChange={(e) => setData('rate_coefficient', e.target.value)}
                                            placeholder="1.0000"
                                        />
                                    </FormField>

                                    <FormField label="Итоговый курс" error={errors.exchange_rate} required>
                                        <Input
                                            type="number"
                                            step="0.0000000001"
                                            value={data.exchange_rate}
                                            readOnly
                                            disabled
                                            placeholder="1.00"
                                        />
                                    </FormField>
                                </SimpleGrid>
                                <Text fontSize="xs" color="fg.muted" mt={2}>
                                    Итоговый курс = Официальный курс × Поправочный коэффициент
                                </Text>
                            </Box>

                            <Box>
                                <Checkbox
                                    checked={data.is_base}
                                    onCheckedChange={(e) => setData('is_base', e.checked)}
                                >
                                    Базовая валюта системы
                                </Checkbox>
                            </Box>

                            <FormActions
                                onSaveAndClose={handleSaveAndClose}
                                submitLabel="Сохранить изменения"
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

Edit.layout = (page) => <AdminLayout>{page}</AdminLayout>;
