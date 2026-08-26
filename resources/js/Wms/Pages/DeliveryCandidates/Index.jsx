import { useMemo, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import { Box, Card, HStack, Text, VStack } from '@chakra-ui/react';
import { LuCheck, LuPackageSearch, LuTruck } from 'react-icons/lu';
import WmsLayout from '@/Wms/Layouts/WmsLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Button } from '@/components/ui/button';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { toaster } from '@/components/ui/toaster';
import { usePermission } from '@/shared/Panel/usePermission';
import { useFlashToast } from '@/hooks/useFlashToast';
import { ShipmentFilters } from '@/Wms/Components/ShipmentFilters';
import { ShipmentPicker } from '@/Wms/Components/ShipmentPicker';
import { MarkShippedDialog } from '@/Wms/Components/MarkShippedDialog';
import { formatMoney, formatWeight } from '@/Wms/Components/deliveryFormat';

/**
 * Раздел «Реализации к доставке» — рабочий стол склада перед созданием отправки.
 *
 * Здесь весь разбор: фильтры, группировки, поиск и скрытие. Выбранное уходит
 * в мастер кнопкой «Отправить реализации» — сам мастер остаётся компактным.
 */
export default function DeliveryCandidatesIndex() {
    const { clients, pickupClients, filters, options, meta } = usePage().props;
    const { can } = usePermission();
    useFlashToast();

    const [selectedIds, setSelectedIds] = useState([]);
    const [tab, setTab] = useState('delivery');
    const [markShippedOpen, setMarkShippedOpen] = useState(false);

    const allShipments = useMemo(
        () => [...clients, ...pickupClients].flatMap((group) => group.shipments),
        [clients, pickupClients],
    );

    const selected = useMemo(
        () => allShipments.filter((item) => selectedIds.includes(item.id)),
        [allShipments, selectedIds],
    );

    const selectedUserId = selected[0]?.user_id ?? null;
    const visibleClients = tab === 'pickup' ? pickupClients : clients;

    const counts = useMemo(() => ({
        delivery: clients.reduce((sum, group) => sum + group.shipments_count, 0),
        pickup: pickupClients.reduce((sum, group) => sum + group.shipments_count, 0),
    }), [clients, pickupClients]);

    const totals = useMemo(() => ({
        weight: selected.reduce((sum, item) => sum + item.weight, 0),
        amount: selected.reduce((sum, item) => sum + item.amount, 0),
    }), [selected]);

    const applyFilters = (next) => {
        router.get('/wms/delivery-candidates', cleanParams(next), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const toggleShipment = (shipment) => {
        setSelectedIds((prev) => {
            if (prev.includes(shipment.id)) {
                return prev.filter((id) => id !== shipment.id);
            }

            // Разные клиенты в одной отправке — это разные адреса получателя,
            // одной заявкой такое не отправить.
            if (selectedUserId && shipment.user_id !== selectedUserId) {
                toaster.create({
                    title: 'Реализации разных клиентов',
                    description: 'В одну отправку можно включить только реализации одного клиента.',
                    type: 'warning',
                });
                return prev;
            }

            return [...prev, shipment.id];
        });
    };

    const toggleGroup = (group, select) => {
        const groupIds = group.shipments.map((item) => item.id);

        setSelectedIds((prev) => (select
            ? [...new Set([...prev, ...groupIds])]
            : prev.filter((id) => !groupIds.includes(id))));
    };

    const toggleHidden = (shipment) => {
        router.post('/wms/delivery-candidates/hide', {
            shipment_id: shipment.id,
            hidden: !shipment.hidden,
        }, {
            preserveScroll: true,
            // Скрытая реализация уходит из списка — держать её выбранной нельзя:
            // её вес продолжал бы считаться в сводке.
            onSuccess: () => setSelectedIds((prev) => prev.filter((id) => id !== shipment.id)),
        });
    };

    const sendToWizard = () => {
        router.get('/wms/deliveries/create', { shipment_ids: selectedIds });
    };

    return (
        <>
            <Head title="Реализации к доставке — Склад" />
            <PageHeader
                title="Реализации к доставке"
                description="Что нужно везти: статусы заказов, состояние сборки, адреса. Отберите реализации и передайте их в отправку."
            />

            <VStack gap={4} align="stretch">
                <Card.Root>
                    <Card.Body>
                        <ShipmentFilters
                            filters={filters}
                            options={options}
                            meta={meta}
                            onChange={applyFilters}
                            onReset={() => applyFilters({
                                search: filters.search,
                                group_by: filters.group_by,
                                row_sort: filters.row_sort,
                            })}
                        />
                    </Card.Body>
                </Card.Root>

                <SegmentedControl
                    size="sm"
                    value={tab}
                    onValueChange={({ value }) => setTab(value)}
                    items={[
                        { value: 'delivery', label: `К отправке (${counts.delivery})` },
                        { value: 'pickup', label: `Самовывоз (${counts.pickup})` },
                    ]}
                />

                {tab === 'pickup' && (
                    <Text fontSize="xs" color="fg.muted">
                        По этим заказам клиент забирает товар сам — перевозчик им не нужен.
                        Выбирайте только если способ доставки в заказе указан ошибочно.
                    </Text>
                )}

                <Card.Root>
                    <Card.Body>
                        {visibleClients.length === 0 ? (
                            <VStack py={10} gap={2} color="fg.muted">
                                <LuPackageSearch size={32} />
                                <Text fontSize="sm">Реализаций не найдено</Text>
                                <Text fontSize="xs">
                                    {filters.show_hidden
                                        ? 'Скрытых реализаций нет.'
                                        : 'Либо ни одна не подходит под фильтры, либо всё уже в отправках.'}
                                </Text>
                            </VStack>
                        ) : (
                            <ShipmentPicker
                                clients={visibleClients}
                                selectedIds={selectedIds}
                                selectedUserId={selectedUserId}
                                onToggle={toggleShipment}
                                onSelectGroup={toggleGroup}
                                onToggleHidden={toggleHidden}
                            />
                        )}
                    </Card.Body>
                </Card.Root>
            </VStack>

            {/* Панель действия липнет к низу: список длинный, и возвращаться
                наверх за кнопкой после каждого выбора — лишняя работа. */}
            {selected.length > 0 && (
                <Box
                    position="sticky"
                    bottom={0}
                    mt={4}
                    py={3}
                    px={4}
                    bg="bg.panel"
                    borderTopWidth="1px"
                    borderColor="border"
                    boxShadow="md"
                    borderRadius="md"
                >
                    <HStack justify="space-between" flexWrap="wrap" gap={3}>
                        <HStack gap={5} flexWrap="wrap" fontSize="sm">
                            <Text fontWeight="bold">Выбрано: {selected.length}</Text>
                            <Text><Text as="span" color="fg.muted">Вес: </Text>{formatWeight(totals.weight)}</Text>
                            <Text><Text as="span" color="fg.muted">Сумма: </Text>{formatMoney(totals.amount)}</Text>
                            {selected[0]?.client && (
                                <Text color="fg.muted" lineClamp={1}>{selected[0].client}</Text>
                            )}
                        </HStack>

                        <HStack gap={2}>
                            <Button size="sm" variant="ghost" onClick={() => setSelectedIds([])}>
                                Сбросить
                            </Button>
                            {can('wms-deliveries.create') && (
                                <>
                                    {/* Груз мог уехать мимо ApiShip — тогда это отметка
                                        о свершившемся факте, а не новая заявка. */}
                                    <Button size="sm" variant="outline" onClick={() => setMarkShippedOpen(true)}>
                                        <LuCheck /> Уже отправлено
                                    </Button>
                                    <Button size="sm" onClick={sendToWizard}>
                                        <LuTruck /> Отправить реализации
                                    </Button>
                                </>
                            )}
                        </HStack>
                    </HStack>
                </Box>
            )}
            <MarkShippedDialog
                open={markShippedOpen}
                selected={selected}
                statuses={options.manualStatuses || []}
                onClose={(saved) => {
                    setMarkShippedOpen(false);
                    if (saved) {
                        setSelectedIds([]);
                    }
                }}
            />
        </>
    );
}

/** Пустые значения в URL не тащим — иначе адресная строка забивается мусором. */
function cleanParams(filters) {
    return Object.fromEntries(
        Object.entries(filters)
            .filter(([, value]) => (Array.isArray(value) ? value.length > 0 : value !== '' && value !== false && value !== null)),
    );
}

DeliveryCandidatesIndex.layout = (page) => <WmsLayout>{page}</WmsLayout>;
