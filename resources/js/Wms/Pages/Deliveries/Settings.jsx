import { useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Badge, Box, Card, HStack, Input, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import { LuCircleCheck, LuCopy, LuPlug } from 'react-icons/lu';
import WmsLayout from '@/Wms/Layouts/WmsLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field } from '@/components/ui/field';
import { Switch } from '@/components/ui/switch';
import { toaster } from '@/components/ui/toaster';
import { usePermission } from '@/shared/Panel/usePermission';
import { useFlashToast } from '@/hooks/useFlashToast';

/**
 * Настройки интеграции с ApiShip.
 *
 * Секреты сюда не приезжают — только признак «задано». Пустое поле означает
 * «не менять»: иначе каждое сохранение формы стирало бы токен.
 */
export default function DeliverySettings() {
    const { settings, missing, integrationEnabled, webhookUrl } = usePage().props;
    const { can } = usePermission();
    useFlashToast();

    const canEdit = can('wms-delivery-settings.edit');
    const [testing, setTesting] = useState(false);

    const { data, setData, put, processing, errors } = useForm({
        enabled: !!settings.enabled,
        base_url: settings.base_url || 'https://api.apiship.ru/v1',
        token: '',
        clear_token: false,
        login: settings.login || '',
        password: '',
        clear_password: false,
        timeout: settings.timeout || 30,

        webhook_enabled: !!settings.webhook_enabled,
        webhook_secret: '',
        clear_webhook_secret: false,

        sender_company_name: settings.sender_company_name || '',
        sender_contact_name: settings.sender_contact_name || '',
        sender_phone: settings.sender_phone || '',
        sender_email: settings.sender_email || '',
        sender_country_code: settings.sender_country_code || 'RU',
        sender_index: settings.sender_index || '',
        sender_region: settings.sender_region || '',
        sender_city: settings.sender_city || '',
        sender_street: settings.sender_street || '',
        sender_house: settings.sender_house || '',

        default_item_weight_grams: settings.default_item_weight_grams || 500,
        default_place_length: settings.default_place_length || 40,
        default_place_width: settings.default_place_width || 30,
        default_place_height: settings.default_place_height || 20,
    });

    const submit = (event) => {
        event.preventDefault();
        put('/wms/delivery-settings', { preserveScroll: true });
    };

    const testConnection = () => {
        setTesting(true);
        router.post('/wms/delivery-settings/test', {}, {
            preserveScroll: true,
            onFinish: () => setTesting(false),
        });
    };

    const copyWebhookUrl = () => {
        navigator.clipboard?.writeText(webhookUrl).then(
            () => toaster.create({ title: 'Адрес вебхука скопирован', type: 'success' }),
            () => toaster.create({ title: 'Скопировать не удалось', type: 'error' }),
        );
    };

    /** Поле секрета: показывает, задано ли значение, и умеет его стирать. */
    const SecretField = ({ label, name, clearName, hint }) => (
        <Field
            label={label}
            helperText={hint}
            errorText={errors[name]}
            invalid={!!errors[name]}
        >
            <VStack align="stretch" gap={1}>
                <HStack gap={2}>
                    <Input
                        size="sm"
                        type="password"
                        autoComplete="new-password"
                        value={data[name]}
                        disabled={!canEdit || data[clearName]}
                        onChange={(event) => setData(name, event.target.value)}
                        placeholder={settings[`${name}_is_set`] ? 'Задано — оставьте пустым, чтобы не менять' : 'Не задано'}
                    />
                    {settings[`${name}_is_set`] && (
                        <Badge colorPalette="green" size="sm" flexShrink={0}>
                            <LuCircleCheck size={11} /> задано
                        </Badge>
                    )}
                </HStack>

                {settings[`${name}_is_set`] && canEdit && (
                    <Checkbox
                        size="sm"
                        checked={data[clearName]}
                        onCheckedChange={() => setData(clearName, !data[clearName])}
                    >
                        <Text fontSize="xs">Стереть значение</Text>
                    </Checkbox>
                )}
            </VStack>
        </Field>
    );

    return (
        <>
            <Head title="Настройки доставки — Склад" />
            <PageHeader
                title="Настройки ApiShip"
                description="Доступы к агрегатору служб доставки, адрес отправителя и значения по умолчанию."
                actions={canEdit && (
                    <Button size="sm" variant="outline" onClick={testConnection} loading={testing}>
                        <LuPlug /> Проверить связь
                    </Button>
                )}
            />

            <form onSubmit={submit}>
                <VStack gap={4} align="stretch">
                    <Card.Root borderColor={integrationEnabled ? 'green.400' : 'orange.400'} borderWidth="1px">
                        <Card.Body py={3}>
                            <HStack justify="space-between" flexWrap="wrap" gap={2}>
                                <Text fontSize="sm">
                                    {integrationEnabled
                                        ? 'Интеграция включена: расчёт и передача заявок работают.'
                                        : 'Интеграция выключена — отправки можно собирать, но перевозчику они не уйдут.'}
                                </Text>
                                {missing.length > 0 && (
                                    <Text fontSize="sm" color="orange.500">
                                        Не заполнено: {missing.join(', ')}.
                                    </Text>
                                )}
                            </HStack>
                        </Card.Body>
                    </Card.Root>

                    <Card.Root>
                        <Card.Header><Text fontWeight="bold">Доступ к API</Text></Card.Header>
                        <Card.Body>
                            <VStack gap={4} align="stretch">
                                <Switch
                                    checked={data.enabled}
                                    disabled={!canEdit}
                                    onCheckedChange={({ checked }) => setData('enabled', checked)}
                                >
                                    Интеграция включена
                                </Switch>

                                <SimpleGrid columns={{ base: 1, md: 2 }} gap={3}>
                                    <Field
                                        label="Адрес API"
                                        required
                                        helperText="Боевой — https://api.apiship.ru/v1, тестовый — http://api.dev.apiship.ru/v1"
                                        errorText={errors.base_url}
                                        invalid={!!errors.base_url}
                                    >
                                        <Input
                                            size="sm"
                                            value={data.base_url}
                                            disabled={!canEdit}
                                            onChange={(event) => setData('base_url', event.target.value)}
                                        />
                                    </Field>

                                    <Field
                                        label="Таймаут запроса, секунды"
                                        required
                                        errorText={errors.timeout}
                                        invalid={!!errors.timeout}
                                    >
                                        <Input
                                            size="sm"
                                            type="number"
                                            value={data.timeout}
                                            disabled={!canEdit}
                                            onChange={(event) => setData('timeout', event.target.value)}
                                        />
                                    </Field>
                                </SimpleGrid>

                                <SecretField
                                    label="API-токен"
                                    name="token"
                                    clearName="clear_token"
                                    hint="Из личного кабинета ApiShip. Если задан, логин и пароль не нужны."
                                />

                                <SimpleGrid columns={{ base: 1, md: 2 }} gap={3}>
                                    <Field label="Логин" helperText="Нужен только если токен не задан">
                                        <Input
                                            size="sm"
                                            value={data.login}
                                            disabled={!canEdit}
                                            onChange={(event) => setData('login', event.target.value)}
                                        />
                                    </Field>

                                    <SecretField label="Пароль" name="password" clearName="clear_password" />
                                </SimpleGrid>
                            </VStack>
                        </Card.Body>
                    </Card.Root>

                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="bold">Вебхук статусов</Text>
                            <Text fontSize="sm" color="fg.muted">
                                Через него перевозчик сообщает о движении груза. Без вебхука статусы
                                подтягиваются сверкой раз в полчаса.
                            </Text>
                        </Card.Header>
                        <Card.Body>
                            <VStack gap={4} align="stretch">
                                <Switch
                                    checked={data.webhook_enabled}
                                    disabled={!canEdit}
                                    onCheckedChange={({ checked }) => setData('webhook_enabled', checked)}
                                >
                                    Принимать вебхуки
                                </Switch>

                                <SecretField
                                    label="Секрет вебхука"
                                    name="webhook_secret"
                                    clearName="clear_webhook_secret"
                                    hint="Подписи у ApiShip нет — секрет уходит прямо в адресе, поэтому от 16 символов."
                                />

                                {webhookUrl && (
                                    <Box borderWidth="1px" borderColor="border" borderRadius="md" p={3} bg="bg.subtle">
                                        <Text fontSize="xs" color="fg.muted" mb={1}>
                                            Адрес для подписки в кабинете ApiShip
                                        </Text>
                                        <HStack gap={2}>
                                            <Text fontSize="xs" fontFamily="mono" lineClamp={1}>{webhookUrl}</Text>
                                            <Button size="xs" variant="outline" onClick={copyWebhookUrl}>
                                                <LuCopy /> Копировать
                                            </Button>
                                        </HStack>
                                    </Box>
                                )}
                            </VStack>
                        </Card.Body>
                    </Card.Root>

                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="bold">Отправитель</Text>
                            <Text fontSize="sm" color="fg.muted">
                                Наш склад. От этого адреса перевозчики считают тарифы, сюда же приезжает курьер.
                            </Text>
                        </Card.Header>
                        <Card.Body>
                            <SimpleGrid columns={{ base: 1, md: 3 }} gap={3}>
                                <Field label="Компания">
                                    <Input size="sm" value={data.sender_company_name} disabled={!canEdit}
                                        onChange={(event) => setData('sender_company_name', event.target.value)} />
                                </Field>
                                <Field label="Контактное лицо">
                                    <Input size="sm" value={data.sender_contact_name} disabled={!canEdit}
                                        onChange={(event) => setData('sender_contact_name', event.target.value)} />
                                </Field>
                                <Field label="Телефон" errorText={errors.sender_phone} invalid={!!errors.sender_phone}>
                                    <Input size="sm" value={data.sender_phone} disabled={!canEdit}
                                        onChange={(event) => setData('sender_phone', event.target.value)} />
                                </Field>
                                <Field label="Email" errorText={errors.sender_email} invalid={!!errors.sender_email}>
                                    <Input size="sm" value={data.sender_email} disabled={!canEdit}
                                        onChange={(event) => setData('sender_email', event.target.value)} />
                                </Field>
                                <Field label="Код страны" errorText={errors.sender_country_code} invalid={!!errors.sender_country_code}>
                                    <Input size="sm" value={data.sender_country_code} disabled={!canEdit}
                                        onChange={(event) => setData('sender_country_code', event.target.value)} />
                                </Field>
                                <Field label="Индекс">
                                    <Input size="sm" value={data.sender_index} disabled={!canEdit}
                                        onChange={(event) => setData('sender_index', event.target.value)} />
                                </Field>
                                <Field label="Регион">
                                    <Input size="sm" value={data.sender_region} disabled={!canEdit}
                                        onChange={(event) => setData('sender_region', event.target.value)} />
                                </Field>
                                <Field label="Город">
                                    <Input size="sm" value={data.sender_city} disabled={!canEdit}
                                        onChange={(event) => setData('sender_city', event.target.value)} />
                                </Field>
                                <Field label="Улица">
                                    <Input size="sm" value={data.sender_street} disabled={!canEdit}
                                        onChange={(event) => setData('sender_street', event.target.value)} />
                                </Field>
                                <Field label="Дом">
                                    <Input size="sm" value={data.sender_house} disabled={!canEdit}
                                        onChange={(event) => setData('sender_house', event.target.value)} />
                                </Field>
                            </SimpleGrid>
                        </Card.Body>
                    </Card.Root>

                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="bold">Значения по умолчанию</Text>
                            <Text fontSize="sm" color="fg.muted">
                                Вес — в граммах, габариты — в сантиметрах: так их требует ApiShip.
                            </Text>
                        </Card.Header>
                        <Card.Body>
                            <SimpleGrid columns={{ base: 2, md: 4 }} gap={3}>
                                <Field
                                    label="Вес позиции, г"
                                    required
                                    helperText="Для товаров без веса в карточке"
                                    errorText={errors.default_item_weight_grams}
                                    invalid={!!errors.default_item_weight_grams}
                                >
                                    <Input size="sm" type="number" value={data.default_item_weight_grams} disabled={!canEdit}
                                        onChange={(event) => setData('default_item_weight_grams', event.target.value)} />
                                </Field>
                                <Field label="Длина коробки, см" required errorText={errors.default_place_length} invalid={!!errors.default_place_length}>
                                    <Input size="sm" type="number" value={data.default_place_length} disabled={!canEdit}
                                        onChange={(event) => setData('default_place_length', event.target.value)} />
                                </Field>
                                <Field label="Ширина коробки, см" required errorText={errors.default_place_width} invalid={!!errors.default_place_width}>
                                    <Input size="sm" type="number" value={data.default_place_width} disabled={!canEdit}
                                        onChange={(event) => setData('default_place_width', event.target.value)} />
                                </Field>
                                <Field label="Высота коробки, см" required errorText={errors.default_place_height} invalid={!!errors.default_place_height}>
                                    <Input size="sm" type="number" value={data.default_place_height} disabled={!canEdit}
                                        onChange={(event) => setData('default_place_height', event.target.value)} />
                                </Field>
                            </SimpleGrid>
                        </Card.Body>
                    </Card.Root>

                    {canEdit && (
                        <HStack justify="end">
                            <Button type="submit" loading={processing}>Сохранить настройки</Button>
                        </HStack>
                    )}
                </VStack>
            </form>
        </>
    );
}

DeliverySettings.layout = (page) => <WmsLayout>{page}</WmsLayout>;
