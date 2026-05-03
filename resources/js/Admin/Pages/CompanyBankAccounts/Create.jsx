import { useRef } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, EntitySelector } from '@/Admin/Components';
import { Box, Card, Input, Stack, SimpleGrid, HStack, Button } from '@chakra-ui/react';
import { LuSearch } from 'react-icons/lu';
import { Checkbox } from '@/components/ui/checkbox';
import { toaster } from '@/components/ui/toaster';
import { BankSuggest } from '@/components/common/BankSuggest';
import { useDadataBankAutofill } from '@/hooks/useDadataBankAutofill';

export default function Create() {
    const { data, setData, post, processing, errors , transform } = useForm({
        company_id: '',
        bank_name: '',
        bank_bik: '',
        correspondent_account: '',
        account_number: '',
        is_primary: false,
    });

    const closeAfterSaveRef = useRef(false);

    transform((data) => ({
        ...data,
        _close: closeAfterSaveRef.current ? 1 : 0,
    }));

    const { applyBank, lookupByBik, lookingUp } = useDadataBankAutofill(
        (fields) => Object.entries(fields).forEach(([k, v]) => setData(k, v)),
    );

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;
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

    const handleSaveAndClose = (e) => {
        handleSubmit(e, true);
    };

    return (
        <>
            <PageHeader title="Создать банковский счет" description="Добавление нового счета компании" />

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
                                    valueKey="id"
                                    displayField="name"
                                />
                            </FormField>

                            <FormField label="Название банка" error={errors.bank_name} required>
                                <BankSuggest
                                    value={data.bank_name}
                                    onChange={(val) => setData('bank_name', val)}
                                    onBankSelected={applyBank}
                                    invalid={!!errors.bank_name}
                                    placeholder="Начните вводить название или БИК"
                                />
                            </FormField>

                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="БИК" error={errors.bank_bik}>
                                    <HStack gap="2" align="stretch" w="full">
                                        <Input
                                            value={data.bank_bik}
                                            onChange={(e) => setData('bank_bik', e.target.value)}
                                            placeholder="044525225"
                                        />
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="md"
                                            onClick={() => lookupByBik(data.bank_bik)}
                                            loading={lookingUp}
                                            title="Найти реквизиты по БИК"
                                        >
                                            <LuSearch />
                                        </Button>
                                    </HStack>
                                </FormField>

                                <FormField label="Корреспондентский счет" error={errors.correspondent_account}>
                                    <Input
                                        value={data.correspondent_account}
                                        onChange={(e) => setData('correspondent_account', e.target.value)}
                                        placeholder="30101810400000000225"
                                    />
                                </FormField>
                            </SimpleGrid>

                            <FormField label="Номер счета" error={errors.account_number} required>
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
                            onSaveAndClose={handleSaveAndClose}
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
