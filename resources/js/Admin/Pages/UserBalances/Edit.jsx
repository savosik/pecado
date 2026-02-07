import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, EntitySelector } from '@/Admin/Components';
import { Card, Input, Stack, SimpleGrid } from '@chakra-ui/react';
import { toaster } from '@/components/ui/toaster';

export default function Edit({ balance, currencies }) {
    const [selectedUser, setSelectedUser] = useState(balance.user || null);
    const { data, setData, put, processing, errors } = useForm({
        user_id: balance.user_id || '',
        currency_id: balance.currency_id || '',
        balance: balance.balance || '0.00',
        overdue_debt: balance.overdue_debt || '0.00',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
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

    return (
        <>
            <PageHeader title={`Редактировать баланс: ${balance.user.name} (${balance.currency.code})`} />

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

                            <FormField label="Валюта" error={errors.currency_id} required>
                                <select
                                    value={data.currency_id}
                                    onChange={(e) => setData('currency_id', e.target.value)}
                                    style={{
                                        width: '100%',
                                        padding: '0.5rem',
                                        borderRadius: '0.375rem',
                                        border: '1px solid var(--chakra-colors-border)'
                                    }}
                                >
                                    <option value="">Выберите валюту</option>
                                    {currencies.map((currency) => (
                                        <option key={currency.id} value={currency.id}>
                                            {currency.code} - {currency.name} ({currency.symbol})
                                        </option>
                                    ))}
                                </select>
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

                            <FormActions
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
