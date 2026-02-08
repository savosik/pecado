import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, EntitySelector } from '@/Admin/Components';
import { Box, Card, Input, Stack, SimpleGrid } from '@chakra-ui/react';
import { Checkbox } from '@/components/ui/checkbox';
import { toaster } from '@/components/ui/toaster';

export default function Edit({ bankAccount }) {
    const { data, setData, put, processing, errors } = useForm({
        company_id: bankAccount.company_id || '',
        bank_name: bankAccount.bank_name || '',
        bank_bik: bankAccount.bank_bik || '',
        correspondent_account: bankAccount.correspondent_account || '',
        account_number: bankAccount.account_number || '',
        is_primary: bankAccount.is_primary || false,
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        put(route('admin.company-bank-accounts.update', bankAccount.id), {
            onSuccess: () => {
                toaster.create({
                    title: 'Счет обновлен',
                    description: 'Информация о банковском счете успешно обновлена',
                    type: 'success',
                });
            },
        });
    };

    return (
        <>
            <PageHeader
                title={`Редактирование счета ${bankAccount.account_number}`}
                description="Изменение информации о банковском счете"
            />

            <form onSubmit={handleSubmit}>
                <Card.Root>
                    <Card.Body>
                        <Stack gap={6}>
                            <FormField label="Компания" error={errors.company_id} required>
                                <EntitySelector
                                    searchUrl={route('admin.companies.search')}
                                    placeholder="Выберите компанию"
                                    value={data.company_id}
                                    onChange={(value) => setData('company_id', value)}
                                    error={errors.company_id}
                                    initialDisplay={bankAccount.company?.name}
                                    valueKey="id"
                                    displayField="name"
                                />
                            </FormField>

                            <FormField label="Название банка" error={errors.bank_name} required>
                                <Input
                                    value={data.bank_name}
                                    onChange={(e) => setData('bank_name', e.target.value)}
                                />
                            </FormField>

                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="БИК" error={errors.bank_bik}>
                                    <Input
                                        value={data.bank_bik}
                                        onChange={(e) => setData('bank_bik', e.target.value)}
                                    />
                                </FormField>

                                <FormField label="Корреспондентский счет" error={errors.correspondent_account}>
                                    <Input
                                        value={data.correspondent_account}
                                        onChange={(e) => setData('correspondent_account', e.target.value)}
                                    />
                                </FormField>
                            </SimpleGrid>

                            <FormField label="Номер счета" error={errors.account_number} required>
                                <Input
                                    value={data.account_number}
                                    onChange={(e) => setData('account_number', e.target.value)}
                                />
                            </FormField>

                            <Checkbox
                                checked={data.is_primary}
                                onCheckedChange={(e) => setData('is_primary', e.checked)}
                            >
                                Основной счет компании
                            </Checkbox>
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
        </>
    );
}

Edit.layout = (page) => <AdminLayout>{page}</AdminLayout>;
