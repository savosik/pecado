import { useState, useEffect } from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import {
    Box, Flex, Text, Heading, Button, Table, Badge, Separator,
    Textarea, NativeSelect, Stack, Dialog, Portal, Input, SimpleGrid,
} from '@chakra-ui/react';
import { LuArrowLeft, LuPackage, LuWarehouse, LuSend, LuBuilding2, LuMapPin, LuMessageSquare, LuPlus } from 'react-icons/lu';
import axios from 'axios';
import UserLayout from '../UserLayout';
import Breadcrumbs from '@/components/common/Breadcrumbs';
import { toaster } from '@/components/ui/toaster';
import { Field } from '@/components/ui/field';
import { PhoneInput } from '@/components/common/PhoneInput';

/**
 * Страница оформления заказа.
 *
 * Props от CheckoutController@index:
 *   - cart: { id, name }
 *   - instockItems, preorderItems
 *   - instockTotals, preorderTotals, grandTotal
 *   - companies, addresses
 */
export default function CheckoutIndex({
    cart,
    instockItems = [],
    preorderItems = [],
    instockTotals = {},
    preorderTotals = {},
    grandTotal = {},
    companies: initialCompanies = [],
    addresses = [],
    countries = [],
}) {
    const { currency, errors: serverErrors } = usePage().props;
    const currencySymbol = currency?.symbol ?? '₽';

    const breadcrumbs = [
        { label: 'Главная', url: '/' },
        { label: 'Корзина', url: '/cart' },
        { label: 'Оформление заказа' },
    ];

    const [companies, setCompanies] = useState(initialCompanies);

    // Form state
    const { data, setData, post, processing, errors } = useForm({
        company_id: initialCompanies.length > 0 ? initialCompanies[0].id : '',
        delivery_address: addresses.length > 0 ? addresses[0].address : '',
        comment: '',
    });

    const [useNewAddress, setUseNewAddress] = useState(addresses.length === 0);
    const [companyDialogOpen, setCompanyDialogOpen] = useState(false);

    // Show server stock error
    useEffect(() => {
        if (serverErrors?.stock) {
            toaster.create({
                title: 'Ошибка',
                description: serverErrors.stock,
                type: 'error',
            });
        }
    }, [serverErrors?.stock]);

    const handleSubmit = (e) => {
        e.preventDefault();
        post('/checkout', {
            preserveScroll: true,
        });
    };

    const fmt = (v) => Number(v || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    return (
        <UserLayout>
            <Head title="Оформление заказа" />
            <Breadcrumbs items={breadcrumbs} />

            <Box>
                <Heading as="h1" size={{ base: 'xl', md: '3xl' }} fontWeight="bold" mb="6">
                    Оформление заказа
                </Heading>

                <form onSubmit={handleSubmit}>
                    <Stack gap="4">
                        {/* ═══ Таблица: Товары со склада ═══ */}
                        {instockItems.length > 0 && (
                            <ItemTable
                                title="Товары со склада"
                                icon={<LuWarehouse size={20} />}
                                items={instockItems}
                                totals={instockTotals}
                                currencySymbol={currencySymbol}
                                colorPalette="green"
                                fmt={fmt}
                            />
                        )}

                        {/* ═══ Таблица: Товары по предзаказу ═══ */}
                        {preorderItems.length > 0 && (
                            <ItemTable
                                title="Товары по предзаказу"
                                icon={<LuPackage size={20} />}
                                items={preorderItems}
                                totals={preorderTotals}
                                currencySymbol={currencySymbol}
                                colorPalette="orange"
                                fmt={fmt}
                            />
                        )}

                        {/* ═══ Общий итог ═══ */}
                        <Box
                            bg="bg"
                            borderWidth="1px"
                            borderColor="border"
                            rounded="lg"
                            p={{ base: '3', md: '5' }}
                        >
                            <Flex justify="space-between" align="center">
                                <Text fontWeight="600" fontSize="lg">
                                    Итого ({grandTotal.quantity ?? 0} шт.)
                                </Text>
                                <Flex gap="3" align="center">
                                    {Number(grandTotal.amount_regular || 0) > Number(grandTotal.amount_discounted || 0) && (
                                        <Text fontSize="sm" color="fg.muted" textDecoration="line-through">
                                            {fmt(grandTotal.amount_regular)} {currencySymbol}
                                        </Text>
                                    )}
                                    <Text fontSize="xl" fontWeight="bold">
                                        {fmt(grandTotal.amount_discounted)} {currencySymbol}
                                    </Text>
                                </Flex>
                            </Flex>
                        </Box>

                        {/* ═══ Компания ═══ */}
                        <Box
                            bg="bg"
                            borderWidth="1px"
                            borderColor="border"
                            rounded="lg"
                            p={{ base: '4', md: '5' }}
                        >
                            <Flex align="center" justify="space-between" mb="3" gap="2" flexWrap="wrap">
                                <Flex align="center" gap="2">
                                    <LuBuilding2 size={20} />
                                    <Text fontWeight="600" fontSize="lg">Компания</Text>
                                </Flex>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    colorPalette="pecado"
                                    onClick={() => setCompanyDialogOpen(true)}
                                >
                                    <LuPlus size={14} />
                                    Добавить компанию
                                </Button>
                            </Flex>

                            {companies.length > 0 ? (
                                <Field
                                    label="Выберите компанию"
                                    invalid={!!errors.company_id}
                                    errorText={errors.company_id}
                                >
                                    <NativeSelect.Root size="md">
                                        <NativeSelect.Field
                                            value={data.company_id}
                                            onChange={(e) => setData('company_id', e.target.value)}
                                        >
                                            {companies.map((c) => (
                                                <option key={c.id} value={c.id}>
                                                    {c.name}{c.legal_name ? ` (${c.legal_name})` : ''}
                                                </option>
                                            ))}
                                        </NativeSelect.Field>
                                        <NativeSelect.Indicator />
                                    </NativeSelect.Root>
                                </Field>
                            ) : (
                                <Text color="fg.muted" fontSize="sm">
                                    У вас нет зарегистрированных компаний. Добавьте первую, чтобы оформить заказ.
                                </Text>
                            )}
                        </Box>

                        {/* ═══ Адрес доставки ═══ */}
                        <Box
                            bg="bg"
                            borderWidth="1px"
                            borderColor="border"
                            rounded="lg"
                            p={{ base: '4', md: '5' }}
                        >
                            <Flex align="center" gap="2" mb="3">
                                <LuMapPin size={20} />
                                <Text fontWeight="600" fontSize="lg">Адрес доставки</Text>
                            </Flex>

                            {addresses.length > 0 && (
                                <Flex gap="3" mb="3" flexWrap="wrap">
                                    <Button
                                        size="sm"
                                        variant={!useNewAddress ? 'solid' : 'outline'}
                                        colorPalette="pecado"
                                        onClick={() => {
                                            setUseNewAddress(false);
                                            setData('delivery_address', addresses[0].address);
                                        }}
                                    >
                                        Сохранённый адрес
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant={useNewAddress ? 'solid' : 'outline'}
                                        colorPalette="pecado"
                                        onClick={() => {
                                            setUseNewAddress(true);
                                            setData('delivery_address', '');
                                        }}
                                    >
                                        Другой адрес
                                    </Button>
                                </Flex>
                            )}

                            {!useNewAddress && addresses.length > 0 ? (
                                <Field
                                    label="Выберите адрес"
                                    invalid={!!errors.delivery_address}
                                    errorText={errors.delivery_address}
                                >
                                    <NativeSelect.Root size="md">
                                        <NativeSelect.Field
                                            value={data.delivery_address}
                                            onChange={(e) => setData('delivery_address', e.target.value)}
                                        >
                                            {addresses.map((a) => (
                                                <option key={a.id} value={a.address}>
                                                    {a.name ? `${a.name}: ` : ''}{a.address}
                                                </option>
                                            ))}
                                        </NativeSelect.Field>
                                        <NativeSelect.Indicator />
                                    </NativeSelect.Root>
                                </Field>
                            ) : (
                                <Field
                                    label="Введите адрес доставки"
                                    invalid={!!errors.delivery_address}
                                    errorText={errors.delivery_address}
                                >
                                    <Textarea
                                        placeholder="Город, улица, дом, квартира..."
                                        value={data.delivery_address}
                                        onChange={(e) => setData('delivery_address', e.target.value)}
                                        rows={3}
                                    />
                                </Field>
                            )}
                        </Box>

                        {/* ═══ Комментарий ═══ */}
                        <Box
                            bg="bg"
                            borderWidth="1px"
                            borderColor="border"
                            rounded="lg"
                            p={{ base: '4', md: '5' }}
                        >
                            <Flex align="center" gap="2" mb="3">
                                <LuMessageSquare size={20} />
                                <Text fontWeight="600" fontSize="lg">Комментарий к заказу</Text>
                            </Flex>

                            <Textarea
                                placeholder="Ваши пожелания или комментарии..."
                                value={data.comment}
                                onChange={(e) => setData('comment', e.target.value)}
                                rows={3}
                            />
                        </Box>

                        {/* ═══ Кнопки ═══ */}
                        <Flex
                            gap="3"
                            justify="space-between"
                            direction={{ base: 'column-reverse', md: 'row' }}
                        >
                            <Button asChild variant="outline" size="lg">
                                <Link href="/cart">
                                    <LuArrowLeft size={16} />
                                    Вернуться в корзину
                                </Link>
                            </Button>

                            <Button
                                type="submit"
                                colorPalette="pecado"
                                size="lg"
                                loading={processing}
                                disabled={processing || companies.length === 0}
                            >
                                <LuSend size={16} />
                                Оформить заказ
                            </Button>
                        </Flex>
                    </Stack>
                </form>
            </Box>

            <AddCompanyDialog
                open={companyDialogOpen}
                countries={countries}
                onClose={() => setCompanyDialogOpen(false)}
                onCreated={(company) => {
                    setCompanies((prev) => [...prev, company]);
                    setData('company_id', company.id);
                    setCompanyDialogOpen(false);
                    toaster.create({ title: 'Компания успешно создана', type: 'success' });
                }}
            />
        </UserLayout>
    );
}

/**
 * Диалог создания компании прямо со страницы Checkout.
 * Обязательные поля: Название, Страна, Юридическое название, ИНН.
 * Проверка уникальности ИНН выполняется на бэкенде по всей БД.
 */
function AddCompanyDialog({ open, countries, onClose, onCreated }) {
    const emptyForm = {
        country: '',
        name: '',
        legal_name: '',
        tax_id: '',
        registration_number: '',
        tax_code: '',
        okpo_code: '',
        legal_address: '',
        actual_address: '',
        phone: '',
        email: '',
    };

    const [form, setForm] = useState(emptyForm);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        if (open) {
            setForm(emptyForm);
            setErrors({});
        }
    }, [open]);

    const handleChange = (field, value) => {
        setForm((prev) => ({ ...prev, [field]: value }));
        setErrors((prev) => ({ ...prev, [field]: undefined }));
    };

    const validate = () => {
        const errs = {};
        if (!form.name.trim()) errs.name = 'Название обязательно.';
        if (!form.country) errs.country = 'Выберите страну.';
        if (!form.legal_name.trim()) errs.legal_name = 'Юридическое название обязательно.';
        if (!form.tax_id.trim()) errs.tax_id = 'ИНН обязателен.';
        if (form.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email)) errs.email = 'Введите корректный email.';
        return errs;
    };

    const errText = (key) => {
        const v = errors?.[key];
        return Array.isArray(v) ? v[0] : v;
    };

    const handleSubmit = async () => {
        const clientErrors = validate();
        if (Object.keys(clientErrors).length > 0) {
            setErrors(clientErrors);
            return;
        }

        setSaving(true);
        setErrors({});
        try {
            const { data } = await axios.post('/cabinet/companies/api', form);
            onCreated(data.company);
        } catch (err) {
            if (err.response?.status === 422) {
                setErrors(err.response.data.errors || {});
            } else {
                toaster.create({ title: 'Ошибка создания компании', type: 'error' });
            }
        } finally {
            setSaving(false);
        }
    };

    return (
        <Dialog.Root
            open={open}
            onOpenChange={({ open: isOpen }) => !isOpen && onClose()}
            size="lg"
        >
            <Portal>
                <Dialog.Backdrop />
                <Dialog.Positioner>
                    <Dialog.Content>
                        <Dialog.Header>
                            <Dialog.Title>Новая компания</Dialog.Title>
                        </Dialog.Header>
                        <Dialog.Body>
                            <Stack gap="4">
                                <SimpleGrid columns={{ base: 1, md: 2 }} gap="4">
                                    <Field
                                        label="Название"
                                        required
                                        invalid={!!errText('name')}
                                        errorText={errText('name')}
                                    >
                                        <Input
                                            value={form.name}
                                            onChange={(e) => handleChange('name', e.target.value)}
                                            placeholder="ООО Компания"
                                        />
                                    </Field>

                                    <Field
                                        label="Страна"
                                        required
                                        invalid={!!errText('country')}
                                        errorText={errText('country')}
                                    >
                                        <NativeSelect.Root size="md">
                                            <NativeSelect.Field
                                                value={form.country}
                                                onChange={(e) => handleChange('country', e.target.value)}
                                            >
                                                <option value="">Выберите страну</option>
                                                {countries.map((c) => (
                                                    <option key={c.value} value={c.value}>{c.label}</option>
                                                ))}
                                            </NativeSelect.Field>
                                            <NativeSelect.Indicator />
                                        </NativeSelect.Root>
                                    </Field>
                                </SimpleGrid>

                                <Field
                                    label="Юридическое название"
                                    required
                                    invalid={!!errText('legal_name')}
                                    errorText={errText('legal_name')}
                                >
                                    <Input
                                        value={form.legal_name}
                                        onChange={(e) => handleChange('legal_name', e.target.value)}
                                        placeholder="ООО «Компания»"
                                    />
                                </Field>

                                <SimpleGrid columns={{ base: 1, md: 2 }} gap="4">
                                    <Field
                                        label="ИНН"
                                        required
                                        invalid={!!errText('tax_id')}
                                        errorText={errText('tax_id')}
                                    >
                                        <Input
                                            value={form.tax_id}
                                            onChange={(e) => handleChange('tax_id', e.target.value)}
                                            placeholder="7707083893"
                                        />
                                    </Field>

                                    <Field
                                        label="ОГРН"
                                        invalid={!!errText('registration_number')}
                                        errorText={errText('registration_number')}
                                    >
                                        <Input
                                            value={form.registration_number}
                                            onChange={(e) => handleChange('registration_number', e.target.value)}
                                            placeholder="1027700132195"
                                        />
                                    </Field>
                                </SimpleGrid>

                                <SimpleGrid columns={{ base: 1, md: 2 }} gap="4">
                                    <Field
                                        label="КПП"
                                        invalid={!!errText('tax_code')}
                                        errorText={errText('tax_code')}
                                    >
                                        <Input
                                            value={form.tax_code}
                                            onChange={(e) => handleChange('tax_code', e.target.value)}
                                            placeholder="770701001"
                                        />
                                    </Field>

                                    <Field
                                        label="ОКПО"
                                        invalid={!!errText('okpo_code')}
                                        errorText={errText('okpo_code')}
                                    >
                                        <Input
                                            value={form.okpo_code}
                                            onChange={(e) => handleChange('okpo_code', e.target.value)}
                                            placeholder="00032537"
                                        />
                                    </Field>
                                </SimpleGrid>

                                <Field
                                    label="Юридический адрес"
                                    invalid={!!errText('legal_address')}
                                    errorText={errText('legal_address')}
                                >
                                    <Textarea
                                        rows={2}
                                        value={form.legal_address}
                                        onChange={(e) => handleChange('legal_address', e.target.value)}
                                        placeholder="г. Москва, ул. Примерная, д. 1"
                                    />
                                </Field>

                                <Field
                                    label="Фактический адрес"
                                    invalid={!!errText('actual_address')}
                                    errorText={errText('actual_address')}
                                >
                                    <Textarea
                                        rows={2}
                                        value={form.actual_address}
                                        onChange={(e) => handleChange('actual_address', e.target.value)}
                                        placeholder="г. Москва, ул. Примерная, д. 1, оф. 100"
                                    />
                                </Field>

                                <SimpleGrid columns={{ base: 1, md: 2 }} gap="4">
                                    <Field
                                        label="Телефон"
                                        invalid={!!errText('phone')}
                                        errorText={errText('phone')}
                                    >
                                        <PhoneInput
                                            value={form.phone}
                                            onChange={(val) => handleChange('phone', val)}
                                        />
                                    </Field>

                                    <Field
                                        label="Email"
                                        invalid={!!errText('email')}
                                        errorText={errText('email')}
                                    >
                                        <Input
                                            type="email"
                                            value={form.email}
                                            onChange={(e) => handleChange('email', e.target.value)}
                                            placeholder="company@example.com"
                                        />
                                    </Field>
                                </SimpleGrid>
                            </Stack>
                        </Dialog.Body>
                        <Dialog.Footer>
                            <Button variant="outline" onClick={onClose} disabled={saving}>
                                Отмена
                            </Button>
                            <Button
                                colorPalette="pecado"
                                onClick={handleSubmit}
                                loading={saving}
                                loadingText="Создание..."
                            >
                                Создать компанию
                            </Button>
                        </Dialog.Footer>
                    </Dialog.Content>
                </Dialog.Positioner>
            </Portal>
        </Dialog.Root>
    );
}

/**
 * Компонент таблицы товаров (instock / preorder).
 */
function ItemTable({ title, icon, items, totals, currencySymbol, colorPalette, fmt }) {
    const totalQty = totals?.quantity ?? items.reduce((s, it) => s + Number(it.quantity || 0), 0);
    const totalRegular = Number(totals?.amount_regular ?? 0);
    const totalDiscounted = Number(totals?.amount_discounted ?? 0);
    const hasDiscount = totalRegular > 0 && totalRegular !== totalDiscounted;

    return (
        <Box
            bg="bg"
            borderWidth="1px"
            borderColor="border"
            rounded="lg"
            p={{ base: '3', md: '5' }}
        >
            <Flex align="center" gap="2" mb="3">
                {icon}
                <Text fontWeight="600" fontSize="lg">
                    {title}
                </Text>
                <Badge colorPalette={colorPalette} variant="subtle" ml="1">
                    {totalQty} шт.
                </Badge>
            </Flex>

            {/* Мобильная раскладка — карточки товаров вместо таблицы */}
            <Stack gap="3" display={{ base: 'flex', md: 'none' }}>
                {items.map((it) => {
                    const pid = it.product?.id || it.id;
                    const qty = Number(it.quantity || 0);
                    const priceDisc = Number(it.price_discounted ?? it.price ?? 0);
                    const priceReg = Number(it.price_regular ?? priceDisc);
                    const totalDisc = Number(it.total_amount_discounted ?? it.total_amount ?? 0);

                    return (
                        <Box
                            key={pid}
                            borderWidth="1px"
                            borderColor="border"
                            rounded="md"
                            p="3"
                        >
                            <Text fontWeight="medium" lineClamp={2}>
                                {it.product?.name || 'Товар'}
                            </Text>
                            {(it.product?.brand?.name || it.product?.sku) && (
                                <Flex gap="1" mt="0.5" flexWrap="wrap">
                                    {it.product?.brand?.name && (
                                        <Text fontSize="xs" color="fg.muted">
                                            {it.product.brand.name}
                                        </Text>
                                    )}
                                    {it.product?.sku && (
                                        <Text fontSize="xs" color="fg.muted">
                                            • {it.product.sku}
                                        </Text>
                                    )}
                                </Flex>
                            )}
                            <Flex justify="space-between" align="flex-end" gap="3" mt="2">
                                <Flex direction="column" fontSize="sm" color="fg.muted">
                                    <Text>
                                        {qty} шт × {fmt(priceDisc)} {currencySymbol}
                                    </Text>
                                    {priceReg !== priceDisc && (
                                        <Text fontSize="xs" textDecoration="line-through">
                                            {fmt(priceReg)} {currencySymbol}
                                        </Text>
                                    )}
                                </Flex>
                                <Text fontWeight="600" fontSize="md" whiteSpace="nowrap">
                                    {fmt(totalDisc)} {currencySymbol}
                                </Text>
                            </Flex>
                        </Box>
                    );
                })}
            </Stack>

            {/* Таблица — на планшетах и шире */}
            <Box overflowX="auto" display={{ base: 'none', md: 'block' }}>
                <Table.Root size="sm" variant="outline">
                    <Table.Header>
                        <Table.Row bg="bg.muted">
                            <Table.ColumnHeader>Название</Table.ColumnHeader>
                            <Table.ColumnHeader w="90px" textAlign="center">Кол-во</Table.ColumnHeader>
                            <Table.ColumnHeader w="130px" textAlign="right">Цена ({currencySymbol})</Table.ColumnHeader>
                            <Table.ColumnHeader w="130px" textAlign="right">Сумма ({currencySymbol})</Table.ColumnHeader>
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {items.map((it) => {
                            const pid = it.product?.id || it.id;
                            const qty = Number(it.quantity || 0);
                            const priceDisc = Number(it.price_discounted ?? it.price ?? 0);
                            const priceReg = Number(it.price_regular ?? priceDisc);
                            const totalDisc = Number(it.total_amount_discounted ?? it.total_amount ?? 0);

                            return (
                                <Table.Row key={pid}>
                                    <Table.Cell>
                                        <Text fontWeight="medium" lineClamp={1}>
                                            {it.product?.name || 'Товар'}
                                        </Text>
                                        <Flex gap="1" mt="0.5">
                                            {it.product?.brand?.name && (
                                                <Text fontSize="xs" color="fg.muted">
                                                    {it.product.brand.name}
                                                </Text>
                                            )}
                                            {it.product?.sku && (
                                                <Text fontSize="xs" color="fg.muted">
                                                    • {it.product.sku}
                                                </Text>
                                            )}
                                        </Flex>
                                    </Table.Cell>
                                    <Table.Cell textAlign="center">{qty}</Table.Cell>
                                    <Table.Cell textAlign="right">
                                        {priceReg !== priceDisc && (
                                            <Text fontSize="xs" color="fg.muted" textDecoration="line-through">
                                                {fmt(priceReg)}
                                            </Text>
                                        )}
                                        <Text fontWeight="medium">{fmt(priceDisc)}</Text>
                                    </Table.Cell>
                                    <Table.Cell textAlign="right">
                                        <Text fontWeight="medium">{fmt(totalDisc)}</Text>
                                    </Table.Cell>
                                </Table.Row>
                            );
                        })}
                    </Table.Body>
                </Table.Root>
            </Box>

            <Separator my="3" />

            <Flex justify="flex-end" gap="4" align="center">
                {hasDiscount && (
                    <Text fontSize="sm" color="fg.muted" textDecoration="line-through">
                        {fmt(totalRegular)} {currencySymbol}
                    </Text>
                )}
                <Text fontSize="lg" fontWeight="bold">
                    {fmt(totalDiscounted)} {currencySymbol}
                </Text>
            </Flex>
        </Box>
    );
}
