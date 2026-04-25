import { useState } from 'react';
import {
    Box, Flex, VStack, HStack, Text, Input, Textarea, Button, Card,
    Field, SimpleGrid, Badge, Table, IconButton,
    Dialog, Portal, Switch, Separator,
} from '@chakra-ui/react';
import { Head, router, usePage } from '@inertiajs/react';
import CabinetLayout from '../CabinetLayout';
import { PhoneInput } from '@/components/common/PhoneInput';
import { LuSave, LuPlus, LuPencil, LuTrash2, LuArrowLeft, LuBuilding2, LuFileText, LuMapPin, LuPhone, LuLandmark } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';
import axios from 'axios';
import { validateTaxId } from '@/utils/taxId';

function SectionHeading({ icon: Icon, children }) {
    return (
        <HStack gap="2" pt="2" pb="1">
            {Icon && <Icon size={18} style={{ color: '#9e1b32', flexShrink: 0 }} />}
            <Text fontSize="md" fontWeight="700" color={{ base: 'gray.800', _dark: 'gray.100' }}>
                {children}
            </Text>
        </HStack>
    );
}

export default function Form({ company, countries = [] }) {
    const { errors: serverErrors } = usePage().props;
    const isEditing = !!company;

    const [form, setForm] = useState({
        country: company?.country || '',
        name: company?.name || '',
        legal_name: company?.legal_name || '',
        tax_id: company?.tax_id || '',
        registration_number: company?.registration_number || '',
        tax_code: company?.tax_code || '',
        okpo_code: company?.okpo_code || '',
        legal_address: company?.legal_address || '',
        actual_address: company?.actual_address || '',
        phone: company?.phone || '',
        email: company?.email || '',
    });
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState(serverErrors || {});

    // Bank accounts state
    const [bankDialogOpen, setBankDialogOpen] = useState(false);
    const [bankForm, setBankForm] = useState({ bank_name: '', bank_bik: '', correspondent_account: '', account_number: '', is_primary: false });
    const [bankErrors, setBankErrors] = useState({});
    const [bankSaving, setBankSaving] = useState(false);
    const [editingAccountId, setEditingAccountId] = useState(null);

    const handleChange = (field, value) => {
        setForm(prev => ({ ...prev, [field]: value }));
        setErrors(prev => ({ ...prev, [field]: undefined }));
    };

    const validate = () => {
        const errs = {};
        if (!form.name.trim()) errs.name = 'Название обязательно.';
        if (!form.country) errs.country = 'Выберите страну.';
        const taxIdError = validateTaxId(form.tax_id, form.country);
        if (taxIdError) errs.tax_id = taxIdError;
        if (form.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) errs.email = 'Введите корректный email.';
        return errs;
    };

    const handleTaxIdBlur = () => {
        const err = validateTaxId(form.tax_id, form.country);
        setErrors(prev => ({ ...prev, tax_id: err || undefined }));
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        const clientErrors = validate();
        if (Object.keys(clientErrors).length > 0) { setErrors(clientErrors); return; }

        setProcessing(true);
        setErrors({});

        const options = {
            preserveScroll: true,
            onSuccess: () => toaster.create({ title: isEditing ? 'Компания обновлена' : 'Компания создана', type: 'success' }),
            onError: (errs) => setErrors(errs),
            onFinish: () => setProcessing(false),
        };

        if (isEditing) {
            router.put(`/cabinet/companies/${company.id}`, form, options);
        } else {
            router.post('/cabinet/companies', form, options);
        }
    };

    // Bank account handlers
    const openAddBankDialog = () => {
        setBankForm({ bank_name: '', bank_bik: '', correspondent_account: '', account_number: '', is_primary: false });
        setBankErrors({});
        setEditingAccountId(null);
        setBankDialogOpen(true);
    };

    const openEditBankDialog = (account) => {
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

    const handleBankSubmit = async () => {
        setBankSaving(true);
        setBankErrors({});
        try {
            const payload = { ...bankForm, company_id: company.id };
            if (editingAccountId) {
                await axios.put(`/cabinet/bank-accounts/${editingAccountId}`, payload);
                toaster.create({ title: 'Банковский счёт обновлён', type: 'success' });
            } else {
                await axios.post('/cabinet/bank-accounts', payload);
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

    const handleBankDelete = async (accountId) => {
        if (!confirm('Удалить этот банковский счёт?')) return;
        try {
            await axios.delete(`/cabinet/bank-accounts/${accountId}`);
            toaster.create({ title: 'Банковский счёт удалён', type: 'success' });
            router.reload({ only: ['company'] });
        } catch {
            toaster.create({ title: 'Ошибка удаления', type: 'error' });
        }
    };

    const bankAccounts = company?.bank_accounts || [];

    return (
        <CabinetLayout title={isEditing ? `Редактирование: ${company.name}` : 'Новая компания'}>
            <Head title={`${isEditing ? 'Редактирование компании' : 'Новая компания'} — Pecado`} />

            <Button
                as="a"
                href="/cabinet/companies"
                variant="ghost"
                size="sm"
                mb="4"
                onClick={(e) => { e.preventDefault(); router.visit('/cabinet/companies'); }}
            >
                <LuArrowLeft /> Назад к списку
            </Button>

            <form onSubmit={handleSubmit}>
                <VStack gap="6" align="stretch" maxW="720px">

                    {/* === Основная информация === */}
                    <Card.Root bg={{ base: 'white', _dark: 'gray.800' }} borderRadius="xl" border="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }}>
                        <Card.Body p="5">
                            <SectionHeading icon={LuBuilding2}>Основная информация</SectionHeading>
                            <VStack gap="4" align="stretch" pt="3">
                                <SimpleGrid columns={{ base: 1, md: 2 }} gap="4">
                                    <Field.Root invalid={!!errors.name} required>
                                        <Field.Label fontSize="sm" fontWeight="600">Название *</Field.Label>
                                        <Input value={form.name} onChange={(e) => handleChange('name', e.target.value)} placeholder="ООО Компания" />
                                        {errors.name && <Field.ErrorText>{errors.name}</Field.ErrorText>}
                                    </Field.Root>

                                    <Field.Root invalid={!!errors.country} required>
                                        <Field.Label fontSize="sm" fontWeight="600">Страна *</Field.Label>
                                        <select
                                            value={form.country}
                                            onChange={(e) => handleChange('country', e.target.value)}
                                            style={{ width: '100%', padding: '0.5rem', borderRadius: '0.375rem', border: '1px solid var(--chakra-colors-border-subtle)' }}
                                        >
                                            <option value="">Выберите страну</option>
                                            {countries.map((c) => (
                                                <option key={c.value} value={c.value}>{c.label}</option>
                                            ))}
                                        </select>
                                        {errors.country && <Field.ErrorText>{errors.country}</Field.ErrorText>}
                                    </Field.Root>
                                </SimpleGrid>

                                <Field.Root invalid={!!errors.legal_name}>
                                    <Field.Label fontSize="sm" fontWeight="600">Юридическое название</Field.Label>
                                    <Input value={form.legal_name} onChange={(e) => handleChange('legal_name', e.target.value)} placeholder="ООО «Компания»" />
                                    {errors.legal_name && <Field.ErrorText>{errors.legal_name}</Field.ErrorText>}
                                </Field.Root>
                            </VStack>
                        </Card.Body>
                    </Card.Root>

                    {/* === Юридические реквизиты === */}
                    <Card.Root bg={{ base: 'white', _dark: 'gray.800' }} borderRadius="xl" border="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }}>
                        <Card.Body p="5">
                            <SectionHeading icon={LuFileText}>Юридические реквизиты</SectionHeading>
                            <VStack gap="4" align="stretch" pt="3">
                                <SimpleGrid columns={{ base: 1, md: 2 }} gap="4">
                                    <Field.Root invalid={!!errors.tax_id} required>
                                        <Field.Label fontSize="sm" fontWeight="600">ИНН *</Field.Label>
                                        <Input
                                            value={form.tax_id}
                                            onChange={(e) => handleChange('tax_id', e.target.value)}
                                            onBlur={handleTaxIdBlur}
                                            placeholder="7707083893"
                                        />
                                        {errors.tax_id && <Field.ErrorText>{errors.tax_id}</Field.ErrorText>}
                                    </Field.Root>

                                    <Field.Root invalid={!!errors.registration_number}>
                                        <Field.Label fontSize="sm" fontWeight="600">ОГРН</Field.Label>
                                        <Input value={form.registration_number} onChange={(e) => handleChange('registration_number', e.target.value)} placeholder="1027700132195" />
                                        {errors.registration_number && <Field.ErrorText>{errors.registration_number}</Field.ErrorText>}
                                    </Field.Root>
                                </SimpleGrid>

                                <SimpleGrid columns={{ base: 1, md: 2 }} gap="4">
                                    <Field.Root invalid={!!errors.tax_code}>
                                        <Field.Label fontSize="sm" fontWeight="600">КПП</Field.Label>
                                        <Input value={form.tax_code} onChange={(e) => handleChange('tax_code', e.target.value)} placeholder="770701001" />
                                        {errors.tax_code && <Field.ErrorText>{errors.tax_code}</Field.ErrorText>}
                                    </Field.Root>

                                    <Field.Root invalid={!!errors.okpo_code}>
                                        <Field.Label fontSize="sm" fontWeight="600">ОКПО</Field.Label>
                                        <Input value={form.okpo_code} onChange={(e) => handleChange('okpo_code', e.target.value)} placeholder="00032537" />
                                        {errors.okpo_code && <Field.ErrorText>{errors.okpo_code}</Field.ErrorText>}
                                    </Field.Root>
                                </SimpleGrid>
                            </VStack>
                        </Card.Body>
                    </Card.Root>

                    {/* === Адреса === */}
                    <Card.Root bg={{ base: 'white', _dark: 'gray.800' }} borderRadius="xl" border="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }}>
                        <Card.Body p="5">
                            <SectionHeading icon={LuMapPin}>Адреса</SectionHeading>
                            <VStack gap="4" align="stretch" pt="3">
                                <Field.Root invalid={!!errors.legal_address}>
                                    <Field.Label fontSize="sm" fontWeight="600">Юридический адрес</Field.Label>
                                    <Textarea value={form.legal_address} onChange={(e) => handleChange('legal_address', e.target.value)} rows={2} placeholder="г. Москва, ул. Примерная, д. 1" />
                                    {errors.legal_address && <Field.ErrorText>{errors.legal_address}</Field.ErrorText>}
                                </Field.Root>

                                <Field.Root invalid={!!errors.actual_address}>
                                    <Field.Label fontSize="sm" fontWeight="600">Фактический адрес</Field.Label>
                                    <Textarea value={form.actual_address} onChange={(e) => handleChange('actual_address', e.target.value)} rows={2} placeholder="г. Москва, ул. Примерная, д. 1, оф. 100" />
                                    {errors.actual_address && <Field.ErrorText>{errors.actual_address}</Field.ErrorText>}
                                </Field.Root>
                            </VStack>
                        </Card.Body>
                    </Card.Root>

                    {/* === Контакты === */}
                    <Card.Root bg={{ base: 'white', _dark: 'gray.800' }} borderRadius="xl" border="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }}>
                        <Card.Body p="5">
                            <SectionHeading icon={LuPhone}>Контакты</SectionHeading>
                            <SimpleGrid columns={{ base: 1, md: 2 }} gap="4" pt="3">
                                <Field.Root invalid={!!errors.phone}>
                                    <Field.Label fontSize="sm" fontWeight="600">Телефон</Field.Label>
                                    <PhoneInput value={form.phone} onChange={(val) => handleChange('phone', val)} />
                                    {errors.phone && <Field.ErrorText>{errors.phone}</Field.ErrorText>}
                                </Field.Root>

                                <Field.Root invalid={!!errors.email}>
                                    <Field.Label fontSize="sm" fontWeight="600">Email</Field.Label>
                                    <Input type="email" value={form.email} onChange={(e) => handleChange('email', e.target.value)} placeholder="company@example.com" />
                                    {errors.email && <Field.ErrorText>{errors.email}</Field.ErrorText>}
                                </Field.Root>
                            </SimpleGrid>
                        </Card.Body>
                    </Card.Root>

                    {/* === Кнопка сохранения === */}
                    <Flex justify="flex-start">
                        <Button type="submit" bg="#9e1b32" color="white" _hover={{ bg: '#7a1527' }} size="md" loading={processing} loadingText="Сохранение...">
                            <LuSave /> Сохранить
                        </Button>
                    </Flex>

                    {/* === Банковские счета (только при редактировании) === */}
                    {isEditing && (
                        <Card.Root bg={{ base: 'white', _dark: 'gray.800' }} borderRadius="xl" border="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }}>
                            <Card.Body p="5">
                                <Flex justify="space-between" align="center">
                                    <SectionHeading icon={LuLandmark}>Банковские счета</SectionHeading>
                                    <Button size="sm" bg="#9e1b32" color="white" _hover={{ bg: '#7a1527' }} onClick={openAddBankDialog}>
                                        <LuPlus /> Добавить счёт
                                    </Button>
                                </Flex>

                                <Box pt="3">
                                    {bankAccounts.length === 0 ? (
                                        <Box p="8" textAlign="center" borderWidth="1px" borderRadius="md" borderStyle="dashed" borderColor={{ base: 'gray.200', _dark: 'gray.700' }}>
                                            <Text color="gray.400">Банковские счета не добавлены</Text>
                                        </Box>
                                    ) : (
                                        <>
                                            {/* Desktop table */}
                                            <Box display={{ base: 'none', md: 'block' }}>
                                                <Table.Root bg={{ base: 'white', _dark: 'gray.800' }} size="sm" variant="outline">
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
                                                        {bankAccounts.map((acc) => (
                                                            <Table.Row key={acc.id}>
                                                                <Table.Cell fontSize="sm">{acc.bank_name}</Table.Cell>
                                                                <Table.Cell fontSize="sm">{acc.bank_bik || '—'}</Table.Cell>
                                                                <Table.Cell fontSize="sm">{acc.correspondent_account || '—'}</Table.Cell>
                                                                <Table.Cell fontSize="sm">{acc.account_number}</Table.Cell>
                                                                <Table.Cell>
                                                                    <Badge colorPalette={acc.is_primary ? 'green' : 'gray'} variant="subtle">
                                                                        {acc.is_primary ? 'Да' : 'Нет'}
                                                                    </Badge>
                                                                </Table.Cell>
                                                                <Table.Cell textAlign="right">
                                                                    <HStack gap="1" justify="flex-end">
                                                                        <IconButton size="xs" variant="ghost" colorPalette="blue" aria-label="Редактировать" onClick={() => openEditBankDialog(acc)}>
                                                                            <LuPencil />
                                                                        </IconButton>
                                                                        <IconButton size="xs" variant="ghost" colorPalette="red" aria-label="Удалить" onClick={() => handleBankDelete(acc.id)}>
                                                                            <LuTrash2 />
                                                                        </IconButton>
                                                                    </HStack>
                                                                </Table.Cell>
                                                            </Table.Row>
                                                        ))}
                                                    </Table.Body>
                                                </Table.Root>
                                            </Box>

                                            {/* Mobile cards */}
                                            <VStack display={{ base: 'flex', md: 'none' }} gap="3" align="stretch">
                                                {bankAccounts.map((acc) => (
                                                    <Card.Root bg={{ base: 'white', _dark: 'gray.800' }} key={acc.id} size="sm" borderRadius="lg" border="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }}>
                                                        <Card.Body p="3">
                                                            <Flex justify="space-between" align="start">
                                                                <Box flex="1" minW="0">
                                                                    <HStack gap="2" mb="1">
                                                                        <Text fontWeight="600" fontSize="sm">{acc.bank_name}</Text>
                                                                        {acc.is_primary && <Badge colorPalette="green" variant="subtle" fontSize="xs">Основной</Badge>}
                                                                    </HStack>
                                                                    <Text fontSize="xs" color="gray.500">Р/С: {acc.account_number}</Text>
                                                                    {acc.bank_bik && <Text fontSize="xs" color="gray.500">БИК: {acc.bank_bik}</Text>}
                                                                </Box>
                                                                <HStack gap="1" flexShrink="0">
                                                                    <IconButton size="xs" variant="ghost" colorPalette="blue" aria-label="Редактировать" onClick={() => openEditBankDialog(acc)}>
                                                                        <LuPencil />
                                                                    </IconButton>
                                                                    <IconButton size="xs" variant="ghost" colorPalette="red" aria-label="Удалить" onClick={() => handleBankDelete(acc.id)}>
                                                                        <LuTrash2 />
                                                                    </IconButton>
                                                                </HStack>
                                                            </Flex>
                                                        </Card.Body>
                                                    </Card.Root>
                                                ))}
                                            </VStack>
                                        </>
                                    )}
                                </Box>
                            </Card.Body>
                        </Card.Root>
                    )}
                </VStack>
            </form>

            {/* Bank Account Dialog */}
            <Dialog.Root open={bankDialogOpen} onOpenChange={({ open }) => !open && setBankDialogOpen(false)}>
                <Portal>
                    <Dialog.Backdrop />
                    <Dialog.Positioner>
                        <Dialog.Content>
                            <Dialog.Header>
                                <Dialog.Title>{editingAccountId ? 'Редактировать банковский счёт' : 'Добавить банковский счёт'}</Dialog.Title>
                            </Dialog.Header>
                            <Dialog.Body>
                                <VStack gap="4" align="stretch">
                                    <Field.Root invalid={!!bankErrors.bank_name}>
                                        <Field.Label fontSize="sm" fontWeight="600">Название банка *</Field.Label>
                                        <Input
                                            value={bankForm.bank_name}
                                            onChange={(e) => setBankForm(prev => ({ ...prev, bank_name: e.target.value }))}
                                            placeholder="ПАО Сбербанк"
                                        />
                                        {bankErrors.bank_name && <Field.ErrorText>{bankErrors.bank_name[0]}</Field.ErrorText>}
                                    </Field.Root>

                                    <SimpleGrid columns={{ base: 1, md: 2 }} gap="4">
                                        <Field.Root invalid={!!bankErrors.bank_bik}>
                                            <Field.Label fontSize="sm" fontWeight="600">БИК</Field.Label>
                                            <Input
                                                value={bankForm.bank_bik}
                                                onChange={(e) => setBankForm(prev => ({ ...prev, bank_bik: e.target.value }))}
                                                placeholder="044525225"
                                            />
                                            {bankErrors.bank_bik && <Field.ErrorText>{bankErrors.bank_bik[0]}</Field.ErrorText>}
                                        </Field.Root>

                                        <Field.Root invalid={!!bankErrors.correspondent_account}>
                                            <Field.Label fontSize="sm" fontWeight="600">Корр. счёт</Field.Label>
                                            <Input
                                                value={bankForm.correspondent_account}
                                                onChange={(e) => setBankForm(prev => ({ ...prev, correspondent_account: e.target.value }))}
                                                placeholder="30101810400000000225"
                                            />
                                            {bankErrors.correspondent_account && <Field.ErrorText>{bankErrors.correspondent_account[0]}</Field.ErrorText>}
                                        </Field.Root>
                                    </SimpleGrid>

                                    <Field.Root invalid={!!bankErrors.account_number}>
                                        <Field.Label fontSize="sm" fontWeight="600">Расчётный счёт *</Field.Label>
                                        <Input
                                            value={bankForm.account_number}
                                            onChange={(e) => setBankForm(prev => ({ ...prev, account_number: e.target.value }))}
                                            placeholder="40702810938000012345"
                                        />
                                        {bankErrors.account_number && <Field.ErrorText>{bankErrors.account_number[0]}</Field.ErrorText>}
                                    </Field.Root>

                                    <Field.Root>
                                        <Field.Label fontSize="sm" fontWeight="600">Основной счёт</Field.Label>
                                        <Switch.Root
                                            checked={bankForm.is_primary}
                                            onCheckedChange={({ checked }) => setBankForm(prev => ({ ...prev, is_primary: checked }))}
                                        >
                                            <Switch.HiddenInput />
                                            <Switch.Control>
                                                <Switch.Thumb />
                                            </Switch.Control>
                                            <Switch.Label>{bankForm.is_primary ? 'Да' : 'Нет'}</Switch.Label>
                                        </Switch.Root>
                                    </Field.Root>
                                </VStack>
                            </Dialog.Body>
                            <Dialog.Footer>
                                <Dialog.ActionTrigger asChild>
                                    <Button variant="outline">Отмена</Button>
                                </Dialog.ActionTrigger>
                                <Button bg="#9e1b32" color="white" _hover={{ bg: '#7a1527' }} onClick={handleBankSubmit} loading={bankSaving}>
                                    {editingAccountId ? 'Сохранить' : 'Добавить'}
                                </Button>
                            </Dialog.Footer>
                        </Dialog.Content>
                    </Dialog.Positioner>
                </Portal>
            </Dialog.Root>
        </CabinetLayout>
    );
}
