import { useRef } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, EntitySelector, PhoneInput, YandexMapPicker } from '@/Admin/Components';
import { Box, Card, Input, Textarea, Stack, SimpleGrid, Tabs, Switch } from '@chakra-ui/react';

import { toaster } from '@/components/ui/toaster';

export default function Create({ countries, yandexMapsApiKey }) {
    const { data, setData, post, processing, errors, transform } = useForm({
        user_id: '',
        country: '',
        name: '',
        legal_name: '',
        tax_id: '',
        registration_number: '',
        tax_code: '',
        okpo_code: '',
        legal_address: '',
        actual_address: '',
        phone: '',
        email: '',
        erp_id: '',
        latitude: '',
        longitude: '',
        is_our_company: false,
    });

    const closeAfterSaveRef = useRef(false);

    transform((data) => ({
        ...data,
        _close: closeAfterSaveRef.current ? 1 : 0,
    }));

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;
        post(route('admin.companies.store'), {
            onSuccess: () => {
                toaster.create({
                    title: 'Компания создана',
                    description: 'Компания успешно добавлена',
                    type: 'success',
                });
            },
        });
    };

    const handleSaveAndClose = (e) => {
        handleSubmit(e, true);
    };

    return (
        <>
            <PageHeader title="Создать компанию" description="Добавление новой компании" />

            <form onSubmit={handleSubmit}>
                <Card.Root>
                    <Card.Body>
                        <Tabs.Root defaultValue="general" variant="enclosed">
                            <Tabs.List>
                                <Tabs.Trigger value="general">Данные компании</Tabs.Trigger>
                                <Tabs.Trigger value="legal">Юридические реквизиты</Tabs.Trigger>
                            </Tabs.List>

                            <Tabs.Content value="general">
                                <Stack gap={6} pt={4}>
                                    <FormField label="Пользователь" error={errors.user_id} required>
                                        <EntitySelector
                                            searchUrl={route('admin.users.search')}
                                            placeholder="Выберите пользователя"
                                            value={data.user_id}
                                            onChange={(value) => setData('user_id', value)}
                                            error={errors.user_id}
                                            valueKey="id"
                                            displayField="full_name"
                                        />
                                    </FormField>

                                    <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                        <FormField label="Название" error={errors.name} required>
                                            <Input
                                                value={data.name}
                                                onChange={(e) => setData('name', e.target.value)}
                                                placeholder="ООО Компания"
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

                                    <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                        <FormField label="Телефон" error={errors.phone}>
                                            <PhoneInput
                                                value={data.phone}
                                                onChange={(value) => setData('phone', value)}
                                            />
                                        </FormField>

                                        <FormField label="Email" error={errors.email}>
                                            <Input
                                                type="email"
                                                value={data.email}
                                                onChange={(e) => setData('email', e.target.value)}
                                                placeholder="company@example.com"
                                            />
                                        </FormField>
                                    </SimpleGrid>

                                    <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                        <FormField label="ERP ID" error={errors.erp_id}>
                                            <Input
                                                value={data.erp_id}
                                                onChange={(e) => setData('erp_id', e.target.value)}
                                                placeholder="ID из внешней системы"
                                            />
                                        </FormField>

                                        <FormField label="Наша компания">
                                            <Switch.Root
                                                checked={data.is_our_company}
                                                onCheckedChange={({ checked }) => setData('is_our_company', checked)}
                                            >
                                                <Switch.HiddenInput />
                                                <Switch.Control>
                                                    <Switch.Thumb />
                                                </Switch.Control>
                                                <Switch.Label>{data.is_our_company ? 'Да' : 'Нет'}</Switch.Label>
                                            </Switch.Root>
                                        </FormField>
                                    </SimpleGrid>

                                    <FormField label="Координаты" error={errors.latitude || errors.longitude}>
                                        <YandexMapPicker
                                            latitude={data.latitude}
                                            longitude={data.longitude}
                                            onChange={(lat, lng) => {
                                                setData(prev => ({ ...prev, latitude: lat, longitude: lng }));
                                            }}
                                            apiKey={yandexMapsApiKey}
                                            height="350px"
                                        />
                                    </FormField>
                                </Stack>
                            </Tabs.Content>

                            <Tabs.Content value="legal">
                                <Stack gap={6} pt={4}>
                                    <FormField label="Юридическое название" error={errors.legal_name}>
                                        <Input
                                            value={data.legal_name}
                                            onChange={(e) => setData('legal_name', e.target.value)}
                                            placeholder="Полное юридическое название"
                                        />
                                    </FormField>

                                    <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                        <FormField label="ИНН" error={errors.tax_id}>
                                            <Input
                                                value={data.tax_id}
                                                onChange={(e) => setData('tax_id', e.target.value)}
                                                placeholder="1234567890"
                                            />
                                        </FormField>

                                        <FormField label="ОГРН" error={errors.registration_number}>
                                            <Input
                                                value={data.registration_number}
                                                onChange={(e) => setData('registration_number', e.target.value)}
                                                placeholder="1234567890123"
                                            />
                                        </FormField>
                                    </SimpleGrid>

                                    <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                        <FormField label="КПП" error={errors.tax_code}>
                                            <Input
                                                value={data.tax_code}
                                                onChange={(e) => setData('tax_code', e.target.value)}
                                                placeholder="123456789"
                                            />
                                        </FormField>

                                        <FormField label="ОКПО" error={errors.okpo_code}>
                                            <Input
                                                value={data.okpo_code}
                                                onChange={(e) => setData('okpo_code', e.target.value)}
                                                placeholder="12345678"
                                            />
                                        </FormField>
                                    </SimpleGrid>

                                    <FormField label="Юридический адрес" error={errors.legal_address}>
                                        <Textarea
                                            value={data.legal_address}
                                            onChange={(e) => setData('legal_address', e.target.value)}
                                            placeholder="Полный юридический адрес"
                                            rows={2}
                                        />
                                    </FormField>

                                    <FormField label="Фактический адрес" error={errors.actual_address}>
                                        <Textarea
                                            value={data.actual_address}
                                            onChange={(e) => setData('actual_address', e.target.value)}
                                            placeholder="Фактический адрес (если отличается)"
                                            rows={2}
                                        />
                                    </FormField>
                                </Stack>
                            </Tabs.Content>
                        </Tabs.Root>
                    </Card.Body>

                    <Card.Footer>
                        <FormActions
                            onSaveAndClose={handleSaveAndClose}
                            loading={processing}
                            onCancel={() => window.history.back()}
                            submitLabel="Создать компанию"
                        />
                    </Card.Footer>
                </Card.Root>
            </form>
        </>
    );
}

Create.layout = (page) => <AdminLayout>{page}</AdminLayout>;
