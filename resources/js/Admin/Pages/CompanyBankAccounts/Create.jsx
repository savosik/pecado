import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, EntitySelector } from '@/Admin/Components';
import { Box, Card, Input, Stack, SimpleGrid, Checkbox } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';

export default function Create() {
    const { data, setData, post, processing, errors } = useForm({
        company_id: '',
        bank_name: '',
        bank_bik: '',
        correspondent_account: '',
        account_number: '',
        is_primary: false,
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('admin.company-bank-accounts.store'), {
            onSuccess: () => {
                toaster.create({
                    title: 'Счет создан',
                    description: 'Банковский счет успешно добавлен',
                    type: 'success',
                });
            },
        });
    };

    return (
        <>
                <PageHeader title="Создать банковский счет" description="Добавление нового счета компании" />

                <form onSubmit={handleSubmit}>
                    <Card.Root>
                        <Card.Body>
                            <Stack gap={6}>
                                <FormField label="Компания *" error={errors.company_id} required>
                                    <EntitySelector
                                        searchUrl={route('admin.companies.search')}
                                        placeholder="Выберите компанию"
                                        value={data.company_id}
                                        onChange={(value) => setData('company_id', value)}
                                        error={errors.company_id}
                                    />
                                </FormField>

                                <FormField label="Название банка *" error={errors.bank_name} required>
                                    <Input
                                        value={data.bank_name}
                                        onChange={(e) => setData('bank_name', e.target.value)}
                                        placeholder="ПАО Сбербанк"
                                    />
                                </FormField>

                                <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                    <FormField label="БИК" error={errors.bank_bik}>
                                        <Input
                                            value={data.bank_bik}
                                            onChange={(e) => setData('bank_bik', e.target.value)}
                                            placeholder="044525225"
                                        />
                                    </FormField>

                                    <FormField label="Корреспондентский счет" error={errors.correspondent_account}>
                                        <Input
                                            value={data.correspondent_account}
                                            onChange={(e) => setData('correspondent_account', e.target.value)}
                                            placeholder="30101810400000000225"
                                        />
                                    </FormField>
                                </SimpleGrid>

                                <FormField label="Номер счета *" error={errors.account_number} required>
                                    <Input
                                        value={data.account_number}
                                        onChange={(e) => setData('account_number', e.target.value)}
                                        placeholder="40702810138000000000"
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
                                submitLabel="Создать счет"
                            />
                        </Card.Footer>
                    </Card.Root>
                </form>
        </>
    );
}

Create.layout = (page) => <AdminLayout>{page}</AdminLayout>;
