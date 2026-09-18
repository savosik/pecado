import { useState, useCallback, useMemo } from 'react';
import {
    Box, Flex, Text, Button, Card, HStack, VStack, SimpleGrid, Badge,
} from '@chakra-ui/react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { LuClock3, LuEye, LuSend, LuPackage, LuBan, LuHourglass, LuTriangleAlert, LuLayers } from 'react-icons/lu';
import CabinetLayout from '../CabinetLayout';
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
export default function ReservesIndex({ reserves, ship_together_enabled: shipTogetherEnabled = false }) {
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
                    ? `Заказ ${confirmTarget.number} уйдёт в сборку и отгрузку — изменить или отменить его после подтверждения будет нельзя.${promise ? ` ${promise.text}.` : ''}`
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
                description={`Заказы ${selectedOrders.map((o) => o.number).join(', ')} уйдут на склад одной группой: по ним оформят одну реализацию и один расходный ордер, если это возможно. Пока склад не подтвердит группу, заказы остаются в резерве, но изменить или отменить их будет нельзя.${promise ? ` ${promise.text}.` : ''}`}
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
                    ? `Заказ ${cancelTarget.number} будет отменён, товар вернётся в свободный остаток. Действие необратимо.`
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
                            <Text fontWeight="600">Резервов нет</Text>
                            <Text fontSize="sm" textAlign="center">
                                Оформите заказ с пометкой «Поставьте в резерв» — он появится здесь,
                                и у вас будет время подтвердить его или изменить.
                            </Text>
                        </VStack>
                    </Card.Body>
                </Card.Root>
            ) : (
                <>
                {/* Панель совместной отгрузки: одна строка над карточками, без отдельного блока.
                    Выбор галочками на карточках, кнопка активна от двух заказов. */}
                {shipTogetherEnabled && selectable.length >= 2 && (
                    <Flex
                        mb="4"
                        gap="3"
                        align="center"
                        justify="space-between"
                        wrap="wrap"
                        position="sticky"
                        top="0"
                        zIndex="1"
                        bg="bg"
                        py="2"
                    >
                        <HStack gap="2" color="fg.muted" fontSize="sm">
                            <LuLayers size={16} />
                            <Text>
                                {selected.length === 0
                                    ? 'Отметьте заказы, которые заберёт один курьер, — склад оформит их одной отгрузкой.'
                                    : `Выбрано: ${selected.length}`}
                            </Text>
                        </HStack>
                        <HStack gap="2">
                            {selected.length > 0 && (
                                <Button variant="ghost" size="sm" onClick={() => setSelected([])}>
                                    Снять выбор
                                </Button>
                            )}
                            <Button
                                colorPalette="green"
                                size="sm"
                                disabled={selected.length < 2}
                                onClick={() => setGroupConfirmOpen(true)}
                            >
                                <LuSend size={16} />
                                В отгрузку вместе{selected.length > 0 ? ` (${selected.length})` : ''}
                            </Button>
                        </HStack>
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
                            borderColor={checked ? 'green.400' : undefined}
                            borderWidth={checked ? '2px' : undefined}
                            opacity={pending ? 0.85 : 1}
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
                                                    aria-label={`Отметить заказ ${order.number} для совместной отгрузки`}
                                                />
                                            )}
                                            <Text fontWeight="700">Заказ {order.number}</Text>
                                            <Badge colorPalette="purple">резерв</Badge>
                                            {pending && (
                                                <Badge colorPalette="orange">
                                                    <LuHourglass size={12} /> ждём склад
                                                </Badge>
                                            )}
                                        </HStack>
                                        <HStack gap="1" color="fg.muted" fontSize="sm">
                                            <LuPackage size={14} />
                                            <Text>
                                                {order.items_count} поз. · {Number(order.total_amount).toLocaleString('ru-RU', { minimumFractionDigits: 2 })} {order.currency_code === 'RUB' ? '₽' : order.currency_code}
                                            </Text>
                                        </HStack>
                                        <Text fontSize="xs" color="fg.muted">от {order.created_at_formatted}</Text>
                                    </VStack>
                                    <VStack align="flex-end" gap="0">
                                        <HStack gap="1">
                                            <LuClock3 size={16} />
                                            <ReserveCountdown until={order.reserved_until} fontSize="lg" fontWeight="700" />
                                        </HStack>
                                        <Text fontSize="xs" color="fg.muted">до {order.reserved_until_formatted}</Text>
                                    </VStack>
                                </Flex>

                                {/* Итог группы (v16.11.0): ожидание — действия закрыты, отказ — причина и совет */}
                                {pending && (
                                    <HStack mt="3" gap="2" fontSize="sm" color="orange.fg" align="flex-start">
                                        <Box mt="0.5"><LuHourglass size={14} /></Box>
                                        <Text>
                                            Отправлен в отгрузку вместе с другими заказами — ждём подтверждения склада.
                                            Пока ответа нет, изменить или отменить заказ нельзя; товар остаётся удержанным.
                                        </Text>
                                    </HStack>
                                )}
                                {conflict && (
                                    <HStack mt="3" gap="2" fontSize="sm" color="red.fg" align="flex-start">
                                        <Box mt="0.5"><LuTriangleAlert size={14} /></Box>
                                        <Text>
                                            Отправить вместе не удалось: {conflict.label.toLowerCase()}.
                                            {conflict.message ? ` ${conflict.message}` : ''} Заказ снова в резерве —
                                            отправьте его вместе с другими ещё раз или по одному.
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
