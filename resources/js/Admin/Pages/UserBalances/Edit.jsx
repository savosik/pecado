import { useState, useRef } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, EntitySelector } from '@/Admin/Components';
import { Card, Input, Stack, SimpleGrid } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';

export default function Edit({ balance }) {
    const [selectedUser, setSelectedUser] = useState(balance.user || null);
    const { data, setData, put, processing, errors, transform } = useForm({
        user_id: balance.user_id || '',
        balance: balance.balance || '0.00',
        overdue_debt: balance.overdue_debt || '0.00',
    });

    const closeAfterSaveRef = useRef(false);

    transform((data) => ({
        ...data,
        _close: closeAfterSaveRef.current ? 1 : 0,
    }));

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;
        put(route('admin.user-balances.update', balance.id), {
            onSuccess: () => {
                toaster.create({
                    title: 'Баланс успешно обновлён',
                    type: 'success',
                });
            },
            onError: () => {
                toaster.create({
                    title: 'Ошибка при обновлении баланса',
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
            <PageHeader title={`Редактировать баланс: ${balance.user.name}`} />

            <Card.Root>
                <Card.Body>
                    <form onSubmit={handleSubmit}>
                        <Stack gap={6}>
                            <FormField label="Пользователь" error={errors.user_id} required>
                                <EntitySelector
                                    value={selectedUser}
                                    onChange={(user) => {
                                        setSelectedUser(user);
                                        setData('user_id', user?.id || '');
                                    }}
                                    searchUrl="admin.users.search"
                                    placeholder="Выберите пользователя"
                                    displayField="name"
                                />
                            </FormField>

                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Баланс" error={errors.balance} required>
                                    <Input
                                        type="number"
                                        step="0.01"
                                        value={data.balance}
                                        onChange={(e) => setData('balance', e.target.value)}
                                        placeholder="0.00"
                                    />
                                </FormField>

                                <FormField label="Просроченная задолженность" error={errors.overdue_debt}>
                                    <Input
                                        type="number"
                                        step="0.01"
                                        value={data.overdue_debt}
                                        onChange={(e) => setData('overdue_debt', e.target.value)}
                                        placeholder="0.00"
                                    />
                                </FormField>
                            </SimpleGrid>

                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Обновлено в 1С">
                                    <Input
                                        value={balance.balance_erp_updated_at ? new Date(balance.balance_erp_updated_at).toLocaleString('ru-RU') : '—'}
                                        readOnly
                                        variant="flushed"
                                        bg="gray.50"
                                        _dark={{ bg: 'gray.800' }}
                                    />
                                </FormField>

                                <FormField label="Дата обновления">
                                    <Input
                                        value={balance.updated_at ? new Date(balance.updated_at).toLocaleString('ru-RU') : '—'}
                                        readOnly
                                        variant="flushed"
                                        bg="gray.50"
                                        _dark={{ bg: 'gray.800' }}
                                    />
                                </FormField>
                            </SimpleGrid>

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
