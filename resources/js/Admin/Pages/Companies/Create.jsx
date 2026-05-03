import { useRef } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, EntitySelector, PhoneInput } from '@/Admin/Components';
import { Box, Card, Input, Textarea, Stack, SimpleGrid, Tabs, HStack, Button } from '@chakra-ui/react';
import { LuSearch } from 'react-icons/lu';

import { toaster } from '@/components/ui/toaster';
import { validateTaxId } from '@/utils/taxId';
import { PartySuggest } from '@/components/common/PartySuggest';
import { EmailSuggest } from '@/components/common/EmailSuggest';
import { AddressSuggest } from '@/components/common/AddressSuggest';
import { useDadataPartyAutofill } from '@/hooks/useDadataPartyAutofill';

export default function Create({ countries }) {
    const { data, setData, post, processing, errors, setError, clearErrors, transform } = useForm({
        user_id: '',
        country: 'RU',
        name: '',
        legal_name: '',
        tax_id: '',
        registration_number: '',
        tax_code: '',
        okpo_code: '',
        legal_address: '',
        legal_address_data: null,
        actual_address: '',
        actual_address_data: null,
        phone: '',
        email: '',
        erp_id: '',
    });

    const closeAfterSaveRef = useRef(false);

    transform((data) => ({
        ...data,
        _close: closeAfterSaveRef.current ? 1 : 0,
    }));

    const { applyParty, lookupByInn, lookingUp } = useDadataPartyAutofill(
        (fields) => Object.entries(fields).forEach(([k, v]) => setData(k, v)),
    );

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        clearErrors('tax_id');
        const taxIdError = validateTaxId(data.tax_id, data.country);
        if (taxIdError) {
            setError('tax_id', taxIdError);
            return;
        }
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
                                            displayField="name"
                                        />
                                    </FormField>

                                    <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                        <FormField label="Название" error={errors.name} required>
                                            <PartySuggest
                                                value={data.name}
                                                onChange={(val) => setData('name', val)}
                                                onCompanySelected={applyParty}
                                                invalid={!!errors.name}
                                                placeholder="Начните вводить название или ИНН"
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
                                            <EmailSuggest
                                                value={data.email}
                                                onChange={(val) => setData('email', val)}
                                                invalid={!!errors.email}
                                                placeholder="company@example.com"
                                            />
                                        </FormField>
                                    </SimpleGrid>

                                    <FormField label="ERP ID" error={errors.erp_id}>
                                        <Input
                                            value={data.erp_id}
                                            onChange={(e) => setData('erp_id', e.target.value)}
                                            placeholder="ID из внешней системы"
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
                                        <FormField
                                            label="ИНН"
                                            error={errors.tax_id}
                                            required
                                            helpText="Введите ИНН и нажмите «Найти», чтобы автоматически заполнить реквизиты."
                                        >
                                            <HStack gap="2" align="stretch" w="full">
                                                <Input
                                                    value={data.tax_id}
                                                    onChange={(e) => setData('tax_id', e.target.value)}
                                                    onBlur={() => {
                                                        const err = validateTaxId(data.tax_id, data.country);
                                                        if (err) setError('tax_id', err);
                                                        else clearErrors('tax_id');
                                                    }}
                                                    placeholder="1234567890"
                                                />
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="md"
                                                    onClick={() => lookupByInn(data.tax_id, data.tax_code || null)}
                                                    loading={lookingUp}
                                                    title="Найти реквизиты по ИНН"
                                                >
                                                    <LuSearch /> Найти
                                                </Button>
                                            </HStack>
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
                                        <AddressSuggest
                                            value={data.legal_address}
                                            onChange={(val) => setData('legal_address', val)}
                                            onAddressSelected={(s) => setData('legal_address_data', s?.data ?? null)}
                                            invalid={!!errors.legal_address}
                                            placeholder="Полный юридический адрес"
                                        />
                                    </FormField>

                                    <FormField label="Фактический адрес" error={errors.actual_address}>
                                        <AddressSuggest
                                            value={data.actual_address}
                                            onChange={(val) => setData('actual_address', val)}
                                            onAddressSelected={(s) => setData('actual_address_data', s?.data ?? null)}
                                            invalid={!!errors.actual_address}
                                            placeholder="Фактический адрес (если отличается)"
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
