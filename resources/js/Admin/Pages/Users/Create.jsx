import { useRef } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, PhoneInput } from '@/Admin/Components';
import { Box, Card, Input, Textarea, Stack, SimpleGrid, HStack, Badge, Button, Text } from '@chakra-ui/react';
import PasswordInput from '@/components/ui/password-input';
import { Checkbox } from '@/components/ui/checkbox';

import { toaster } from '@/components/ui/toaster';

export default function Create({ regions, countries, statuses, userKinds, availableRoles, clientStatuses, personalManagers }) {
    const { data, setData, post, processing, errors, transform } = useForm({
        name: '',
        email: '',
        password: '',
        phone: '',
        country: '',
        city: '',
        region_id: '',
        client_status_id: '',
        personal_manager_id: '',
        roles: [],
        is_subscribed: false,
        terms_accepted: false,
        status: '',
        user_kind: 'client',
        comment: '',
        erp_id: '',
        send_welcome_email: false,
    });

    const closeAfterSaveRef = useRef(false);

    transform((data) => ({
        ...data,
        _close: closeAfterSaveRef.current ? 1 : 0,
    }));

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;
        post(route('admin.users.store'), {
            onSuccess: () => {
                toaster.create({
                    title: 'Пользователь создан',
                    description: 'Пользователь успешно добавлен в систему',
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
            <PageHeader title="Создать пользователя" description="Добавление нового пользователя в систему" />

            <form onSubmit={handleSubmit}>
                <Card.Root>
                    <Card.Body>
                        <Stack gap={6}>
                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Имя / Название" error={errors.name} required>
                                    <Input
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="Иванов Иван Иванович или ООО Рога и Копыта"
                                    />
                                </FormField>
                            </SimpleGrid>

                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Email" error={errors.email} required>
                                    <Input
                                        type="email"
                                        value={data.email}
                                        onChange={(e) => setData('email', e.target.value)}
                                        placeholder="user@example.com"
                                    />
                                </FormField>

                                <FormField label="Пароль" error={errors.password} required>
                                    <PasswordInput
                                        value={data.password}
                                        onChange={(e) => setData('password', e.target.value)}
                                        placeholder="Минимум 8 символов"
                                    />
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
                                        placeholder="Москва"
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
                                                {region.name}{region.currency ? ` (${region.currency.code})` : ''}
                                            </option>
                                        ))}
                                    </select>
                                </FormField>
                            </SimpleGrid>

                            <FormField
                                label="Тип аккаунта"
                                error={errors.user_kind}
                                helpText="Сотрудники и служебные учётки не попадают в CRM: их не видно в клиентах, планах продаж и покрытии задачами."
                            >
                                <select
                                    value={data.user_kind}
                                    onChange={(e) => setData('user_kind', e.target.value)}
                                    style={{ width: '100%', padding: '0.5rem', borderRadius: '0.375rem', border: '1px solid var(--chakra-colors-border)' }}
                                >
                                    {userKinds?.map((kind) => (
                                        <option key={kind.value} value={kind.value}>
                                            {kind.label}
                                        </option>
                                    ))}
                                </select>
                            </FormField>

                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Статус клиента" error={errors.client_status_id}>
                                    <select
                                        value={data.client_status_id}
                                        onChange={(e) => setData('client_status_id', e.target.value || '')}
                                        style={{ width: '100%', padding: '0.5rem', borderRadius: '0.375rem', border: '1px solid var(--chakra-colors-border)' }}
                                    >
                                        <option value="">Без статуса</option>
                                        {clientStatuses?.map((cs) => (
                                            <option key={cs.id} value={cs.id}>
                                                {cs.name}
                                            </option>
                                        ))}
                                    </select>
                                </FormField>

                                <FormField label="Персональный менеджер" error={errors.personal_manager_id}>
                                    <select
                                        value={data.personal_manager_id}
                                        onChange={(e) => setData('personal_manager_id', e.target.value || '')}
                                        style={{ width: '100%', padding: '0.5rem', borderRadius: '0.375rem', border: '1px solid var(--chakra-colors-border)' }}
                                    >
                                        <option value="">Без менеджера</option>
                                        {personalManagers?.map((pm) => (
                                            <option key={pm.id} value={pm.id}>
                                                {pm.name}
                                            </option>
                                        ))}
                                    </select>
                                </FormField>
                            </SimpleGrid>

                            {/* Выделенный блок статуса */}
                            <Box
                                p={4}
                                borderWidth="2px"
                                borderColor={
                                    data.status === 'active' ? 'green.500' :
                                        data.status === 'blocked' ? 'red.500' :
                                            data.status === 'processing' ? 'yellow.500' :
                                                'blue.300'
                                }
                                borderRadius="lg"
                                bg={
                                    data.status === 'active' ? 'green.50' :
                                        data.status === 'blocked' ? 'red.50' :
                                            data.status === 'processing' ? 'yellow.50' :
                                                'blue.50'
                                }
                            >
                                <HStack gap={3} align="center" mb={2}>
                                    <Badge
                                        colorPalette={
                                            data.status === 'active' ? 'green' :
                                                data.status === 'blocked' ? 'red' :
                                                    data.status === 'processing' ? 'yellow' :
                                                        'blue'
                                        }
                                        fontSize="sm"
                                        px={3}
                                        py={1}
                                    >
                                        Статус пользователя
                                    </Badge>
                                </HStack>
                                <HStack gap={2} flexWrap="wrap">
                                    {statuses.map((status) => {
                                        const colorMap = {
                                            active: 'green',
                                            blocked: 'red',
                                            processing: 'yellow',
                                        };
                                        const palette = colorMap[status.value] || 'gray';
                                        const isSelected = data.status === status.value;
                                        return (
                                            <Button
                                                key={status.value}
                                                type="button"
                                                size="sm"
                                                variant={isSelected ? 'solid' : 'outline'}
                                                colorPalette={palette}
                                                onClick={() => setData('status', status.value)}
                                                fontWeight={isSelected ? 'bold' : 'normal'}
                                            >
                                                {status.label}
                                            </Button>
                                        );
                                    })}
                                </HStack>
                                {errors.status && <Text color="red.500" fontSize="sm" mt={1}>{errors.status}</Text>}
                            </Box>

                            <FormField label="ERP ID" error={errors.erp_id}>
                                <Input
                                    value={data.erp_id}
                                    onChange={(e) => setData('erp_id', e.target.value)}
                                    placeholder="ID из внешней системы"
                                />
                            </FormField>

                            <FormField label="Комментарий" error={errors.comment}>
                                <Textarea
                                    value={data.comment}
                                    onChange={(e) => setData('comment', e.target.value)}
                                    placeholder="Дополнительная информация о пользователе"
                                    rows={3}
                                />
                            </FormField>

                            <Stack gap={2}>
                                <Text fontWeight="semibold" fontSize="sm" mb={1}>Роли</Text>
                                {availableRoles?.map((role) => (
                                    <Checkbox
                                        key={role.name}
                                        checked={data.roles.includes(role.name)}
                                        onCheckedChange={(e) => {
                                            const next = e.checked
                                                ? [...data.roles, role.name]
                                                : data.roles.filter(r => r !== role.name);
                                            setData('roles', next);
                                        }}
                                    >
                                        {role.name}
                                    </Checkbox>
                                ))}

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

                                <Checkbox
                                    checked={data.send_welcome_email}
                                    onCheckedChange={(e) => setData('send_welcome_email', e.checked)}
                                >
                                    Отправить приветственное письмо
                                </Checkbox>
                            </Stack>
                        </Stack>
                    </Card.Body>

                    <Card.Footer>
                        <FormActions
                            onSaveAndClose={handleSaveAndClose}
                            loading={processing}
                            onCancel={() => window.history.back()}
                            submitLabel="Создать пользователя"
                        />
                    </Card.Footer>
                </Card.Root>
            </form>
        </>
    );
}

Create.layout = (page) => <AdminLayout>{page}</AdminLayout>;
