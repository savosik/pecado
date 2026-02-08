import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, PhoneInput } from '@/Admin/Components';
import { Box, Card, Input, Textarea, Stack, SimpleGrid, Text } from '@chakra-ui/react';
import { Checkbox } from '@/components/ui/checkbox';

import { toaster } from '@/components/ui/toaster';

export default function Edit({ user, regions, currencies, countries }) {
    const { data, setData, put, processing, errors } = useForm({
        name: user.name || '',
        surname: user.surname

            || '',
        patronymic: user.patronymic || '',
        email: user.email || '',
        password: '', // Пустое значение - пароль не меняется
        phone: user.phone || '',
        country: user.country || '',
        city: user.city || '',
        region_id: user.region_id || '',
        currency_id: user.currency_id || '',
        is_admin: user.is_admin || false,
        is_subscribed: user.is_subscribed || false,
        terms_accepted: user.terms_accepted || false,
        status: user.status || '',
        comment: user.comment || '',
        erp_id: user.erp_id || '',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        put(route('admin.users.update', user.id), {
            onSuccess: () => {
                toaster.create({
                    title: 'Пользователь обновлен',
                    description: 'Информация о пользователе успешно обновлена',
                    type: 'success',
                });
            },
        });
    };

    return (
        <>
            <PageHeader
                title={`Редактирование: ${user.full_name}`}
                description="Изменение информации о пользователе"
            />

            <form onSubmit={handleSubmit}>
                <Card.Root>
                    <Card.Body>
                        <Stack gap={6}>
                            <SimpleGrid columns={{ base: 1, md: 3 }} gap={4}>
                                <FormField label="Фамилия" error={errors.surname}>
                                    <Input
                                        value={data.surname}
                                        onChange={(e) => setData('surname', e.target.value)}
                                    />
                                </FormField>

                                <FormField label="Имя" error={errors.name} required>
                                    <Input
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                    />
                                </FormField>

                                <FormField label="Отчество" error={errors.patronymic}>
                                    <Input
                                        value={data.patronymic}
                                        onChange={(e) => setData('patronymic', e.target.value)}
                                    />
                                </FormField>
                            </SimpleGrid>

                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Email" error={errors.email} required>
                                    <Input
                                        type="email"
                                        value={data.email}
                                        onChange={(e) => setData('email', e.target.value)}
                                    />
                                </FormField>

                                <FormField label="Пароль" error={errors.password}>
                                    <Input
                                        type="password"
                                        value={data.password}
                                        onChange={(e) => setData('password', e.target.value)}
                                        placeholder="Оставьте пустым, если не хотите менять"
                                    />
                                    <Text fontSize="xs" color="fg.muted" mt={1}>
                                        Оставьте поле пустым, чтобы не менять пароль
                                    </Text>
                                </FormField>
                            </SimpleGrid>

                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Телефон" error={errors.phone}>
                                    <PhoneInput
                                        value={data.phone}
                                        onChange={(value) => setData('phone', value)}
                                    />
                                </FormField>

                                <FormField label="Город" error={errors.city}>
                                    <Input
                                        value={data.city}
                                        onChange={(e) => setData('city', e.target.value)}
                                    />
                                </FormField>
                            </SimpleGrid>

                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Страна" error={errors.country}>
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

                                <FormField label="Регион" error={errors.region_id}>
                                    <select
                                        value={data.region_id}
                                        onChange={(e) => setData('region_id', e.target.value)}
                                        style={{ width: '100%', padding: '0.5rem', borderRadius: '0.375rem', border: '1px solid var(--chakra-colors-border)' }}
                                    >
                                        <option value="">Выберите регион</option>
                                        {regions.map((region) => (
                                            <option key={region.id} value={region.id}>
                                                {region.name}
                                            </option>
                                        ))}
                                    </select>
                                </FormField>
                            </SimpleGrid>

                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Валюта" error={errors.currency_id}>
                                    <select
                                        value={data.currency_id}
                                        onChange={(e) => setData('currency_id', e.target.value)}
                                        style={{ width: '100%', padding: '0.5rem', borderRadius: '0.375rem', border: '1px solid var(--chakra-colors-border)' }}
                                    >
                                        <option value="">Выберите валюту</option>
                                        {currencies.map((currency) => (
                                            <option key={currency.id} value={currency.id}>
                                                {currency.code} - {currency.name}
                                            </option>
                                        ))}
                                    </select>
                                </FormField>

                                <FormField label="Статус" error={errors.status}>
                                    <Input
                                        value={data.status}
                                        onChange={(e) => setData('status', e.target.value)}
                                    />
                                </FormField>
                            </SimpleGrid>

                            <FormField label="ERP ID" error={errors.erp_id}>
                                <Input
                                    value={data.erp_id}
                                    onChange={(e) => setData('erp_id', e.target.value)}
                                />
                            </FormField>

                            <FormField label="Комментарий" error={errors.comment}>
                                <Textarea
                                    value={data.comment}
                                    onChange={(e) => setData('comment', e.target.value)}
                                    rows={3}
                                />
                            </FormField>

                            <Stack gap={2}>
                                <Checkbox
                                    checked={data.is_admin}
                                    onCheckedChange={(e) => setData('is_admin', e.checked)}
                                >
                                    Администратор
                                </Checkbox>

                                <Checkbox
                                    checked={data.is_subscribed}
                                    onCheckedChange={(e) => setData('is_subscribed', e.checked)}
                                >
                                    Подписан на рассылку
                                </Checkbox>

                                <Checkbox
                                    checked={data.terms_accepted}
                                    onCheckedChange={(e) => setData('terms_accepted', e.checked)}
                                >
                                    Условия приняты
                                </Checkbox>
                            </Stack>
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
