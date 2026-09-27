import { useState, useCallback, useMemo, useEffect } from 'react';
import {
    Box, Flex, Text, Button, Card, HStack, VStack, SimpleGrid, Badge,
} from '@chakra-ui/react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { LuClock3, LuEye, LuSend, LuPackage, LuBan, LuHourglass, LuTriangleAlert, LuLayers, LuPackageCheck, LuArrowRight } from 'react-icons/lu';
import CabinetLayout from '../CabinetLayout';
import { orderTitle, orderNumberHint } from '../components/OrderNumber';
import ReserveCountdown from '@/components/cabinet/ReserveCountdown';
import { Checkbox } from '@/components/ui/checkbox';
import { ConfirmDialog } from '@/shared/Panel/ConfirmDialog';
import { toastSuccess, toastError } from '@/utils/toast';

/**
 * Раздел «Заказы в резерве» (v16.9.0, режим «Заказы в резерве», res-07).
 *
 * Рабочее место интернетчика: удержанные заказы с живыми таймерами.
 * Подтверждение — отсюда или со страницы заказа; отмена и правка — на странице
 * заказа. Основной сценарий мобильный: клиент звонит своему покупателю и сразу
 * с телефона решает судьбу резерва.
 */
export default function ReservesIndex({ reserves, ship_together_enabled: shipTogetherEnabled = false, recent_shipments: recentShipments = [], pickup_enabled: pickupEnabled = false }) {
    // pick-04: обещание «когда соберём» по графику склада — показываем до подтверждения
    const promise = usePage().props.config?.pickup_promise;
    const [confirmTarget, setConfirmTarget] = useState(null);
    const [confirming, setConfirming] = useState(false);

    // ─── Совместная отгрузка (v16.11.0, топик №7): галочки + «В отгрузку выбранное» ───
    // Заказы, ждущие итог склада, отметить нельзя — они уже ушли группой.
    const [selected, setSelected] = useState([]);
    const [groupConfirmOpen, setGroupConfirmOpen] = useState(false);
    const [groupSending, setGroupSending] = useState(false);
    const selectable = useMemo(
        () => reserves.filter((o) => o.ship_together?.status !== 'pending'),
        [reserves],
    );
    const toggleSelected = useCallback((id) => {
        setSelected((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
    }, []);
    const selectedOrders = reserves.filter((o) => selected.includes(o.id));
    const pendingCount = reserves.filter((o) => o.ship_together?.status === 'pending').length;

    // Пока склад подтверждает группу, страница сама подтягивает итог: клиенту не нужно жать F5,
    // чтобы увидеть, что заказы ушли в сборку (обычно это минута).
    useEffect(() => {
        if (pendingCount === 0) return undefined;
        const timer = setInterval(() => router.reload({ only: ['reserves', 'recent_shipments'] }), 10000);
        return () => clearInterval(timer);
    }, [pendingCount]);

    const doShipTogether = useCallback(async () => {
        if (selected.length < 2) return;
        setGroupSending(true);
        try {
            const { data } = await axios.post('/cabinet/reserves/ship-together', { order_ids: selected });
            toastSuccess('Заказы отправлены вместе', data?.message || 'Ждём подтверждения склада.');
            setSelected([]);
        } catch (err) {
            toastError('Отправить вместе не удалось', err?.response?.data?.message || 'Попробуйте ещё раз.');
        } finally {
            setGroupSending(false);
            setGroupConfirmOpen(false);
            router.reload();
        }
    }, [selected]);

    const doConfirm = useCallback(async () => {
        if (!confirmTarget) return;
        setConfirming(true);
        try {
            const { data } = await axios.post(`/cabinet/orders/${confirmTarget.id}/confirm-reserve`);
            toastSuccess('Заказ в отгрузке', data?.message || 'Заказ отправлен в отгрузку.');
        } catch (err) {
            toastError('Не получилось', err?.response?.data?.message || 'Попробуйте ещё раз.');
        } finally {
            setConfirming(false);
            setConfirmTarget(null);
            router.reload();
        }
    }, [confirmTarget]);

    // Отмена прямо из списка — не заставляем открывать заказ ради одного действия
    const [cancelTarget, setCancelTarget] = useState(null);
    const [cancelling, setCancelling] = useState(false);

    const doCancel = useCallback(async () => {
        if (!cancelTarget) return;
        setCancelling(true);
        try {
            const { data } = await axios.post(`/cabinet/orders/${cancelTarget.id}/cancel`);
            toastSuccess('Заказ отменён', data?.message || 'Товар возвращён в свободный остаток.');
        } catch (err) {
            toastError('Отменить не удалось', err?.response?.data?.message || 'Попробуйте ещё раз.');
        } finally {
            setCancelling(false);
            setCancelTarget(null);
            router.reload();
        }
    }, [cancelTarget]);

    return (
        <CabinetLayout title="Заказы в резерве">
            <Head title="Заказы в резерве — Pecado" />

            <ConfirmDialog
                open={!!confirmTarget}
                onClose={() => setConfirmTarget(null)}
                onConfirm={doConfirm}
                title="Отправить в отгрузку?"
                description={confirmTarget
                    ? `${orderTitle(confirmTarget)} уйдёт в сборку и отгрузку — изменить или отменить его после подтверждения будет нельзя.${promise ? ` ${promise.text}.` : ''}${confirmTarget.delivery_method === 'pickup' ? ' Когда соберём — напишем; курьера отправляйте с пропуском из кабинета.' : ''}`
                    : ''}
                confirmLabel="В отгрузку"
                cancelLabel="Ещё подумаю"
                colorPalette="green"
                isLoading={confirming}
            />

            <ConfirmDialog
                open={groupConfirmOpen}
                onClose={() => setGroupConfirmOpen(false)}
                onConfirm={doShipTogether}
                title="Отправить выбранные заказы вместе?"
                description={`Заказы ${selectedOrders.map((o) => o.number || orderTitle(o).replace(/^Заказ /, '')).join(', ')} уйдут на склад вместе: их соберут в одно место и выпишут одну накладную. Склад подтвердит за минуту — до этого заказы остаются здесь, в резерве.${promise ? ` ${promise.text}.` : ''}${selectedOrders.some((o) => o.delivery_method === 'pickup') ? ' Когда соберём — напишем; курьера отправляйте с пропуском из кабинета.' : ''}`}
                confirmLabel={`В отгрузку вместе (${selected.length})`}
                cancelLabel="Ещё подумаю"
                colorPalette="green"
                isLoading={groupSending}
            />

            <ConfirmDialog
                open={!!cancelTarget}
                onClose={() => setCancelTarget(null)}
                onConfirm={doCancel}
                title="Отменить заказ?"
                description={cancelTarget
                    ? `${orderTitle(cancelTarget)} будет отменён, товар вернётся в свободный остаток. Действие необратимо.`
                    : ''}
                confirmLabel="Отменить заказ"
                cancelLabel="Не отменять"
                isLoading={cancelling}
            />

            {reserves.length === 0 ? (
                <Card.Root>
                    <Card.Body>
                        <VStack py="8" gap="2" color="fg.muted">
                            <LuClock3 size={32} />
                            <Text fontWeight="600">В резерве ничего нет</Text>
                            <Text fontSize="sm" textAlign="center">
                                {recentShipments.length > 0
                                    ? 'Отправленные в отгрузку заказы — ниже: они уже на складе, следить за сборкой можно в разделе «Заказы».'
                                    : 'Оформите заказ с пометкой «Поставьте в резерв» — он появится здесь, и у вас будет время подтвердить его или изменить.'}
                            </Text>
                        </VStack>
                    </Card.Body>
                </Card.Root>
            ) : (
                <>
                {/* Баннер «Соберём одним заказом» (просьба заказчика 22.09.2026): объясняет, зачем галочки,
                    и держит кнопку группы. Показывается, когда объединять есть что. */}
                {shipTogetherEnabled && selectable.length >= 2 && (
                    <Card.Root mb="4" bg="green.50" borderColor="green.200" borderWidth="1px" _dark={{ bg: 'green.900/20', borderColor: 'green.700' }}>
                        <Card.Body py="4">
                            <Flex gap="4" align={{ base: 'stretch', md: 'center' }} justify="space-between" direction={{ base: 'column', md: 'row' }}>
                                <HStack gap="3" align="flex-start">
                                    <Box color="green.600" mt="0.5" flexShrink="0"><LuLayers size={26} /></Box>
                                    <VStack align="flex-start" gap="0.5">
                                        <Text fontWeight="700" fontSize="md">Соберём одним заказом</Text>
                                        <Text fontSize="sm" color="fg.muted">
                                            Выделите несколько резервов галочками и нажмите «В отгрузку вместе»:
                                            объединённые заказы соберут в одно место и выпишут одну накладную.
                                        </Text>
                                    </VStack>
                                </HStack>
                                <HStack gap="2" flexShrink="0" justify={{ base: 'flex-end', md: 'flex-start' }}>
                                    {selected.length > 0 && (
                                        <Button variant="ghost" size="sm" onClick={() => setSelected([])}>
                                            Снять выбор
                                        </Button>
                                    )}
                                    <Button
                                        colorPalette="green"
                                        size="md"
                                        disabled={selected.length < 2}
                                        onClick={() => setGroupConfirmOpen(true)}
                                    >
                                        <LuSend size={16} />
                                        {selected.length > 0 ? `В отгрузку вместе (${selected.length})` : 'Выберите заказы'}
                                    </Button>
                                </HStack>
                            </Flex>
                        </Card.Body>
                    </Card.Root>
                )}
                {/* На телефоне кнопка группы уезжает вверх вместе с баннером — дублируем её липкой полоской,
                    когда что-то выбрано, чтобы не прокручивать обратно. */}
                {shipTogetherEnabled && selected.length > 0 && (
                    <Flex mb="3" gap="3" align="center" justify="space-between" position="sticky" top="0" zIndex="1" bg="bg" py="2" display={{ base: 'flex', md: 'none' }}>
                        <Text fontSize="sm" color="fg.muted">Выбрано: {selected.length}</Text>
                        <Button colorPalette="green" size="sm" disabled={selected.length < 2} onClick={() => setGroupConfirmOpen(true)}>
                            <LuSend size={16} />
                            В отгрузку вместе ({selected.length})
                        </Button>
                    </Flex>
                )}
                <SimpleGrid columns={{ base: 1, md: 2 }} gap="4">
                    {reserves.map((order) => {
                        const pending = order.ship_together?.status === 'pending';
                        const conflict = order.ship_together?.status === 'conflict' ? order.ship_together.conflict : null;
                        const checked = selected.includes(order.id);
                        return (
                        <Card.Root
                            key={order.id}
                            borderColor={checked ? 'green.400' : pending ? 'blue.200' : undefined}
                            borderWidth={checked ? '2px' : undefined}
                            bg={pending ? 'blue.50' : undefined}
                            _dark={pending ? { bg: 'blue.900/20', borderColor: 'blue.700' } : undefined}
                        >
                            <Card.Body>
                                <Flex justify="space-between" align="flex-start" gap="3" wrap="wrap">
                                    <VStack align="flex-start" gap="1">
                                        <HStack gap="2">
                                            {shipTogetherEnabled && !pending && (
                                                <Checkbox
                                                    checked={checked}
                                                    onCheckedChange={() => toggleSelected(order.id)}
                                                    colorPalette="green"
                                                    aria-label={`Отметить ${orderTitle(order)} для совместной отгрузки`}
                                                />
                                            )}
                                            <Text fontWeight="700">{orderTitle(order)}</Text>
                                            {pending ? (
                                                <Badge colorPalette="blue">
                                                    <LuSend size={12} /> отправлен на склад
                                                </Badge>
                                            ) : (
                                                <Badge colorPalette="purple">резерв</Badge>
                                            )}
                                        </HStack>
                                        {!order.number && (
                                            <Text fontSize="xs" color="fg.muted">{orderNumberHint(order)}</Text>
                                        )}
                                        <HStack gap="1" color="fg.muted" fontSize="sm">
                                            <LuPackage size={14} />
                                            <Text>
                                                {order.items_count} поз. · {Number(order.total_amount).toLocaleString('ru-RU', { minimumFractionDigits: 2 })} {order.currency_code === 'RUB' ? '₽' : order.currency_code}
                                            </Text>
                                        </HStack>
                                        <Text fontSize="xs" color="fg.muted">от {order.created_at_formatted}</Text>
                                    </VStack>
                                    {pending ? (
                                        <VStack align="flex-end" gap="0" color="blue.fg">
                                            <HStack gap="1"><LuHourglass size={16} /><Text fontWeight="700">подтверждаем</Text></HStack>
                                            <Text fontSize="xs" color="fg.muted">обычно это минута</Text>
                                        </VStack>
                                    ) : (
                                        <VStack align="flex-end" gap="0">
                                            <HStack gap="1">
                                                <LuClock3 size={16} />
                                                <ReserveCountdown until={order.reserved_until} fontSize="lg" fontWeight="700" />
                                            </HStack>
                                            <Text fontSize="xs" color="fg.muted">до {order.reserved_until_formatted}</Text>
                                        </VStack>
                                    )}
                                </Flex>

                                {/* Итог группы (v16.11.0): ожидание — действия закрыты, отказ — причина и совет */}
                                {pending && (
                                    <Text mt="3" fontSize="sm" color="fg.muted">
                                        Всё в порядке: заказ уже на складе вместе с остальными из группы, ждём короткое подтверждение.
                                        Как только оно придёт, заказ перейдёт в раздел «Заказы» и начнёт собираться одной накладной.
                                        Делать ничего не нужно.
                                    </Text>
                                )}
                                {conflict && (
                                    <HStack mt="3" gap="2" fontSize="sm" color="orange.fg" align="flex-start">
                                        <Box mt="0.5"><LuTriangleAlert size={14} /></Box>
                                        <Text>
                                            Склад не смог собрать эти заказы вместе: {conflict.label.toLowerCase()}.
                                            {conflict.message ? ` ${conflict.message}` : ''} Ничего не потеряно — заказ по-прежнему
                                            в резерве, отправьте его снова вместе или по одному.
                                        </Text>
                                    </HStack>
                                )}

                                {/* Полноценные кнопки, не тесные иконки: тапать с телефона.
                                    Все действия резерва доступны прямо из списка. */}
                                <Flex mt="4" gap="2" direction={{ base: 'column', sm: 'row' }} flexWrap="wrap">
                                    {!pending && (
                                        <Button
                                            colorPalette="green"
                                            size="sm"
                                            flex={{ base: 'none', sm: '1' }}
                                            onClick={() => setConfirmTarget(order)}
                                        >
                                            <LuSend size={16} />
                                            В отгрузку
                                        </Button>
                                    )}
                                    {!pending && (
                                        <Button
                                            colorPalette="red"
                                            variant="outline"
                                            size="sm"
                                            flex={{ base: 'none', sm: '1' }}
                                            onClick={() => setCancelTarget(order)}
                                        >
                                            <LuBan size={16} />
                                            Отменить
                                        </Button>
                                    )}
                                    <Button asChild variant="outline" size="sm" flex={{ base: 'none', sm: '1' }}>
                                        <Link href={`/cabinet/orders/${order.id}`}>
                                            <LuEye size={16} />
                                            Открыть
                                        </Link>
                                    </Button>
                                </Flex>
                            </Card.Body>
                        </Card.Root>
                        );
                    })}
                </SimpleGrid>
                </>
            )}

            {recentShipments.length > 0 && (
                <Card.Root mt="5">
                    <Card.Body>
                        <HStack gap="2" mb="3">
                            <Box color="green.600"><LuPackageCheck size={20} /></Box>
                            <Text fontWeight="700">Отправлено на склад</Text>
                            <Text fontSize="sm" color="fg.muted">за последние двое суток</Text>
                        </HStack>
                        <VStack align="stretch" gap="2">
                            {recentShipments.map((g) => (
                                <Flex key={g.key || g.order_ids[0]} gap="3" align="center" justify="space-between" wrap="wrap" py="1.5" borderTop="1px solid" borderColor="border.muted">
                                    <VStack align="flex-start" gap="0">
                                        <Text fontSize="sm" fontWeight="600">
                                            {g.numbers.length === 1 ? `Заказ ${g.numbers[0]}` : `Заказы ${g.numbers.join(', ')}`}
                                        </Text>
                                        <Text fontSize="xs" color="fg.muted">
                                            {g.together
                                                ? `отправлены вместе ${g.sent_at_formatted} — соберут в одно место, одна накладная`
                                                : `отправлен ${g.sent_at_formatted} — собирается на складе`}
                                        </Text>
                                    </VStack>
                                    <HStack gap="2">
                                        <Button asChild size="xs" variant="outline">
                                            <Link href={g.order_ids.length === 1 ? `/cabinet/orders/${g.order_ids[0]}` : '/cabinet/orders'}>
                                                {g.order_ids.length === 1 ? 'Открыть заказ' : 'К заказам'} <LuArrowRight size={12} />
                                            </Link>
                                        </Button>
                                        {pickupEnabled && (
                                            <Button asChild size="xs" variant="ghost">
                                                <Link href="/cabinet/pickup">Самовывоз</Link>
                                            </Button>
                                        )}
                                    </HStack>
                                </Flex>
                            ))}
                        </VStack>
                    </Card.Body>
                </Card.Root>
            )}

            <Box mt="4">
                <Text fontSize="xs" color="fg.muted">
                    Пока заказ в резерве, товар удержан на складе и не уедет другому покупателю.
                    Не подтвердите до истечения срока — резерв снимется автоматически, и товар
                    вернётся в свободный остаток. Изменить состав можно на странице заказа, подтвердить и отменить — прямо здесь.
                    {shipTogetherEnabled && ' Несколько заказов для одного курьера отметьте галочками и отправьте вместе — склад оформит их одной отгрузкой.'}
                </Text>
            </Box>
        </CabinetLayout>
    );
}
