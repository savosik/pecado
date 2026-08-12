import { useState } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    Badge,
    Box,
    Card,
    HStack,
    Input,
    SimpleGrid,
    Table,
    Text,
    VStack,
} from '@chakra-ui/react';
import {
    LuBan,
    LuCalculator,
    LuExternalLink,
    LuFileText,
    LuPencil,
    LuPrinter,
    LuTrash2,
    LuTruck,
    LuUserCheck,
} from 'react-icons/lu';
import WmsLayout from '@/Wms/Layouts/WmsLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { ConfirmDialog } from '@/Admin/Components/ConfirmDialog';
import { PickupPointPicker } from '@/Wms/Components/PickupPointPicker';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { toaster } from '@/components/ui/toaster';
import { usePermission } from '@/Admin/hooks/usePermission';
import { useFlashToast } from '@/hooks/useFlashToast';
import { formatDays, formatMoney, formatWeight } from '@/Wms/Components/deliveryFormat';

const SOURCE_LABELS = {
    webhook: 'вебхук',
    poll: 'сверка',
    manual: 'вручную',
};

function InfoRow({ label, children }) {
    return (
        <HStack justify="space-between" align="start" gap={4}>
            <Text fontSize="sm" color="fg.muted" flexShrink={0}>{label}</Text>
            <Box fontSize="sm" textAlign="end">{children ?? '—'}</Box>
        </HStack>
    );
}

export default function DeliveriesShow() {
    const { delivery, apiLog, integrationEnabled } = usePage().props;
    const { can } = usePermission();
    useFlashToast();

    const [tariffs, setTariffs] = useState([]);
    const [calculating, setCalculating] = useState(false);
    const [points, setPoints] = useState([]);
    const [loadingPoints, setLoadingPoints] = useState(false);
    const [selectedPointId, setSelectedPointId] = useState(delivery.point_id || '');
    const [confirmCancel, setConfirmCancel] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [courierForm, setCourierForm] = useState({ date: '', time_start: '10:00', time_end: '18:00' });
    const [showCourier, setShowCourier] = useState(false);

    const isEditable = delivery.is_editable;
    const isPointDelivery = delivery.delivery_type === 2;

    const calculate = () => {
        setCalculating(true);
        axios.post(delivery.urls.calculate)
            .then(({ data }) => {
                setTariffs(data.tariffs);

                if (data.tariffs.length === 0) {
                    toaster.create({
                        title: 'Перевозчики не предложили ни одного тарифа',
                        description: 'Проверьте адрес, вес и габариты груза.',
                        type: 'warning',
                    });
                }
            })
            .catch((error) => {
                toaster.create({
                    title: 'Рассчитать доставку не удалось',
                    description: error.response?.data?.error || 'Перевозчик не ответил.',
                    type: 'error',
                });
            })
            .finally(() => setCalculating(false));
    };

    const loadPoints = (providerKey) => {
        setLoadingPoints(true);
        axios.get(delivery.urls.points, { params: { provider_key: providerKey } })
            .then(({ data }) => {
                setPoints(data.points);

                if (data.points.length === 0) {
                    toaster.create({
                        title: 'Пунктов выдачи в этом городе не нашлось',
                        type: 'warning',
                    });
                }
            })
            .catch((error) => {
                toaster.create({
                    title: 'Список пунктов выдачи не загрузился',
                    description: error.response?.data?.error || 'Перевозчик не ответил.',
                    type: 'error',
                });
            })
            .finally(() => setLoadingPoints(false));
    };

    /** Выбор тарифа сохраняем сразу: он и есть решение, по которому поедет груз. */
    const chooseTariff = (tariff) => {
        if (tariff.delivery_type === 2 && !selectedPointId) {
            loadPoints(tariff.provider_key);
            toaster.create({
                title: 'Выберите пункт выдачи',
                description: 'Тариф до ПВЗ без пункта выдачи перевозчик не примет.',
                type: 'info',
            });
            return;
        }

        const point = points.find((item) => item.id === selectedPointId);

        router.put(delivery.urls.update, {
            shipment_ids: delivery.documents.map((item) => item.id),
            delivery_type: tariff.delivery_type,
            pickup_type: delivery.pickup_type,
            pickup_date: delivery.pickup_date,
            comment: delivery.comment,
            places: delivery.places.map((place) => ({
                weight: place.weight,
                length: place.length,
                width: place.width,
                height: place.height,
            })),
            recipient: delivery.recipient,
            provider_key: tariff.provider_key,
            tariff_id: tariff.tariff_id,
            tariff_name: tariff.tariff_name,
            delivery_cost: tariff.delivery_cost,
            delivery_cost_original: tariff.delivery_cost_original,
            point_id: tariff.delivery_type === 2 ? selectedPointId : null,
            point_address: tariff.delivery_type === 2 ? point?.address : null,
        }, { preserveScroll: true });
    };

    const submit = () => router.post(delivery.urls.submit, {}, { preserveScroll: true });

    const callCourier = () => {
        router.post(delivery.urls.courier, courierForm, {
            preserveScroll: true,
            onSuccess: () => setShowCourier(false),
        });
    };

    return (
        <>
            <Head title={`Отправка ${delivery.number} — Склад`} />
            <PageHeader
                title={`Отправка ${delivery.number}`}
                description={delivery.client || 'Получатель не указан'}
                actions={(
                    <HStack gap={2} flexWrap="wrap">
                        <Button asChild size="sm" variant="outline">
                            <Link href="/wms/deliveries">К журналу</Link>
                        </Button>

                        {delivery.apiship_order_id && (
                            <Button asChild size="sm" variant="outline">
                                <a href={delivery.urls.label} target="_blank" rel="noopener noreferrer">
                                    <LuPrinter /> Этикетка
                                </a>
                            </Button>
                        )}

                        {delivery.apiship_order_id && (
                            <Button asChild size="sm" variant="outline">
                                <a href={delivery.urls.waybill} target="_blank" rel="noopener noreferrer">
                                    <LuFileText /> Акт приёма-передачи
                                </a>
                            </Button>
                        )}

                        {delivery.apiship_order_id && can('wms-deliveries.submit') && (
                            <Button size="sm" variant="outline" onClick={() => setShowCourier((prev) => !prev)}>
                                <LuUserCheck /> Вызвать курьера
                            </Button>
                        )}

                        {/* Пока заявки у перевозчика нет, отправка — черновик:
                            её можно и править, и удалить без последствий. */}
                        {isEditable && can('wms-deliveries.edit') && (
                            <Button asChild size="sm" variant="outline">
                                <Link href={`/wms/deliveries/${delivery.id}/edit`}>
                                    <LuPencil /> Изменить
                                </Link>
                            </Button>
                        )}

                        {!delivery.apiship_order_id && can('wms-deliveries.edit') && (
                            <Button size="sm" variant="outline" colorPalette="red" onClick={() => setConfirmDelete(true)}>
                                <LuTrash2 /> Удалить
                            </Button>
                        )}

                        {can('wms-deliveries.cancel') && delivery.status !== 'cancelled' && (
                            <Button size="sm" variant="outline" colorPalette="red" onClick={() => setConfirmCancel(true)}>
                                <LuBan /> Отменить
                            </Button>
                        )}
                    </HStack>
                )}
            />

            <VStack gap={4} align="stretch">
                {delivery.last_error && (
                    <Card.Root borderColor="red.400" borderWidth="1px">
                        <Card.Body py={3}>
                            <Text fontSize="sm" fontWeight="medium" mb={1}>Последняя ошибка перевозчика</Text>
                            <Text fontSize="sm" color="fg.muted">{delivery.last_error}</Text>
                        </Card.Body>
                    </Card.Root>
                )}

                {showCourier && (
                    <Card.Root>
                        <Card.Header><Text fontWeight="bold">Вызов курьера</Text></Card.Header>
                        <Card.Body>
                            <HStack gap={3} align="end" flexWrap="wrap">
                                <Field label="Дата" width="180px">
                                    <Input
                                        size="sm"
                                        type="date"
                                        value={courierForm.date}
                                        onChange={(event) => setCourierForm({ ...courierForm, date: event.target.value })}
                                    />
                                </Field>
                                <Field label="С" width="120px">
                                    <Input
                                        size="sm"
                                        type="time"
                                        value={courierForm.time_start}
                                        onChange={(event) => setCourierForm({ ...courierForm, time_start: event.target.value })}
                                    />
                                </Field>
                                <Field label="До" width="120px">
                                    <Input
                                        size="sm"
                                        type="time"
                                        value={courierForm.time_end}
                                        onChange={(event) => setCourierForm({ ...courierForm, time_end: event.target.value })}
                                    />
                                </Field>
                                <Button size="sm" onClick={callCourier} disabled={!courierForm.date}>
                                    Вызвать
                                </Button>
                            </HStack>
                        </Card.Body>
                    </Card.Root>
                )}

                <SimpleGrid columns={{ base: 1, lg: 2 }} gap={4}>
                    <Card.Root>
                        <Card.Header><Text fontWeight="bold">Отправка</Text></Card.Header>
                        <Card.Body>
                            <VStack gap={2} align="stretch">
                                <InfoRow label="Статус">
                                    <Badge colorPalette={delivery.status_color}>{delivery.status_label}</Badge>
                                </InfoRow>
                                <InfoRow label="Статус у перевозчика">
                                    {delivery.apiship_status_label && (
                                        <VStack gap={0} align="end">
                                            <Badge colorPalette={delivery.apiship_status_color || 'gray'} size="sm">
                                                {delivery.apiship_status_label}
                                            </Badge>
                                            {delivery.apiship_status_at && (
                                                <Text fontSize="xs" color="fg.muted">{delivery.apiship_status_at}</Text>
                                            )}
                                        </VStack>
                                    )}
                                </InfoRow>
                                <InfoRow label="Перевозчик">
                                    {delivery.is_manual && (
                                        <Badge size="sm" variant="outline" colorPalette="gray" mb={1}>
                                            оформлено вручную
                                        </Badge>
                                    )}
                                    {delivery.provider_key && (
                                        <VStack gap={0} align="end">
                                            <Text>{delivery.provider_key}</Text>
                                            {delivery.tariff_name && (
                                                <Text fontSize="xs" color="fg.muted">{delivery.tariff_name}</Text>
                                            )}
                                        </VStack>
                                    )}
                                </InfoRow>
                                <InfoRow label="Трек-номер">
                                    {delivery.provider_number && (
                                        delivery.tracking_url ? (
                                            <a href={delivery.tracking_url} target="_blank" rel="noopener noreferrer">
                                                <HStack gap={1}>
                                                    <Text>{delivery.provider_number}</Text>
                                                    <LuExternalLink size={12} />
                                                </HStack>
                                            </a>
                                        ) : delivery.provider_number
                                    )}
                                </InfoRow>
                                <InfoRow label="Тип доставки">
                                    {isPointDelivery ? 'До пункта выдачи' : 'До двери'}
                                </InfoRow>
                                {isPointDelivery && <InfoRow label="Пункт выдачи">{delivery.point_address}</InfoRow>}
                                <InfoRow label="Стоимость доставки">{formatMoney(delivery.delivery_cost)}</InfoRow>
                                <InfoRow label="Объявленная ценность">{formatMoney(delivery.assessed_cost)}</InfoRow>
                                <InfoRow label="Вес">
                                    <VStack gap={0} align="end">
                                        <Text>{formatWeight(delivery.effective_weight)}</Text>
                                        {delivery.declared_weight === null && (
                                            <Text fontSize="xs" color="orange.500">расчётный, груз не взвешен</Text>
                                        )}
                                    </VStack>
                                </InfoRow>
                                <InfoRow label="Создал">
                                    {delivery.created_by && `${delivery.created_by}, ${delivery.created_label}`}
                                </InfoRow>
                                <InfoRow label="Передал в ТК">
                                    {delivery.submitted_by && `${delivery.submitted_by}, ${delivery.submitted_label}`}
                                </InfoRow>
                            </VStack>
                        </Card.Body>
                    </Card.Root>

                    <Card.Root>
                        <Card.Header><Text fontWeight="bold">Получатель</Text></Card.Header>
                        <Card.Body>
                            <VStack gap={2} align="stretch">
                                <InfoRow label="Клиент">{delivery.client}</InfoRow>
                                <InfoRow label="Контактное лицо">{delivery.recipient?.contactName}</InfoRow>
                                <InfoRow label="Телефон">{delivery.recipient?.phone}</InfoRow>
                                <InfoRow label="Email">{delivery.recipient?.email}</InfoRow>
                                <InfoRow label="Адрес">
                                    <Text maxW="320px">
                                        {delivery.recipient?.addressString
                                            || [delivery.recipient?.city, delivery.recipient?.street, delivery.recipient?.house]
                                                .filter(Boolean).join(', ')}
                                    </Text>
                                </InfoRow>
                                <InfoRow label="Склад отправления">{delivery.warehouse}</InfoRow>
                                <InfoRow label="Комментарий">{delivery.comment}</InfoRow>
                            </VStack>
                        </Card.Body>
                    </Card.Root>
                </SimpleGrid>

                {/* Расчёт тарифов доступен, пока груз не уехал. */}
                {isEditable && can('wms-deliveries.edit') && (
                    <Card.Root>
                        <Card.Header>
                            <HStack justify="space-between" flexWrap="wrap" gap={2}>
                                <Box>
                                    <Text fontWeight="bold">Расчёт доставки</Text>
                                    <Text fontSize="sm" color="fg.muted">
                                        Тарифы всех подключённых перевозчиков по весу {formatWeight(delivery.effective_weight)}.
                                    </Text>
                                </Box>
                                <HStack gap={2}>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={calculate}
                                        loading={calculating}
                                        disabled={!integrationEnabled}
                                    >
                                        <LuCalculator /> Рассчитать
                                    </Button>
                                    {can('wms-deliveries.submit') && (
                                        <Button
                                            size="sm"
                                            onClick={submit}
                                            disabled={!integrationEnabled || !delivery.tariff_id}
                                        >
                                            <LuTruck /> Передать заявку в ТК
                                        </Button>
                                    )}
                                </HStack>
                            </HStack>
                        </Card.Header>
                        <Card.Body>
                            <VStack gap={3} align="stretch">
                                {isPointDelivery && (
                                    <HStack gap={2} align="end" flexWrap="wrap">
                                        <Field label="Пункт выдачи" width={{ base: '100%', md: '480px' }}>
                                            <PickupPointPicker
                                                points={points}
                                                value={selectedPointId}
                                                onChange={setSelectedPointId}
                                                loading={loadingPoints}
                                            />
                                        </Field>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            loading={loadingPoints}
                                            disabled={!delivery.provider_key && tariffs.length === 0}
                                            onClick={() => loadPoints(delivery.provider_key || tariffs[0]?.provider_key)}
                                        >
                                            Загрузить пункты
                                        </Button>
                                    </HStack>
                                )}

                                {tariffs.length === 0 ? (
                                    <Text fontSize="sm" color="fg.muted">
                                        {delivery.tariff_id
                                            ? 'Тариф уже выбран. Нажмите «Рассчитать», чтобы посмотреть альтернативы.'
                                            : 'Нажмите «Рассчитать», чтобы получить тарифы перевозчиков.'}
                                    </Text>
                                ) : (
                                    <Box overflowX="auto">
                                        <Table.Root size="sm" interactive>
                                            <Table.Header>
                                                <Table.Row>
                                                    <Table.ColumnHeader>Перевозчик</Table.ColumnHeader>
                                                    <Table.ColumnHeader>Тариф</Table.ColumnHeader>
                                                    <Table.ColumnHeader>Куда</Table.ColumnHeader>
                                                    <Table.ColumnHeader textAlign="end">Срок</Table.ColumnHeader>
                                                    <Table.ColumnHeader textAlign="end">Стоимость</Table.ColumnHeader>
                                                    <Table.ColumnHeader />
                                                </Table.Row>
                                            </Table.Header>
                                            <Table.Body>
                                                {tariffs.map((tariff) => {
                                                    const isCurrent = delivery.tariff_id === tariff.tariff_id
                                                        && delivery.provider_key === tariff.provider_key;

                                                    return (
                                                        <Table.Row key={`${tariff.provider_key}-${tariff.tariff_id}`}>
                                                            <Table.Cell fontSize="sm" fontWeight="medium">
                                                                {tariff.provider_key}
                                                            </Table.Cell>
                                                            <Table.Cell fontSize="sm" maxW="280px">
                                                                <Text lineClamp={2}>{tariff.tariff_name}</Text>
                                                            </Table.Cell>
                                                            <Table.Cell fontSize="sm">
                                                                {tariff.delivery_type === 2 ? 'До ПВЗ' : 'До двери'}
                                                            </Table.Cell>
                                                            <Table.Cell textAlign="end" fontSize="sm">
                                                                {formatDays(tariff.days_min, tariff.days_max)}
                                                            </Table.Cell>
                                                            <Table.Cell textAlign="end" fontSize="sm" fontVariantNumeric="tabular-nums">
                                                                {formatMoney(tariff.delivery_cost)}
                                                            </Table.Cell>
                                                            <Table.Cell textAlign="end">
                                                                {isCurrent ? (
                                                                    <Badge colorPalette="green" size="sm">Выбран</Badge>
                                                                ) : (
                                                                    <Button size="xs" variant="outline" onClick={() => chooseTariff(tariff)}>
                                                                        Выбрать
                                                                    </Button>
                                                                )}
                                                            </Table.Cell>
                                                        </Table.Row>
                                                    );
                                                })}
                                            </Table.Body>
                                        </Table.Root>
                                    </Box>
                                )}
                            </VStack>
                        </Card.Body>
                    </Card.Root>
                )}

                <SimpleGrid columns={{ base: 1, lg: 2 }} gap={4}>
                    <Card.Root>
                        <Card.Header><Text fontWeight="bold">Места ({delivery.places.length})</Text></Card.Header>
                        <Card.Body>
                            <Table.Root size="sm">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>N</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="end">Вес</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="end">Габариты, см</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="end">Объёмный вес</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {delivery.places.map((place) => (
                                        <Table.Row key={place.number}>
                                            <Table.Cell fontSize="sm">{place.number}</Table.Cell>
                                            <Table.Cell textAlign="end" fontSize="sm">{formatWeight(place.weight)}</Table.Cell>
                                            <Table.Cell textAlign="end" fontSize="sm">
                                                {place.length && place.width && place.height
                                                    ? `${place.length}×${place.width}×${place.height}`
                                                    : '—'}
                                            </Table.Cell>
                                            <Table.Cell textAlign="end" fontSize="sm" color="fg.muted">
                                                {formatWeight(place.volumetric_weight)}
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        </Card.Body>
                    </Card.Root>

                    <Card.Root>
                        <Card.Header><Text fontWeight="bold">Реализации ({delivery.documents.length})</Text></Card.Header>
                        <Card.Body>
                            <Table.Root size="sm">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>Номер</Table.ColumnHeader>
                                        <Table.ColumnHeader>Дата</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="end">Позиций</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="end">Вес</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="end">Сумма</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {delivery.documents.map((doc) => (
                                        <Table.Row key={doc.id}>
                                            <Table.Cell fontSize="sm" fontWeight="medium">{doc.number}</Table.Cell>
                                            <Table.Cell fontSize="sm">{doc.date_label || '—'}</Table.Cell>
                                            <Table.Cell textAlign="end" fontSize="sm">{doc.items_count}</Table.Cell>
                                            <Table.Cell textAlign="end" fontSize="sm">{formatWeight(doc.weight)}</Table.Cell>
                                            <Table.Cell textAlign="end" fontSize="sm">{formatMoney(doc.amount)}</Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        </Card.Body>
                    </Card.Root>
                </SimpleGrid>

                <Card.Root>
                    <Card.Header><Text fontWeight="bold">История статусов</Text></Card.Header>
                    <Card.Body>
                        {delivery.history.length === 0 ? (
                            <Text fontSize="sm" color="fg.muted">
                                Статусов пока нет — они появятся после передачи заявки перевозчику.
                            </Text>
                        ) : (
                            <VStack gap={2} align="stretch">
                                {delivery.history.map((row) => (
                                    <HStack key={row.id} gap={3} align="start">
                                        <Text fontSize="xs" color="fg.muted" w="120px" flexShrink={0}>
                                            {row.occurred_label}
                                        </Text>
                                        <Box>
                                            <Text fontSize="sm">{row.label}</Text>
                                            <Text fontSize="xs" color="fg.muted">
                                                {SOURCE_LABELS[row.source] || row.source}
                                                {row.provider_code && ` · код ТК: ${row.provider_code}`}
                                            </Text>
                                        </Box>
                                    </HStack>
                                ))}
                            </VStack>
                        )}
                    </Card.Body>
                </Card.Root>

                {/* Журнал вызовов виден только начальнику склада — это инструмент разбора полётов. */}
                {apiLog !== null && apiLog.length > 0 && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="bold">Журнал обращений к ApiShip</Text>
                            <Text fontSize="sm" color="fg.muted">
                                Последние 50 вызовов по этой отправке.
                            </Text>
                        </Card.Header>
                        <Card.Body>
                            <Box overflowX="auto">
                                <Table.Root size="sm">
                                    <Table.Header>
                                        <Table.Row>
                                            <Table.ColumnHeader>Время</Table.ColumnHeader>
                                            <Table.ColumnHeader>Операция</Table.ColumnHeader>
                                            <Table.ColumnHeader>Запрос</Table.ColumnHeader>
                                            <Table.ColumnHeader textAlign="end">HTTP</Table.ColumnHeader>
                                            <Table.ColumnHeader textAlign="end">мс</Table.ColumnHeader>
                                            <Table.ColumnHeader>Ошибка</Table.ColumnHeader>
                                        </Table.Row>
                                    </Table.Header>
                                    <Table.Body>
                                        {apiLog.map((row) => (
                                            <Table.Row key={row.id}>
                                                <Table.Cell fontSize="xs" whiteSpace="nowrap">{row.created_label}</Table.Cell>
                                                <Table.Cell fontSize="xs">{row.operation}</Table.Cell>
                                                <Table.Cell fontSize="xs" maxW="260px">
                                                    <Text lineClamp={1}>{row.endpoint}</Text>
                                                </Table.Cell>
                                                <Table.Cell textAlign="end">
                                                    <Badge size="xs" colorPalette={row.is_successful ? 'green' : 'red'}>
                                                        {row.http_status || '—'}
                                                    </Badge>
                                                </Table.Cell>
                                                <Table.Cell textAlign="end" fontSize="xs">{row.duration_ms ?? '—'}</Table.Cell>
                                                <Table.Cell fontSize="xs" maxW="320px" color="red.500">
                                                    <Text lineClamp={2}>{row.error || ''}</Text>
                                                </Table.Cell>
                                            </Table.Row>
                                        ))}
                                    </Table.Body>
                                </Table.Root>
                            </Box>
                        </Card.Body>
                    </Card.Root>
                )}
            </VStack>

            <ConfirmDialog
                open={confirmCancel}
                onClose={() => setConfirmCancel(false)}
                onConfirm={() => router.post(delivery.urls.cancel, {}, { preserveScroll: true })}
                title="Отменить заявку?"
                description="Заявка будет отменена и у перевозчика. Напечатанные этикетки станут недействительны, а груз придётся оформлять заново."
                confirmLabel="Отменить заявку"
                cancelLabel="Не отменять"
            />

            <ConfirmDialog
                open={confirmDelete}
                onClose={() => setConfirmDelete(false)}
                onConfirm={() => router.delete(delivery.urls.destroy)}
                title={`Удалить отправку ${delivery.number}?`}
                description="Заявку перевозчику не передавали, поэтому удаление ни на что не влияет. Реализации вернутся в список к доставке."
                confirmLabel="Удалить"
                cancelLabel="Оставить"
            />
        </>
    );
}

DeliveriesShow.layout = (page) => <WmsLayout>{page}</WmsLayout>;
