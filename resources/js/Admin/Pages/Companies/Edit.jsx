import { useForm, router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, EntitySelector, PhoneInput } from '@/Admin/Components';
import { Box, Card, Input, Textarea, Stack, SimpleGrid, Tabs, Table, Badge, Button, IconButton, HStack, Text, Flex, Dialog, Portal, Switch } from '@chakra-ui/react';
import { LuPlus, LuPencil, LuTrash2 } from 'react-icons/lu';
import { useState } from 'react';
import axios from 'axios';
import { toaster } from '@/components/ui/toaster';

const emptyBankAccount = {
    bank_name: '',
    bank_bik: '',
    correspondent_account: '',
    account_number: '',
    is_primary: false,
};

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

    // Состояние диалога банковского счёта
    const [bankDialogOpen, setBankDialogOpen] = useState(false);
    const [bankForm, setBankForm] = useState({ ...emptyBankAccount });
    const [bankErrors, setBankErrors] = useState({});
    const [bankSaving, setBankSaving] = useState(false);
    const [editingAccountId, setEditingAccountId] = useState(null);

    const openAddDialog = () => {
        setBankForm({ ...emptyBankAccount });
        setBankErrors({});
        setEditingAccountId(null);
        setBankDialogOpen(true);
    };

    const openEditDialog = (account) => {
        setBankForm({
            bank_name: account.bank_name || '',
            bank_bik: account.bank_bik || '',
            correspondent_account: account.correspondent_account || '',
            account_number: account.account_number || '',
            is_primary: account.is_primary || false,
        });
        setBankErrors({});
        setEditingAccountId(account.id);
        setBankDialogOpen(true);
    };

    const handleBankFormChange = (field, value) => {
        setBankForm(prev => ({ ...prev, [field]: value }));
    };

    const handleBankSubmit = async () => {
        setBankSaving(true);
        setBankErrors({});
        try {
            const payload = { ...bankForm, company_id: company.id };
            if (editingAccountId) {
                await axios.put(route('admin.company-bank-accounts.update', editingAccountId), payload);
                toaster.create({ title: 'Банковский счёт обновлён', type: 'success' });
            } else {
                await axios.post(route('admin.company-bank-accounts.store'), payload);
                toaster.create({ title: 'Банковский счёт добавлен', type: 'success' });
            }
            setBankDialogOpen(false);
            router.reload({ only: ['company'] });
        } catch (err) {
            if (err.response?.status === 422) {
                setBankErrors(err.response.data.errors || {});
            } else {
                toaster.create({ title: 'Ошибка сохранения', type: 'error' });
            }
        } finally {
            setBankSaving(false);
        }
    };

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
                        <Tabs.Root defaultValue="general" variant="enclosed">
                            <Tabs.List>
                                <Tabs.Trigger value="general">Данные компании</Tabs.Trigger>
                                <Tabs.Trigger value="legal">Юридические реквизиты</Tabs.Trigger>
                                <Tabs.Trigger value="bank">Банковские реквизиты</Tabs.Trigger>
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
                                            initialDisplay={company.user?.full_name}
                                            valueKey="id"
                                            displayField="name"
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
                            </Tabs.Content>

                            <Tabs.Content value="legal">
                                <Stack gap={6} pt={4}>
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
                                </Stack>
                            </Tabs.Content>

                            <Tabs.Content value="bank">
                                <Stack gap={6} pt={4}>
                                    <Flex justifyContent="space-between" alignItems="center">
                                        <Text fontSize="lg" fontWeight="semibold">Банковские счета</Text>
                                        <Button
                                            size="sm"
                                            colorPalette="blue"
                                            onClick={openAddDialog}
                                        >
                                            <LuPlus /> Добавить счёт
                                        </Button>
                                    </Flex>

                                    {company.bank_accounts && company.bank_accounts.length > 0 ? (
                                        <Table.Root size="sm" variant="outline">
                                            <Table.Header>
                                                <Table.Row>
                                                    <Table.ColumnHeader>Банк</Table.ColumnHeader>
                                                    <Table.ColumnHeader>БИК</Table.ColumnHeader>
                                                    <Table.ColumnHeader>Корр. счёт</Table.ColumnHeader>
                                                    <Table.ColumnHeader>Расчётный счёт</Table.ColumnHeader>
                                                    <Table.ColumnHeader>Основной</Table.ColumnHeader>
                                                    <Table.ColumnHeader textAlign="right">Действия</Table.ColumnHeader>
                                                </Table.Row>
                                            </Table.Header>
                                            <Table.Body>
                                                {company.bank_accounts.map((account) => (
                                                    <Table.Row key={account.id}>
                                                        <Table.Cell>{account.bank_name}</Table.Cell>
                                                        <Table.Cell>{account.bank_bik}</Table.Cell>
                                                        <Table.Cell>{account.correspondent_account}</Table.Cell>
                                                        <Table.Cell>{account.account_number}</Table.Cell>
                                                        <Table.Cell>
                                                            {account.is_primary ? (
                                                                <Badge colorPalette="green">Да</Badge>
                                                            ) : (
                                                                <Badge colorPalette="gray">Нет</Badge>
                                                            )}
                                                        </Table.Cell>
                                                        <Table.Cell textAlign="right">
                                                            <HStack gap={1} justifyContent="flex-end">
                                                                <IconButton
                                                                    size="xs"
                                                                    variant="ghost"
                                                                    colorPalette="blue"
                                                                    aria-label="Редактировать"
                                                                    onClick={() => openEditDialog(account)}
                                                                >
                                                                    <LuPencil />
                                                                </IconButton>
                                                                <IconButton
                                                                    size="xs"
                                                                    variant="ghost"
                                                                    colorPalette="red"
                                                                    aria-label="Удалить"
                                                                    onClick={() => {
                                                                        if (confirm('Удалить этот банковский счёт?')) {
                                                                            router.delete(route('admin.company-bank-accounts.destroy', account.id), {
                                                                                preserveState: true,
                                                                                onSuccess: () => toaster.create({
                                                                                    title: 'Банковский счёт удалён',
                                                                                    type: 'success',
                                                                                }),
                                                                            });
                                                                        }
                                                                    }}
                                                                >
                                                                    <LuTrash2 />
                                                                </IconButton>
                                                            </HStack>
                                                        </Table.Cell>
                                                    </Table.Row>
                                                ))}
                                            </Table.Body>
                                        </Table.Root>
                                    ) : (
                                        <Box p={8} textAlign="center" borderWidth="1px" borderRadius="md" borderStyle="dashed">
                                            <Text color="fg.muted">Банковские счета не добавлены</Text>
                                        </Box>
                                    )}
                                </Stack>
                            </Tabs.Content>
                        </Tabs.Root>
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

            {/* Диалог добавления/редактирования банковского счёта */}
            <Dialog.Root open={bankDialogOpen} onOpenChange={({ open }) => !open && setBankDialogOpen(false)}>
                <Portal>
                    <Dialog.Backdrop />
                    <Dialog.Positioner>
                        <Dialog.Content>
                            <Dialog.Header>
                                <Dialog.Title>
                                    {editingAccountId ? 'Редактировать банковский счёт' : 'Добавить банковский счёт'}
                                </Dialog.Title>
                            </Dialog.Header>
                            <Dialog.Body>
                                <Stack gap={4}>
                                    <FormField label="Название банка" error={bankErrors.bank_name?.[0]} required>
                                        <Input
                                            value={bankForm.bank_name}
                                            onChange={(e) => handleBankFormChange('bank_name', e.target.value)}
                                            placeholder="ПАО Сбербанк"
                                        />
                                    </FormField>

                                    <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                        <FormField label="БИК" error={bankErrors.bank_bik?.[0]}>
                                            <Input
                                                value={bankForm.bank_bik}
                                                onChange={(e) => handleBankFormChange('bank_bik', e.target.value)}
                                                placeholder="044525225"
                                            />
                                        </FormField>

                                        <FormField label="Корр. счёт" error={bankErrors.correspondent_account?.[0]}>
                                            <Input
                                                value={bankForm.correspondent_account}
                                                onChange={(e) => handleBankFormChange('correspondent_account', e.target.value)}
                                                placeholder="30101810400000000225"
                                            />
                                        </FormField>
                                    </SimpleGrid>

                                    <FormField label="Расчётный счёт" error={bankErrors.account_number?.[0]} required>
                                        <Input
                                            value={bankForm.account_number}
                                            onChange={(e) => handleBankFormChange('account_number', e.target.value)}
                                            placeholder="40702810938000012345"
                                        />
                                    </FormField>

                                    <FormField label="Основной счёт">
                                        <Switch.Root
                                            checked={bankForm.is_primary}
                                            onCheckedChange={({ checked }) => handleBankFormChange('is_primary', checked)}
                                        >
                                            <Switch.HiddenInput />
                                            <Switch.Control>
                                                <Switch.Thumb />
                                            </Switch.Control>
                                            <Switch.Label>{bankForm.is_primary ? 'Да' : 'Нет'}</Switch.Label>
                                        </Switch.Root>
                                    </FormField>
                                </Stack>
                            </Dialog.Body>
                            <Dialog.Footer>
                                <Dialog.ActionTrigger asChild>
                                    <Button variant="outline">Отмена</Button>
                                </Dialog.ActionTrigger>
                                <Button
                                    colorPalette="blue"
                                    onClick={handleBankSubmit}
                                    loading={bankSaving}
                                >
                                    {editingAccountId ? 'Сохранить' : 'Добавить'}
                                </Button>
                            </Dialog.Footer>
                        </Dialog.Content>
                    </Dialog.Positioner>
                </Portal>
            </Dialog.Root>
        </>
    );
}

Edit.layout = (page) => <AdminLayout>{page}</AdminLayout>;
