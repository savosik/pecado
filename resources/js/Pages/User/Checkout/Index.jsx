import { useState, useEffect, useMemo } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    Box, Flex, Text, Heading, Button, Table, Badge, Separator,
    Textarea, NativeSelect, RadioCard, Stack, Dialog, Portal, Input, SimpleGrid, HStack,
} from '@chakra-ui/react';
import { LuArrowLeft, LuPackage, LuWarehouse, LuSend, LuBuilding2, LuMapPin, LuMessageSquare, LuPlus, LuSearch, LuStore, LuTriangleAlert, LuTruck, LuWand } from 'react-icons/lu';
import axios from 'axios';
import UserLayout from '../UserLayout';
import Breadcrumbs from '@/components/common/Breadcrumbs';
import { toaster } from '@/components/ui/toaster';
import { Field } from '@/components/ui/field';
import { Checkbox } from '@/components/ui/checkbox';
import { PhoneInput } from '@/components/common/PhoneInput';
import { PartySuggest } from '@/components/common/PartySuggest';
import { EmailSuggest } from '@/components/common/EmailSuggest';
import { AddressSuggest } from '@/components/common/AddressSuggest';
import { AddressFieldWithMap } from '@/components/common/AddressFieldWithMap';
import { useDadataPartyAutofill } from '@/hooks/useDadataPartyAutofill';

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
    defaultDeliveryMethod = 'delivery',
}) {
    const { currency, errors: serverErrors, flash } = usePage().props;
    const currencySymbol = currency?.symbol ?? '₽';

    const breadcrumbs = [
        { label: 'Главная', url: '/' },
        { label: 'Корзина', url: '/cart' },
        { label: 'Оформление заказа' },
    ];

    const [companies, setCompanies] = useState(initialCompanies);

    // Адрес по умолчанию (или первый), от него зависит предвыбор на странице.
    const defaultAddress = addresses.find((a) => a.is_default) ?? addresses[0] ?? null;

    // Form state
    const { data, setData, post, processing, errors } = useForm({
        company_id: (initialCompanies.find(c => c.is_default) ?? initialCompanies[0])?.id ?? '',
        delivery_method: defaultDeliveryMethod,
        delivery_address: defaultDeliveryMethod === 'delivery' && defaultAddress ? defaultAddress.address : '',
        comment: '',
        manager_comment: '',
        warehouse_comment: '',
        // Сохранение нового адреса в список пользователя.
        save_address: false,
        address_name: '',
        address_make_default: false,
        address_data: null,
    });

    // Выбор адреса: id сохранённого адреса (строкой) либо 'new' для ручного ввода.
    const [addressChoice, setAddressChoice] = useState(
        defaultAddress ? String(defaultAddress.id) : 'new',
    );
    const useNewAddress = addressChoice === 'new';
    const [companyDialogOpen, setCompanyDialogOpen] = useState(false);
    const [normalizing, setNormalizing] = useState(false);
    // Локальный блок DaData для центровки карты адреса доставки (на сервер не отправляется).
    const [deliveryAddrData, setDeliveryAddrData] = useState(null);

    // Конфликты остатков: считаем по pre-flight stock_status в строках корзины.
    // Если бэк не успел обновить stock_status (race), подмешиваем серверный список.
    const stockConflictsFromFlash = flash?.stock_conflicts ?? [];

    const conflictItems = useMemo(() => {
        const fromItems = [...instockItems, ...preorderItems]
            .filter((it) => it.stock_status && it.stock_status !== 'ok')
            .map((it) => ({
                cart_item_id: it.id,
                product_id: it.product?.id,
                name: it.product?.name ?? 'Товар',
                sku: it.product?.sku,
                requested: Number(it.quantity || 0),
                available: Number(it.max_total ?? 0),
                status: it.stock_status,
            }));

        if (fromItems.length > 0) return fromItems;

        return (stockConflictsFromFlash || []).map((c) => ({
            cart_item_id: c.cart_item_id,
            product_id: c.product_id,
            name: c.name ?? c.product ?? 'Товар',
            sku: c.sku,
            requested: Number(c.requested || 0),
            available: Number(c.available || 0),
            status: Number(c.available || 0) <= 0 ? 'unavailable' : 'partial',
        }));
    }, [instockItems, preorderItems, stockConflictsFromFlash]);

    const hasConflicts = conflictItems.length > 0;

    // Серверная ошибка stock — показываем тостер только при появлении/изменении.
    useEffect(() => {
        if (serverErrors?.stock) {
            toaster.create({
                title: 'Корзина изменилась',
                description: serverErrors.stock,
                type: 'warning',
            });
        }
    }, [serverErrors?.stock]);

    const handleNormalize = () => {
        setNormalizing(true);
        router.post('/checkout/normalize-stock', {}, {
            preserveScroll: false,
            onFinish: () => setNormalizing(false),
        });
    };

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

                {hasConflicts && (
                    <StockConflictsPanel
                        items={conflictItems}
                        onNormalize={handleNormalize}
                        normalizing={normalizing}
                    />
                )}

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

                        {/* ═══ Общий итог — только если есть обе группы товаров ═══ */}
                        {instockItems.length > 0 && preorderItems.length > 0 && (
                        <Box
                            bg="bg"
                            borderWidth="1px"
                            borderColor="border"
                            rounded="lg"
                            p={{ base: '3', md: '5' }}
                        >
                            {(() => {
                                const grandRegular = Number(grandTotal.amount_regular || 0);
                                const grandDiscounted = Number(grandTotal.amount_discounted || 0);
                                const grandHasDiscount = grandRegular > grandDiscounted;
                                return (
                                    <Flex
                                        direction={{ base: 'column', md: 'row' }}
                                        justify="space-between"
                                        align={{ base: 'stretch', md: 'center' }}
                                        gap="3"
                                    >
                                        <Text fontWeight="600" fontSize="lg">
                                            Итого ({grandTotal.quantity ?? 0} шт.)
                                        </Text>
                                        <Stack gap="1" minW={{ md: '280px' }}>
                                            {grandHasDiscount ? (
                                                <>
                                                    <Flex justify="space-between">
                                                        <Text fontSize="sm" color="fg.muted">Сумма без скидки</Text>
                                                        <Text fontSize="sm" color="fg.muted" textDecoration="line-through">
                                                            {fmt(grandRegular)} {currencySymbol}
                                                        </Text>
                                                    </Flex>
                                                    <Flex justify="space-between">
                                                        <Text fontSize="sm" color="green.600" _dark={{ color: 'green.400' }}>
                                                            Сумма скидки
                                                        </Text>
                                                        <Text fontSize="sm" color="green.600" _dark={{ color: 'green.400' }}>
                                                            −{fmt(grandRegular - grandDiscounted)} {currencySymbol}
                                                        </Text>
                                                    </Flex>
                                                    <Flex justify="space-between" pt="1" borderTopWidth="1px" borderColor="border">
                                                        <Text fontSize="lg" fontWeight="bold">Итого</Text>
                                                        <Text fontSize="lg" fontWeight="bold">
                                                            {fmt(grandDiscounted)} {currencySymbol}
                                                        </Text>
                                                    </Flex>
                                                </>
                                            ) : (
                                                <Flex justify="space-between" gap="3">
                                                    <Text fontSize="lg" fontWeight="bold">Итого</Text>
                                                    <Text fontSize="lg" fontWeight="bold">
                                                        {fmt(grandDiscounted)} {currencySymbol}
                                                    </Text>
                                                </Flex>
                                            )}
                                        </Stack>
                                    </Flex>
                                );
                            })()}
                        </Box>
                        )}

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
                                <Box>
                                    <RadioCard.Root
                                        value={String(data.company_id)}
                                        onValueChange={({ value }) => setData('company_id', Number(value))}
                                        gap="2"
                                    >
                                        {companies.map((c) => (
                                            <RadioCard.Item key={c.id} value={String(c.id)} width="full">
                                                <RadioCard.ItemHiddenInput />
                                                <RadioCard.ItemControl>
                                                    <RadioCard.ItemContent>
                                                        <Flex align="center" gap="3" width="full">
                                                            <Box flex="1" minW="0">
                                                                <RadioCard.ItemText fontWeight="600" fontSize="sm">
                                                                    {c.name}
                                                                </RadioCard.ItemText>
                                                                {c.legal_name && (
                                                                    <Text fontSize="xs" color="fg.muted">{c.legal_name}</Text>
                                                                )}
                                                                {c.tax_id && (
                                                                    <Text fontSize="xs" color="fg.muted">ИНН: {c.tax_id}</Text>
                                                                )}
                                                            </Box>
                                                            {c.is_default && (
                                                                <Badge colorPalette="pecado" variant="subtle" fontSize="xs" flexShrink="0">
                                                                    По умолч.
                                                                </Badge>
                                                            )}
                                                        </Flex>
                                                    </RadioCard.ItemContent>
                                                    <RadioCard.ItemIndicator />
                                                </RadioCard.ItemControl>
                                            </RadioCard.Item>
                                        ))}
                                    </RadioCard.Root>
                                    {errors.company_id && (
                                        <Text color="red.500" fontSize="sm" mt="1">{errors.company_id}</Text>
                                    )}
                                </Box>
                            ) : (
                                <Text color="fg.muted" fontSize="sm">
                                    У вас нет зарегистрированных компаний. Добавьте первую, чтобы оформить заказ.
                                </Text>
                            )}
                        </Box>

                        {/* ═══ Способ доставки ═══ */}
                        <Box
                            bg="bg"
                            borderWidth="1px"
                            borderColor="border"
                            rounded="lg"
                            p={{ base: '4', md: '5' }}
                        >
                            <Flex align="center" gap="2" mb="3">
                                <LuTruck size={20} />
                                <Text fontWeight="600" fontSize="lg">Способ доставки</Text>
                            </Flex>

                            <RadioCard.Root
                                value={data.delivery_method}
                                onValueChange={({ value }) => {
                                    setData((prev) => ({
                                        ...prev,
                                        delivery_method: value,
                                        delivery_address: value === 'pickup'
                                            ? ''
                                            : (addressChoice !== 'new'
                                                ? (addresses.find((a) => String(a.id) === addressChoice)?.address ?? '')
                                                : ''),
                                    }));
                                }}
                                orientation="horizontal"
                                gap="2"
                            >
                                <Flex gap="2" direction={{ base: 'column', md: 'row' }} width="full">
                                    <RadioCard.Item value="delivery" flex="1">
                                        <RadioCard.ItemHiddenInput />
                                        <RadioCard.ItemControl>
                                            <RadioCard.ItemContent>
                                                <Flex align="center" gap="2">
                                                    <LuTruck size={16} />
                                                    <RadioCard.ItemText fontWeight="600" fontSize="sm">
                                                        Доставка
                                                    </RadioCard.ItemText>
                                                </Flex>
                                                <Text fontSize="xs" color="fg.muted">
                                                    Доставим по указанному адресу
                                                </Text>
                                            </RadioCard.ItemContent>
                                            <RadioCard.ItemIndicator />
                                        </RadioCard.ItemControl>
                                    </RadioCard.Item>

                                    <RadioCard.Item value="pickup" flex="1">
                                        <RadioCard.ItemHiddenInput />
                                        <RadioCard.ItemControl>
                                            <RadioCard.ItemContent>
                                                <Flex align="center" gap="2">
                                                    <LuStore size={16} />
                                                    <RadioCard.ItemText fontWeight="600" fontSize="sm">
                                                        Самовывоз
                                                    </RadioCard.ItemText>
                                                </Flex>
                                                <Text fontSize="xs" color="fg.muted">
                                                    Заберёте заказ со склада самостоятельно
                                                </Text>
                                            </RadioCard.ItemContent>
                                            <RadioCard.ItemIndicator />
                                        </RadioCard.ItemControl>
                                    </RadioCard.Item>
                                </Flex>
                            </RadioCard.Root>

                            {errors.delivery_method && (
                                <Text color="red.500" fontSize="sm" mt="2">{errors.delivery_method}</Text>
                            )}
                        </Box>

                        {/* ═══ Адрес доставки (скрыт при самовывозе) ═══ */}
                        {data.delivery_method === 'delivery' && (
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

                            <RadioCard.Root
                                value={addressChoice}
                                onValueChange={({ value }) => {
                                    setAddressChoice(value);
                                    if (value === 'new') {
                                        setData('delivery_address', '');
                                    } else {
                                        const addr = addresses.find((a) => String(a.id) === value);
                                        setData('delivery_address', addr?.address ?? '');
                                    }
                                }}
                                gap="2"
                            >
                                {addresses.map((a) => (
                                    <RadioCard.Item key={a.id} value={String(a.id)} width="full">
                                        <RadioCard.ItemHiddenInput />
                                        <RadioCard.ItemControl>
                                            <RadioCard.ItemContent>
                                                <Box flex="1" minW="0">
                                                    {a.name && (
                                                        <RadioCard.ItemText fontWeight="600" fontSize="sm">
                                                            {a.name}
                                                        </RadioCard.ItemText>
                                                    )}
                                                    <Text fontSize={a.name ? 'xs' : 'sm'} color={a.name ? 'fg.muted' : 'fg'}>
                                                        {a.address}
                                                    </Text>
                                                </Box>
                                            </RadioCard.ItemContent>
                                            <RadioCard.ItemIndicator />
                                        </RadioCard.ItemControl>
                                    </RadioCard.Item>
                                ))}

                                <RadioCard.Item value="new" width="full">
                                    <RadioCard.ItemHiddenInput />
                                    <RadioCard.ItemControl>
                                        <RadioCard.ItemContent>
                                            <RadioCard.ItemText fontWeight="600" fontSize="sm">
                                                Другой адрес
                                            </RadioCard.ItemText>
                                            <Text fontSize="xs" color="fg.muted">
                                                Указать новый адрес доставки
                                            </Text>
                                        </RadioCard.ItemContent>
                                        <RadioCard.ItemIndicator />
                                    </RadioCard.ItemControl>
                                </RadioCard.Item>
                            </RadioCard.Root>

                            {useNewAddress && (
                                <>
                                <Field
                                    label="Введите адрес доставки"
                                    invalid={!!errors.delivery_address}
                                    errorText={errors.delivery_address}
                                    mt="3"
                                >
                                    <AddressFieldWithMap
                                        value={data.delivery_address}
                                        onChange={(val) => setData('delivery_address', val)}
                                        addressData={deliveryAddrData}
                                        onAddressDataChange={(val) => {
                                            setDeliveryAddrData(val);
                                            setData('address_data', val);
                                        }}
                                        invalid={!!errors.delivery_address}
                                        placeholder="Город, улица, дом, квартира"
                                    />
                                </Field>

                                <Stack gap="3" mt="3">
                                    <Checkbox
                                        checked={data.save_address}
                                        onCheckedChange={({ checked }) => {
                                            setData('save_address', !!checked);
                                            if (!checked) {
                                                setData('address_make_default', false);
                                            }
                                        }}
                                    >
                                        Сохранить в мои адреса
                                    </Checkbox>

                                    {data.save_address && (
                                        <>
                                            <Field
                                                label="Название адреса"
                                                helperText="Необязательно, например «Офис» или «Склад»"
                                                invalid={!!errors.address_name}
                                                errorText={errors.address_name}
                                            >
                                                <Input
                                                    value={data.address_name}
                                                    onChange={(e) => setData('address_name', e.target.value)}
                                                    placeholder="Название адреса"
                                                />
                                            </Field>

                                            <Checkbox
                                                checked={data.address_make_default}
                                                onCheckedChange={({ checked }) => setData('address_make_default', !!checked)}
                                            >
                                                Сделать адресом по умолчанию
                                            </Checkbox>
                                        </>
                                    )}
                                </Stack>
                                </>
                            )}

                            {!useNewAddress && errors.delivery_address && (
                                <Text color="red.500" fontSize="sm" mt="2">{errors.delivery_address}</Text>
                            )}
                        </Box>
                        )}

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
                                <Text fontWeight="600" fontSize="lg">Комментарии к заказу</Text>
                            </Flex>

                            <Stack gap="4">
                                <Box>
                                    <Text fontSize="sm" fontWeight="500" mb="1.5" color="fg.muted">Общий комментарий</Text>
                                    <Textarea
                                        placeholder="Ваши пожелания или комментарии..."
                                        value={data.comment}
                                        onChange={(e) => setData('comment', e.target.value)}
                                        rows={2}
                                    />
                                </Box>

                                <Box>
                                    <Text fontSize="sm" fontWeight="500" mb="1.5" color="fg.muted">Комментарий для менеджера</Text>
                                    <Textarea
                                        placeholder="Заметка для менеджера: особые условия, счёт на ИП и т.д."
                                        value={data.manager_comment}
                                        onChange={(e) => setData('manager_comment', e.target.value)}
                                        rows={2}
                                    />
                                </Box>

                                <Box>
                                    <Text fontSize="sm" fontWeight="500" mb="1.5" color="fg.muted">Комментарий для склада</Text>
                                    <Textarea
                                        placeholder="Заметка для склада: упаковка, маркировка и т.д."
                                        value={data.warehouse_comment}
                                        onChange={(e) => setData('warehouse_comment', e.target.value)}
                                        rows={2}
                                    />
                                </Box>
                            </Stack>
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
                                disabled={processing || companies.length === 0 || hasConflicts}
                                title={hasConflicts ? 'Сначала уточните корзину — есть позиции с изменившимся остатком' : undefined}
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
        country: 'RU',
        name: '',
        legal_name: '',
        tax_id: '',
        registration_number: '',
        tax_code: '',
        okpo_code: '',
        legal_address: '',
        legal_address_data: null,
        actual_address: '',
        actual_address_data: null,
        phone: '',
        email: '',
    };

    const [form, setForm] = useState(emptyForm);
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const { applyParty, lookupByInn, lookingUp } = useDadataPartyAutofill(
        (fields) => setForm((prev) => ({ ...prev, ...fields })),
    );

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
                                        <PartySuggest
                                            value={form.name}
                                            onChange={(val) => handleChange('name', val)}
                                            onCompanySelected={applyParty}
                                            invalid={!!errText('name')}
                                            placeholder="Начните вводить название или ИНН"
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
                                        helperText="Введите ИНН и нажмите «Найти», чтобы автоматически заполнить реквизиты."
                                    >
                                        <HStack gap="2" align="stretch" w="full">
                                            <Input
                                                value={form.tax_id}
                                                onChange={(e) => handleChange('tax_id', e.target.value)}
                                                placeholder="7707083893"
                                            />
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="md"
                                                onClick={() => lookupByInn(form.tax_id, form.tax_code || null)}
                                                loading={lookingUp}
                                                title="Найти реквизиты по ИНН"
                                            >
                                                <LuSearch /> Найти
                                            </Button>
                                        </HStack>
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
                                    <AddressFieldWithMap
                                        value={form.legal_address}
                                        onChange={(val) => handleChange('legal_address', val)}
                                        addressData={form.legal_address_data}
                                        onAddressDataChange={(d) => setForm(prev => ({ ...prev, legal_address_data: d }))}
                                        invalid={!!errText('legal_address')}
                                        placeholder="г Москва, ул Примерная, д 1"
                                        mapHeight={260}
                                    />
                                </Field>

                                <Field
                                    label="Фактический адрес"
                                    invalid={!!errText('actual_address')}
                                    errorText={errText('actual_address')}
                                >
                                    <AddressFieldWithMap
                                        value={form.actual_address}
                                        onChange={(val) => handleChange('actual_address', val)}
                                        addressData={form.actual_address_data}
                                        onAddressDataChange={(d) => setForm(prev => ({ ...prev, actual_address_data: d }))}
                                        invalid={!!errText('actual_address')}
                                        placeholder="г Москва, ул Примерная, д 1, оф 100"
                                        mapHeight={260}
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
                                        <EmailSuggest
                                            value={form.email}
                                            onChange={(val) => handleChange('email', val)}
                                            invalid={!!errText('email')}
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
    const hasDiscount = totalRegular > 0 && totalRegular > totalDiscounted;

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
                            <StockBadge it={it} mt="2" />
                            <Flex justify="space-between" align="flex-end" gap="3" mt="2">
                                <Flex direction="column" fontSize="sm" color="fg.muted">
                                    {priceReg !== priceDisc && (
                                        <Text fontSize="xs" textDecoration="line-through">
                                            {fmt(priceReg)} {currencySymbol}
                                        </Text>
                                    )}
                                    <Text>
                                        {qty} шт × {fmt(priceDisc)} {currencySymbol}
                                    </Text>
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
                            <Table.ColumnHeader w="130px" textAlign="right">Цена без скидки</Table.ColumnHeader>
                            <Table.ColumnHeader w="80px" textAlign="right">Скидка</Table.ColumnHeader>
                            <Table.ColumnHeader w="130px" textAlign="right">Цена со скидкой</Table.ColumnHeader>
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
                            const discountPct = priceReg > 0 && priceReg > priceDisc
                                ? Math.round((1 - priceDisc / priceReg) * 100)
                                : 0;

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
                                    <Table.Cell textAlign="center">
                                        {qty}
                                        <StockBadge it={it} mt="1" justify="center" />
                                    </Table.Cell>
                                    <Table.Cell textAlign="right">{fmt(priceReg)}</Table.Cell>
                                    <Table.Cell textAlign="right">
                                        {discountPct > 0 ? `${discountPct}%` : '—'}
                                    </Table.Cell>
                                    <Table.Cell textAlign="right">{fmt(priceDisc)}</Table.Cell>
                                    <Table.Cell textAlign="right" fontWeight="600">
                                        {fmt(totalDisc)}
                                    </Table.Cell>
                                </Table.Row>
                            );
                        })}
                    </Table.Body>
                </Table.Root>
            </Box>

            <Separator my="3" />

            <Flex justify="flex-end">
                <Stack gap="1" minW="280px">
                    {hasDiscount ? (
                        <>
                            <Flex justify="space-between">
                                <Text fontSize="sm" color="fg.muted">Сумма без скидки</Text>
                                <Text fontSize="sm" color="fg.muted" textDecoration="line-through">
                                    {fmt(totalRegular)} {currencySymbol}
                                </Text>
                            </Flex>
                            <Flex justify="space-between">
                                <Text fontSize="sm" color="green.600" _dark={{ color: 'green.400' }}>
                                    Сумма скидки
                                </Text>
                                <Text fontSize="sm" color="green.600" _dark={{ color: 'green.400' }}>
                                    −{fmt(totalRegular - totalDiscounted)} {currencySymbol}
                                </Text>
                            </Flex>
                            <Flex justify="space-between" pt="1" borderTopWidth="1px" borderColor="border">
                                <Text fontSize="md" fontWeight="bold">Итого</Text>
                                <Text fontSize="md" fontWeight="bold">
                                    {fmt(totalDiscounted)} {currencySymbol}
                                </Text>
                            </Flex>
                        </>
                    ) : (
                        <Flex justify="space-between">
                            <Text fontSize="md" fontWeight="bold">Итого</Text>
                            <Text fontSize="md" fontWeight="bold">
                                {fmt(totalDiscounted)} {currencySymbol}
                            </Text>
                        </Flex>
                    )}
                </Stack>
            </Flex>
        </Box>
    );
}

/**
 * Маленький бейдж рядом с количеством, если остаток изменился.
 * status: ok | partial | unavailable
 */
function StockBadge({ it, ...flexProps }) {
    const status = it?.stock_status;
    if (!status || status === 'ok') return null;

    const requested = Number(it.quantity || 0);
    const available = Number(it.max_total ?? 0);

    return (
        <Flex {...flexProps}>
            {status === 'unavailable' ? (
                <Badge colorPalette="red" variant="subtle">
                    Нет в наличии
                </Badge>
            ) : (
                <Badge colorPalette="orange" variant="subtle">
                    Доступно {available} из {requested}
                </Badge>
            )}
        </Flex>
    );
}

/**
 * Панель конфликтов остатков, рендерится сверху страницы Checkout
 * при наличии хотя бы одной позиции со статусом partial/unavailable.
 *
 * Действия:
 *   • «Привести к доступному» — POST /checkout/normalize-stock
 *     (квантити уменьшаются до available, недоступные удаляются).
 *   • «Изменить вручную» — переход на /cart.
 */
function StockConflictsPanel({ items, onNormalize, normalizing }) {
    const partial = items.filter((c) => c.status === 'partial');
    const unavailable = items.filter((c) => c.status === 'unavailable');

    return (
        <Box
            bg="orange.subtle"
            borderWidth="1px"
            borderColor="orange.muted"
            rounded="lg"
            p={{ base: '4', md: '5' }}
            mb="4"
        >
            <Flex align="center" gap="2" mb="2" color="orange.fg">
                <LuTriangleAlert size={20} />
                <Text fontWeight="600" fontSize="lg">
                    Остатки на складе изменились
                </Text>
            </Flex>

            <Text fontSize="sm" color="fg.muted" mb="3">
                Пока вы оформляли заказ, по {items.length} {items.length === 1 ? 'позиции изменился' : 'позициям изменился'} остаток.
                {' '}Чтобы продолжить — приведите корзину к доступному количеству или измените её вручную.
            </Text>

            <Stack gap="2" mb="4">
                {items.slice(0, 8).map((c) => (
                    <Flex
                        key={c.cart_item_id}
                        justify="space-between"
                        gap="3"
                        align="center"
                        flexWrap="wrap"
                        fontSize="sm"
                    >
                        <Box flex="1" minW="0">
                            <Text fontWeight="medium" lineClamp={1}>{c.name}</Text>
                            {c.sku && (
                                <Text fontSize="xs" color="fg.muted">арт. {c.sku}</Text>
                            )}
                        </Box>
                        {c.status === 'unavailable' ? (
                            <Badge colorPalette="red" variant="subtle">
                                Нет в наличии (было {c.requested})
                            </Badge>
                        ) : (
                            <Badge colorPalette="orange" variant="subtle">
                                Доступно {c.available} из {c.requested}
                            </Badge>
                        )}
                    </Flex>
                ))}
                {items.length > 8 && (
                    <Text fontSize="xs" color="fg.muted">
                        …и ещё {items.length - 8} {items.length - 8 === 1 ? 'позиция' : 'позиций'}
                    </Text>
                )}
            </Stack>

            <Flex gap="3" flexWrap="wrap">
                <Button
                    type="button"
                    colorPalette="orange"
                    onClick={onNormalize}
                    loading={normalizing}
                    loadingText="Применяем…"
                >
                    <LuWand size={16} />
                    Привести к доступному
                </Button>
                <Button asChild type="button" variant="outline">
                    <Link href="/cart">
                        <LuArrowLeft size={16} />
                        Изменить вручную
                    </Link>
                </Button>
            </Flex>

            <Text fontSize="xs" color="fg.muted" mt="3">
                {partial.length > 0 && `Будет уменьшено количество: ${partial.length}.`}
                {partial.length > 0 && unavailable.length > 0 && ' '}
                {unavailable.length > 0 && `Будет удалено из корзины: ${unavailable.length}.`}
            </Text>
        </Box>
    );
}
