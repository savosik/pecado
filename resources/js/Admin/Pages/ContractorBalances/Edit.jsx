import React, { useRef } from 'react';
import {
    Box, Button, Card, HStack, IconButton, Input, Stack, Table, Text,
} from '@chakra-ui/react';
import { Head, useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, EntitySelector } from '@/Admin/Components';
import { toaster } from '@/components/ui/toaster';
import { LuPlus, LuTrash2 } from 'react-icons/lu';

const emptyDetail = () => ({ shipment_uuid: '', amount: '', due_date: '' });

const ContractorBalancesEdit = ({ balance }) => {
    const { data, setData, put, processing, errors, transform } = useForm({
        user_id: balance.user_id ?? '',
        tax_id: balance.tax_id ?? '',
        contractor_uuid: balance.contractor_uuid ?? '',
        current_balance: balance.current_balance ?? '',
        overdue_debt: balance.overdue_debt ?? '',
        balance_erp_updated_at: balance.balance_erp_updated_at
            ? balance.balance_erp_updated_at.substring(0, 16)
            : '',
        overdue_details: (balance.overdue_details ?? []).map((d) => ({
            shipment_uuid: d.shipment_uuid ?? '',
            amount: d.amount ?? '',
            due_date: d.due_date ?? '',
        })),
    });

    const closeAfterSaveRef = useRef(false);

    transform((d) => ({
        ...d,
        _close: closeAfterSaveRef.current ? 1 : 0,
    }));

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;
        put(route('admin.contractor-balances.update', balance.id), {
            onSuccess: () =>
                toaster.create({ description: 'Баланс контрагента успешно обновлён', type: 'success' }),
        });
    };

    // Overdue details helpers
    const addDetail = () => setData('overdue_details', [...data.overdue_details, emptyDetail()]);

    const removeDetail = (idx) =>
        setData('overdue_details', data.overdue_details.filter((_, i) => i !== idx));

    const updateDetail = (idx, field, value) => {
        const updated = data.overdue_details.map((d, i) => (i === idx ? { ...d, [field]: value } : d));
        setData('overdue_details', updated);
    };

    // Отображаемое имя пользователя для EntitySelector
    const userDisplayName = balance.user
        ? `${balance.user.name ?? balance.user.name ?? ''}`
        : '';

    return (
        <>
            <Head title={`Редактирование баланса: ${balance.tax_id}`} />
            <PageHeader
                title={`Редактирование баланса: ${balance.tax_id}`}
                backUrl={route('admin.contractor-balances.show', balance.id)}
            />

            <form onSubmit={handleSubmit}>
                <Stack gap={5}>
                    {/* Основные данные */}
                    <Card.Root>
                        <Card.Header pb={2}>
                            <Text fontWeight="700" fontSize="md">Основные данные</Text>
                        </Card.Header>
                        <Card.Body>
                            <Stack gap={4} maxW="2xl">
                                <FormField label="Пользователь" error={errors.user_id} required>
                                    <EntitySelector
                                        searchUrl="admin.users.search"
                                        placeholder="Введите имя или email пользователя..."
                                        displayField="name"
                                        valueKey="id"
                                        value={data.user_id}
                                        initialDisplay={userDisplayName}
                                        onChange={(val) => setData('user_id', val)}
                                        error={errors.user_id}
                                    />
                                </FormField>

                                <HStack gap={4} align="flex-start">
                                    <FormField label="ИНН контрагента" error={errors.tax_id} required flex={1}>
                                        <Input
                                            value={data.tax_id}
                                            onChange={(e) => setData('tax_id', e.target.value)}
                                            placeholder="1234567890"
                                            fontFamily="mono"
                                        />
                                    </FormField>
                                    <FormField label="UUID контрагента (1С)" error={errors.contractor_uuid} flex={2}>
                                        <Input
                                            value={data.contractor_uuid}
                                            onChange={(e) => setData('contractor_uuid', e.target.value)}
                                            placeholder="c1a2b3c4-..."
                                            fontFamily="mono"
                                        />
                                    </FormField>
                                </HStack>

                                <HStack gap={4} align="flex-start">
                                    <FormField label="Текущий баланс (₽)" error={errors.current_balance} required flex={1}>
                                        <Input
                                            type="number"
                                            step="0.01"
                                            value={data.current_balance}
                                            onChange={(e) => setData('current_balance', e.target.value)}
                                            placeholder="-125000.00"
                                            fontFamily="mono"
                                        />
                                    </FormField>
                                    <FormField label="Просроченная задолженность (₽)" error={errors.overdue_debt} flex={1}>
                                        <Input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            value={data.overdue_debt}
                                            onChange={(e) => setData('overdue_debt', e.target.value)}
                                            placeholder="50000.00"
                                            fontFamily="mono"
                                        />
                                    </FormField>
                                </HStack>

                                <FormField label="Дата обновления из 1С" error={errors.balance_erp_updated_at}>
                                    <Input
                                        type="datetime-local"
                                        value={data.balance_erp_updated_at}
                                        onChange={(e) => setData('balance_erp_updated_at', e.target.value)}
                                    />
                                </FormField>
                            </Stack>
                        </Card.Body>
                    </Card.Root>

                    {/* Детализация просрочки по реализациям */}
                    <Card.Root>
                        <Card.Header pb={2}>
                            <HStack justify="space-between">
                                <Text fontWeight="700" fontSize="md">
                                    Детализация просрочки по реализациям
                                </Text>
                                <Button size="sm" variant="outline" onClick={addDetail} type="button">
                                    <LuPlus /> Добавить реализацию
                                </Button>
                            </HStack>
                        </Card.Header>
                        <Card.Body>
                            {data.overdue_details.length === 0 ? (
                                <Text color="gray.400" fontSize="sm" py={4} textAlign="center">
                                    Нет просроченных реализаций. Нажмите «Добавить реализацию», чтобы добавить.
                                </Text>
                            ) : (
                                <Box overflowX="auto">
                                    <Table.Root size="sm">
                                        <Table.Header>
                                            <Table.Row bg="gray.50" _dark={{ bg: 'gray.800' }}>
                                                <Table.ColumnHeader>UUID реализации *</Table.ColumnHeader>
                                                <Table.ColumnHeader>Сумма просрочки (₽) *</Table.ColumnHeader>
                                                <Table.ColumnHeader>Дата оплаты *</Table.ColumnHeader>
                                                <Table.ColumnHeader w="40px" />
                                            </Table.Row>
                                        </Table.Header>
                                        <Table.Body>
                                            {data.overdue_details.map((detail, idx) => (
                                                <Table.Row key={idx}>
                                                    <Table.Cell>
                                                        <Input
                                                            size="sm"
                                                            value={detail.shipment_uuid}
                                                            onChange={(e) => updateDetail(idx, 'shipment_uuid', e.target.value)}
                                                            placeholder="s1a2b3c4-..."
                                                            fontFamily="mono"
                                                        />
                                                        {errors[`overdue_details.${idx}.shipment_uuid`] && (
                                                            <Text fontSize="xs" color="red.500" mt={1}>
                                                                {errors[`overdue_details.${idx}.shipment_uuid`]}
                                                            </Text>
                                                        )}
                                                    </Table.Cell>
                                                    <Table.Cell>
                                                        <Input
                                                            size="sm"
                                                            type="number"
                                                            step="0.01"
                                                            min="0"
                                                            value={detail.amount}
                                                            onChange={(e) => updateDetail(idx, 'amount', e.target.value)}
                                                            placeholder="30000.00"
                                                            fontFamily="mono"
                                                        />
                                                        {errors[`overdue_details.${idx}.amount`] && (
                                                            <Text fontSize="xs" color="red.500" mt={1}>
                                                                {errors[`overdue_details.${idx}.amount`]}
                                                            </Text>
                                                        )}
                                                    </Table.Cell>
                                                    <Table.Cell>
                                                        <Input
                                                            size="sm"
                                                            type="date"
                                                            value={detail.due_date}
                                                            onChange={(e) => updateDetail(idx, 'due_date', e.target.value)}
                                                        />
                                                        {errors[`overdue_details.${idx}.due_date`] && (
                                                            <Text fontSize="xs" color="red.500" mt={1}>
                                                                {errors[`overdue_details.${idx}.due_date`]}
                                                            </Text>
                                                        )}
                                                    </Table.Cell>
                                                    <Table.Cell>
                                                        <IconButton
                                                            size="sm"
                                                            variant="ghost"
                                                            colorPalette="red"
                                                            aria-label="Удалить строку"
                                                            onClick={() => removeDetail(idx)}
                                                            type="button"
                                                        >
                                                            <LuTrash2 />
                                                        </IconButton>
                                                    </Table.Cell>
                                                </Table.Row>
                                            ))}
                                        </Table.Body>
                                    </Table.Root>
                                </Box>
                            )}
                        </Card.Body>
                    </Card.Root>

                    <FormActions
                        onSaveAndClose={(e) => handleSubmit(e, true)}
                        backUrl={route('admin.contractor-balances.show', balance.id)}
                        isLoading={processing}
                        submitLabel="Сохранить"
                    />
                </Stack>
            </form>
        </>
    );
};

ContractorBalancesEdit.layout = (page) => <AdminLayout>{page}</AdminLayout>;

export default ContractorBalancesEdit;
