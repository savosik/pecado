import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, EntitySelector } from '@/Admin/Components';
import { Box, Card, Input, Textarea, Stack, SimpleGrid } from '@chakra-ui/react';

import { toaster } from '@/components/ui/toaster';

export default function Edit({ company, countries }) {
    const { data, setData, put, processing, errors } = useForm({
        user_id: company.user_id || '',
        country: company.country || '',
        name: company.name || '',
        legal_name: company.legal_name || '',
        tax_id: company.tax_id || '',
        registration_number: company.registration_number || '',
        tax_code: company.tax_code || '',
        okpo_code: company.okpo_code || '',
        legal_address: company.legal_address || '',
        actual_address: company.actual_address || '',
        phone: company.phone || '',
        email: company.email || '',
        erp_id: company.erp_id || '',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        put(route('admin.companies.update', company.id), {
            onSuccess: () => {
                toaster.create({
                    title: 'Компания обновлена',
                    description: 'Информация о компании успешно обновлена',
                    type: 'success',
                });
            },
        });
    };

    return (
        <>
                <PageHeader
                    title={`Редактирование: ${company.name}`}
                    description="Изменение информации о компании"
                />

                <form onSubmit={handleSubmit}>
                    <Card.Root>
                        <Card.Body>
                            <Stack gap={6}>
                                <FormField label="Пользователь" error={errors.user_id} required>
                                    <EntitySelector
                                        searchUrl={route('admin.users.search')}
                                        placeholder="Выберите пользователя"
                                        value={data.user_id}
                                        onChange={(value) => setData('user_id', value)}
                                        error={errors.user_id}
                                        initialDisplay={company.user?.name}
                                    />
                                </FormField>

                                <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                    <FormField label="Название" error={errors.name} required>
                                        <Input
                                            value={data.name}
                                            onChange={(e) => setData('name', e.target.value)}
                                        />
                                    </FormField>

                                    <FormField label="Страна" error={errors.country} required>
                                        <select
                                            value={data.country}
                                            onChange={(e) => setData('country', e.target.value)}
                                            style={{ width: '100%', padding: '0.5rem', borderRadius: '0.375rem', border: '1px solid var(--chakra-colors-border)' }}
                                        >
                                            <option value="">Выберите страну</option>
                                            {countries.map((country) => (
                                                <option key={country.value} value={country.value}>
                                                    {country.label}
                                                </option>
                                            ))}
                                        </select>
                                    </FormField>
                                </SimpleGrid>

                                <FormField label="Юридическое название" error={errors.legal_name}>
                                    <Input
                                        value={data.legal_name}
                                        onChange={(e) => setData('legal_name', e.target.value)}
                                    />
                                </FormField>

                                <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                    <FormField label="ИНН" error={errors.tax_id}>
                                        <Input
                                            value={data.tax_id}
                                            onChange={(e) => setData('tax_id', e.target.value)}
                                        />
                                    </FormField>

                                    <FormField label="ОГРН" error={errors.registration_number}>
                                        <Input
                                            value={data.registration_number}
                                            onChange={(e) => setData('registration_number', e.target.value)}
                                        />
                                    </FormField>
                                </SimpleGrid>

                                <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                    <FormField label="КПП" error={errors.tax_code}>
                                        <Input
                                            value={data.tax_code}
                                            onChange={(e) => setData('tax_code', e.target.value)}
                                        />
                                    </FormField>

                                    <FormField label="ОКПО" error={errors.okpo_code}>
                                        <Input
                                            value={data.okpo_code}
                                            onChange={(e) => setData('okpo_code', e.target.value)}
                                        />
                                    </FormField>
                                </SimpleGrid>

                                <FormField label="Юридический адрес" error={errors.legal_address}>
                                    <Textarea
                                        value={data.legal_address}
                                        onChange={(e) => setData('legal_address', e.target.value)}
                                        rows={2}
                                    />
                                </FormField>

                                <FormField label="Фактический адрес" error={errors.actual_address}>
                                    <Textarea
                                        value={data.actual_address}
                                        onChange={(e) => setData('actual_address', e.target.value)}
                                        rows={2}
                                    />
                                </FormField>

                                <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                    <FormField label="Телефон" error={errors.phone}>
                                        <Input
                                            value={data.phone}
                                            onChange={(e) => setData('phone', e.target.value)}
                                        />
                                    </FormField>

                                    <FormField label="Email" error={errors.email}>
                                        <Input
                                            type="email"
                                            value={data.email}
                                            onChange={(e) => setData('email', e.target.value)}
                                        />
                                    </FormField>
                                </SimpleGrid>

                                <FormField label="ERP ID" error={errors.erp_id}>
                                    <Input
                                        value={data.erp_id}
                                        onChange={(e) => setData('erp_id', e.target.value)}
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
        </>
    );
}

Edit.layout = (page) => <AdminLayout>{page}</AdminLayout>;
