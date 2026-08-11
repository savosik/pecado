import { useEffect, useMemo, useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    Box,
    Card,
    HStack,
    Input,
    SimpleGrid,
    Text,
    Textarea,
    VStack,
} from '@chakra-ui/react';
import { LuPackagePlus, LuPackageSearch, LuTrash2, LuTriangleAlert } from 'react-icons/lu';
import WmsLayout from '@/Wms/Layouts/WmsLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';
import { toaster } from '@/components/ui/toaster';
import { AddressSuggest } from '@/components/common/AddressSuggest';
import { useFlashToast } from '@/hooks/useFlashToast';
import { ShipmentSelector } from '@/Wms/Components/ShipmentSelector';
import { formatMoney, formatWeight } from '@/Wms/Components/deliveryFormat';

const ADDRESS_SOURCES = [
    { value: 'order', label: 'Из заказа клиента' },
    { value: 'client', label: 'Из адресов клиента' },
    { value: 'manual', label: 'Ввести вручную' },
];

/** Пустое место с габаритами типовой коробки. */
const makePlace = (defaults) => ({
    weight: '',
    length: defaults.length,
    width: defaults.width,
    height: defaults.height,
});

export default function DeliveriesCreate() {
    const { defaults, integrationEnabled, preselected, delivery } = usePage().props;
    useFlashToast();

    // Одна форма на два режима: у отправки состав, места и получатель те же самые,
    // и вторая копия карточки разъехалась бы с первой на первой же правке.
    const isEdit = Boolean(delivery);

    // ─── Шаг 1: реализации ───
    // Приезжают предвыбранными из раздела «Реализации к доставке»; здесь состав
    // можно только подправить компактным поиском.
    const [selected, setSelected] = useState(preselected || []);

    // ─── Шаг 2: груз ───
    const [deliveryType, setDeliveryType] = useState(delivery?.delivery_type ?? defaults.deliveryType);
    const [pickupType, setPickupType] = useState(delivery?.pickup_type ?? defaults.pickupType);
    const [pickupDate, setPickupDate] = useState(delivery?.pickup_date ?? '');
    const [comment, setComment] = useState(delivery?.comment ?? '');
    const [places, setPlaces] = useState(
        delivery?.places?.length ? delivery.places : [makePlace(defaults.place)],
    );
    // Пока кладовщик не тронул вес руками, единственное место держим равным
    // расчётному весу груза: система его уже посчитала и показала выше, и
    // заставлять переписывать то же число в соседнее поле незачем. При правке
    // вес уже задан — его подменять нельзя.
    const [weightTouched, setWeightTouched] = useState(isEdit);

    // ─── Шаг 3: получатель ───
    const [addressSource, setAddressSource] = useState('manual');
    const [recipientOptions, setRecipientOptions] = useState({
        contact: null,
        order_addresses: [],
        client_addresses: [],
    });
    const [selectedAddressId, setSelectedAddressId] = useState('');
    const [manualAddress, setManualAddress] = useState('');
    const [resolvedAddress, setResolvedAddress] = useState(delivery?.recipient ?? null);
    const [resolving, setResolving] = useState(false);
    const [contact, setContact] = useState({
        contactName: delivery?.recipient?.contactName ?? '',
        phone: delivery?.recipient?.phone ?? '',
        email: delivery?.recipient?.email ?? '',
        companyName: delivery?.recipient?.companyName ?? '',
    });

    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState({});

    const selectedUserId = selected[0]?.user_id ?? null;

    const totals = useMemo(() => ({
        weight: selected.reduce((sum, item) => sum + item.weight, 0),
        amount: selected.reduce((sum, item) => sum + item.amount, 0),
        weightless: [...new Set(selected.flatMap((item) => item.weightless_items))],
    }), [selected]);

    const placesWeight = places.reduce((sum, place) => sum + (Number(place.weight) || 0), 0);

    // Расчётный вес → в единственное место, пока его не правили руками.
    useEffect(() => {
        if (weightTouched || places.length !== 1) {
            return;
        }

        setPlaces((prev) => (
            prev.length === 1 && Number(prev[0].weight) !== totals.weight
                ? [{ ...prev[0], weight: totals.weight ? String(totals.weight) : '' }]
                : prev
        ));
    }, [totals.weight, weightTouched, places.length]);

    // Клиент определяется первой выбранной реализацией — вместе с ним подтягиваем
    // его адреса и контакты, чтобы кладовщик не перепечатывал их руками.
    useEffect(() => {
        if (!selectedUserId) {
            setRecipientOptions({ contact: null, order_addresses: [], client_addresses: [] });
            return;
        }

        axios.get('/wms/deliveries/recipient-options', { params: { user_id: selectedUserId } })
            .then(({ data }) => {
                setRecipientOptions(data);
                if (data.contact) {
                    setContact((prev) => ({
                        contactName: prev.contactName || data.contact.contactName || '',
                        phone: prev.phone || data.contact.phone || '',
                        email: prev.email || data.contact.email || '',
                        companyName: prev.companyName,
                    }));
                }
            })
            .catch(() => {
                toaster.create({ title: 'Не удалось загрузить адреса клиента', type: 'error' });
            });
    }, [selectedUserId]);

    const resolveAddress = (addressString, data = null) => {
        if (!addressString) {
            return;
        }

        setResolving(true);
        axios.post('/wms/deliveries/resolve-address', { address_string: addressString, data })
            .then(({ data: result }) => {
                setResolvedAddress(result.address);

                if (!result.resolved) {
                    toaster.create({
                        title: 'Адрес разобран не полностью',
                        description: 'Проверьте город и номер дома — без них перевозчик заявку не примет.',
                        type: 'warning',
                    });
                }
            })
            .catch(() => {
                toaster.create({ title: 'Не удалось разобрать адрес', type: 'error' });
            })
            .finally(() => setResolving(false));
    };

    const addressList = addressSource === 'order'
        ? recipientOptions.order_addresses
        : recipientOptions.client_addresses;

    const handleAddressSelect = (id) => {
        setSelectedAddressId(id);
        const found = addressList.find((item) => item.id === id);

        if (found) {
            resolveAddress(found.address_string, found.data);
        }
    };

    const updatePlace = (index, field, value) => {
        setPlaces((prev) => prev.map((place, i) => (i === index ? { ...place, [field]: value } : place)));
    };

    const submit = () => {
        setSubmitting(true);
        setErrors({});

        const payload = {
            shipment_ids: selected.map((item) => item.id),
            delivery_type: deliveryType,
            pickup_type: pickupType,
            pickup_date: pickupDate || null,
            comment: comment || null,
            places: places.map((place) => ({
                weight: Number(place.weight) || 0,
                length: Number(place.length) || null,
                width: Number(place.width) || null,
                height: Number(place.height) || null,
            })),
            recipient: { ...(resolvedAddress || {}), ...contact },
        };

        const options = {
            onError: (formErrors) => setErrors(formErrors),
            onFinish: () => setSubmitting(false),
        };

        if (isEdit) {
            router.put(delivery.urls.update, payload, options);

            return;
        }

        router.post('/wms/deliveries', payload, options);
    };

    // Заблокированная кнопка без объяснения — худший вид формы: кладовщик видит
    // заполненный экран и не понимает, что мешает. Перечисляем недостающее.
    const missing = [
        selected.length === 0 && 'выберите реализации',
        placesWeight <= 0 && 'укажите вес мест',
        !resolvedAddress?.city && 'нужен город получателя',
        !contact.contactName && 'нужно контактное лицо',
        !contact.phone && 'нужен телефон получателя',
    ].filter(Boolean);

    const canSubmit = missing.length === 0 && !submitting;

    return (
        <>
            <Head title={isEdit ? `Правка отправки ${delivery.number} — Склад` : 'Новая отправка — Склад'} />
            <PageHeader
                title={isEdit ? `Правка отправки ${delivery.number}` : 'Новая отправка'}
                description={isEdit
                    ? 'Пока заявка не передана перевозчику, состав, места и получателя можно менять. После сохранения пересчитайте тариф.'
                    : 'Соберите груз из реализаций, задайте места и укажите получателя. Тариф выберете на следующем шаге.'}
            />

            <VStack gap={4} align="stretch">
                {!integrationEnabled && (
                    <Card.Root borderColor="orange.400" borderWidth="1px">
                        <Card.Body py={3}>
                            <Text fontSize="sm">
                                Интеграция с ApiShip выключена — отправку можно собрать, но рассчитать
                                и передать её перевозчику не получится.
                            </Text>
                        </Card.Body>
                    </Card.Root>
                )}

                {/* ─── Шаг 1 ─── */}
                <Card.Root>
                    <Card.Header>
                        <HStack justify="space-between" flexWrap="wrap" gap={2}>
                            <Box>
                                <Text fontWeight="bold">Шаг 1. Реализации</Text>
                                <Text fontSize="sm" color="fg.muted">
                                    Состав груза. Разбор с фильтрами и группировками — в разделе
                                    «Реализации к доставке».
                                </Text>
                            </Box>
                            <Button asChild size="sm" variant="outline">
                                <Link href="/wms/delivery-candidates">
                                    <LuPackageSearch /> Подобрать реализации
                                </Link>
                            </Button>
                        </HStack>
                    </Card.Header>
                    <Card.Body>
                        <VStack gap={3} align="stretch">
                            <ShipmentSelector selected={selected} onChange={setSelected} />

                            {errors.shipment_ids && (
                                <Text fontSize="sm" color="red.500">{errors.shipment_ids}</Text>
                            )}

                            {selected.length > 0 && (
                                <Box borderWidth="1px" borderColor="border" borderRadius="md" p={3} bg="bg.subtle">
                                    <HStack gap={6} flexWrap="wrap" fontSize="sm">
                                        <Text><Text as="span" color="fg.muted">Выбрано: </Text>{selected.length}</Text>
                                        <Text><Text as="span" color="fg.muted">Расчётный вес: </Text>{formatWeight(totals.weight)}</Text>
                                        <Text><Text as="span" color="fg.muted">Сумма: </Text>{formatMoney(totals.amount)}</Text>
                                        {selected[0]?.client && (
                                            <Text color="fg.muted" lineClamp={1}>{selected[0].client}</Text>
                                        )}
                                    </HStack>

                                    {totals.weightless.length > 0 && (
                                        <HStack gap={1} color="orange.500" mt={2} align="start">
                                            <Box pt="2px"><LuTriangleAlert size={14} /></Box>
                                            <Text fontSize="xs">
                                                У {totals.weightless.length} позиций нет веса в карточке — они
                                                посчитаны по умолчанию. Взвесьте груз и укажите фактический вес мест.
                                            </Text>
                                        </HStack>
                                    )}
                                </Box>
                            )}
                        </VStack>
                    </Card.Body>
                </Card.Root>

                {/* ─── Шаг 2 ─── */}
                <Card.Root>
                    <Card.Header>
                        <Text fontWeight="bold">Шаг 2. Груз и способ доставки</Text>
                        <Text fontSize="sm" color="fg.muted">
                            Вес указывается в граммах, габариты — в сантиметрах: так их требует перевозчик.
                        </Text>
                    </Card.Header>
                    <Card.Body>
                        <VStack gap={4} align="stretch">
                            <SimpleGrid columns={{ base: 1, md: 4 }} gap={3}>
                                <Field label="Тип доставки">
                                    <NativeSelectRoot size="sm">
                                        <NativeSelectField
                                            value={deliveryType}
                                            onChange={(event) => setDeliveryType(Number(event.target.value))}
                                        >
                                            <option value={1}>До двери получателя</option>
                                            <option value={2}>До пункта выдачи</option>
                                        </NativeSelectField>
                                    </NativeSelectRoot>
                                </Field>

                                <Field label="Передача груза">
                                    <NativeSelectRoot size="sm">
                                        <NativeSelectField
                                            value={pickupType}
                                            onChange={(event) => setPickupType(Number(event.target.value))}
                                        >
                                            <option value={1}>Забирает курьер</option>
                                            <option value={2}>Везём на терминал сами</option>
                                        </NativeSelectField>
                                    </NativeSelectRoot>
                                </Field>

                                <Field label="Дата передачи" errorText={errors.pickup_date} invalid={!!errors.pickup_date}>
                                    <Input
                                        size="sm"
                                        type="date"
                                        value={pickupDate}
                                        onChange={(event) => setPickupDate(event.target.value)}
                                    />
                                </Field>

                                <Field label="Итого по местам">
                                    <Text fontSize="sm" pt={2} fontWeight="medium">
                                        {formatWeight(placesWeight)}
                                        {placesWeight === 0 && (
                                            <Text as="span" color="fg.muted"> — укажите вес</Text>
                                        )}
                                    </Text>
                                </Field>
                            </SimpleGrid>

                            <VStack gap={2} align="stretch">
                                {places.map((place, index) => (
                                    <HStack key={index} gap={2} align="end" flexWrap="wrap">
                                        <Text fontSize="sm" w="70px" color="fg.muted">Место {index + 1}</Text>

                                        <Field label="Вес, г" width="120px">
                                            <Input
                                                size="sm"
                                                type="number"
                                                min={1}
                                                value={place.weight}
                                                onChange={(event) => {
                                                    setWeightTouched(true);
                                                    updatePlace(index, 'weight', event.target.value);
                                                }}
                                            />
                                        </Field>
                                        <Field label="Длина, см" width="110px">
                                            <Input
                                                size="sm"
                                                type="number"
                                                min={1}
                                                value={place.length}
                                                onChange={(event) => updatePlace(index, 'length', event.target.value)}
                                            />
                                        </Field>
                                        <Field label="Ширина, см" width="110px">
                                            <Input
                                                size="sm"
                                                type="number"
                                                min={1}
                                                value={place.width}
                                                onChange={(event) => updatePlace(index, 'width', event.target.value)}
                                            />
                                        </Field>
                                        <Field label="Высота, см" width="110px">
                                            <Input
                                                size="sm"
                                                type="number"
                                                min={1}
                                                value={place.height}
                                                onChange={(event) => updatePlace(index, 'height', event.target.value)}
                                            />
                                        </Field>

                                        {places.length > 1 && (
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                colorPalette="red"
                                                onClick={() => setPlaces((prev) => prev.filter((_, i) => i !== index))}
                                            >
                                                <LuTrash2 />
                                            </Button>
                                        )}
                                    </HStack>
                                ))}

                                <Box>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setPlaces((prev) => [...prev, makePlace(defaults.place)])}
                                    >
                                        <LuPackagePlus /> Добавить место
                                    </Button>
                                </Box>
                            </VStack>

                            <Field label="Комментарий для перевозчика" errorText={errors.comment} invalid={!!errors.comment}>
                                <Textarea
                                    size="sm"
                                    rows={2}
                                    value={comment}
                                    onChange={(event) => setComment(event.target.value)}
                                    placeholder="Например: хрупкий груз, не кантовать"
                                />
                            </Field>
                        </VStack>
                    </Card.Body>
                </Card.Root>

                {/* ─── Шаг 3 ─── */}
                <Card.Root>
                    <Card.Header>
                        <Text fontWeight="bold">Шаг 3. Получатель</Text>
                        <Text fontSize="sm" color="fg.muted">
                            Адрес разбирается на регион, город, улицу и дом — в таком виде его ждёт перевозчик.
                        </Text>
                    </Card.Header>
                    <Card.Body>
                        <VStack gap={4} align="stretch">
                            <SimpleGrid columns={{ base: 1, md: 3 }} gap={3}>
                                <Field
                                    label="Контактное лицо"
                                    required
                                    errorText={errors['recipient.contactName']}
                                    invalid={!!errors['recipient.contactName']}
                                >
                                    <Input
                                        size="sm"
                                        value={contact.contactName}
                                        onChange={(event) => setContact({ ...contact, contactName: event.target.value })}
                                    />
                                </Field>
                                <Field
                                    label="Телефон"
                                    required
                                    errorText={errors['recipient.phone']}
                                    invalid={!!errors['recipient.phone']}
                                >
                                    <Input
                                        size="sm"
                                        value={contact.phone}
                                        onChange={(event) => setContact({ ...contact, phone: event.target.value })}
                                    />
                                </Field>
                                <Field
                                    label="Email"
                                    errorText={errors['recipient.email']}
                                    invalid={!!errors['recipient.email']}
                                >
                                    <Input
                                        size="sm"
                                        value={contact.email}
                                        onChange={(event) => setContact({ ...contact, email: event.target.value })}
                                    />
                                </Field>
                            </SimpleGrid>

                            <Field label="Откуда взять адрес">
                                <NativeSelectRoot size="sm" maxW="260px">
                                    <NativeSelectField
                                        value={addressSource}
                                        onChange={(event) => {
                                            setAddressSource(event.target.value);
                                            setSelectedAddressId('');
                                        }}
                                    >
                                        {ADDRESS_SOURCES.map((item) => (
                                            <option key={item.value} value={item.value}>{item.label}</option>
                                        ))}
                                    </NativeSelectField>
                                </NativeSelectRoot>
                            </Field>

                            {addressSource === 'manual' ? (
                                <Field label="Адрес доставки" required>
                                    <AddressSuggest
                                        value={manualAddress}
                                        onChange={setManualAddress}
                                        onAddressSelected={(suggestion) => {
                                            setManualAddress(suggestion.value);
                                            resolveAddress(
                                                suggestion.unrestricted_value || suggestion.value,
                                                suggestion.data,
                                            );
                                        }}
                                        placeholder="Начните вводить адрес..."
                                    />
                                </Field>
                            ) : addressList.length === 0 ? (
                                <Text fontSize="sm" color="fg.muted">
                                    {selectedUserId
                                        ? 'У клиента нет сохранённых адресов этого типа — введите адрес вручную.'
                                        : 'Сначала выберите реализации — адреса подтянутся от их клиента.'}
                                </Text>
                            ) : (
                                <Field label="Адрес доставки" required>
                                    <NativeSelectRoot size="sm">
                                        <NativeSelectField
                                            value={selectedAddressId}
                                            onChange={(event) => handleAddressSelect(event.target.value)}
                                        >
                                            <option value="">Выберите адрес</option>
                                            {addressList.map((item) => (
                                                <option key={item.id} value={item.id}>{item.label}</option>
                                            ))}
                                        </NativeSelectField>
                                    </NativeSelectRoot>
                                </Field>
                            )}

                            {resolving && <Text fontSize="sm" color="fg.muted">Разбираем адрес...</Text>}

                            {resolvedAddress && (
                                <Box borderWidth="1px" borderColor="border" borderRadius="md" p={3} bg="bg.subtle">
                                    <Text fontSize="xs" color="fg.muted" mb={2}>
                                        Разобранный адрес — поправьте, если перевозчику чего-то не хватает
                                    </Text>
                                    <SimpleGrid columns={{ base: 1, md: 3 }} gap={3}>
                                        <Field
                                            label="Город"
                                            required
                                            errorText={errors['recipient.city']}
                                            invalid={!!errors['recipient.city']}
                                        >
                                            <Input
                                                size="sm"
                                                value={resolvedAddress.city || ''}
                                                onChange={(event) => setResolvedAddress({ ...resolvedAddress, city: event.target.value })}
                                            />
                                        </Field>
                                        <Field label="Регион">
                                            <Input
                                                size="sm"
                                                value={resolvedAddress.region || ''}
                                                onChange={(event) => setResolvedAddress({ ...resolvedAddress, region: event.target.value })}
                                            />
                                        </Field>
                                        <Field label="Индекс">
                                            <Input
                                                size="sm"
                                                value={resolvedAddress.index || ''}
                                                onChange={(event) => setResolvedAddress({ ...resolvedAddress, index: event.target.value })}
                                            />
                                        </Field>
                                        <Field label="Улица">
                                            <Input
                                                size="sm"
                                                value={resolvedAddress.street || ''}
                                                onChange={(event) => setResolvedAddress({ ...resolvedAddress, street: event.target.value })}
                                            />
                                        </Field>
                                        <Field
                                            label="Дом"
                                            errorText={errors['recipient.house']}
                                            invalid={!!errors['recipient.house']}
                                        >
                                            <Input
                                                size="sm"
                                                value={resolvedAddress.house || ''}
                                                onChange={(event) => setResolvedAddress({ ...resolvedAddress, house: event.target.value })}
                                            />
                                        </Field>
                                        <Field label="Офис / квартира">
                                            <Input
                                                size="sm"
                                                value={resolvedAddress.office || ''}
                                                onChange={(event) => setResolvedAddress({ ...resolvedAddress, office: event.target.value })}
                                            />
                                        </Field>
                                    </SimpleGrid>
                                </Box>
                            )}
                        </VStack>
                    </Card.Body>
                </Card.Root>

                <HStack justify="end" gap={2} flexWrap="wrap">
                    {missing.length > 0 && (
                        <Text fontSize="sm" color="fg.muted" mr="auto">
                            Чтобы создать отправку: {missing.join(', ')}.
                        </Text>
                    )}
                    <Button
                        variant="outline"
                        onClick={() => router.get(isEdit ? `/wms/deliveries/${delivery.id}` : '/wms/deliveries')}
                    >
                        Отмена
                    </Button>
                    <Button onClick={submit} disabled={!canSubmit} loading={submitting}>
                        {isEdit ? 'Сохранить' : 'Создать отправку'}
                    </Button>
                </HStack>
            </VStack>
        </>
    );
}

DeliveriesCreate.layout = (page) => <WmsLayout>{page}</WmsLayout>;
