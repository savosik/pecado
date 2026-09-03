import { useState, useCallback } from 'react';
import {
    Box, Flex, Text, Button, Card, HStack, VStack, SimpleGrid, Badge,
} from '@chakra-ui/react';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { LuClock3, LuEye, LuSend, LuPackage } from 'react-icons/lu';
import CabinetLayout from '../CabinetLayout';
import ReserveCountdown from '@/components/cabinet/ReserveCountdown';
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
export default function ReservesIndex({ reserves }) {
    const [confirmTarget, setConfirmTarget] = useState(null);
    const [confirming, setConfirming] = useState(false);

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

    return (
        <CabinetLayout title="Заказы в резерве">
            <Head title="Заказы в резерве — Pecado" />

            <ConfirmDialog
                open={!!confirmTarget}
                onClose={() => setConfirmTarget(null)}
                onConfirm={doConfirm}
                title="Отправить в отгрузку?"
                description={confirmTarget
                    ? `Заказ ${confirmTarget.number} уйдёт в сборку и отгрузку — изменить или отменить его после подтверждения будет нельзя.`
                    : ''}
                confirmLabel="В отгрузку"
                cancelLabel="Ещё подумаю"
                colorPalette="green"
                isLoading={confirming}
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
                <SimpleGrid columns={{ base: 1, md: 2 }} gap="4">
                    {reserves.map((order) => (
                        <Card.Root key={order.id}>
                            <Card.Body>
                                <Flex justify="space-between" align="flex-start" gap="3" wrap="wrap">
                                    <VStack align="flex-start" gap="1">
                                        <HStack gap="2">
                                            <Text fontWeight="700">Заказ {order.number}</Text>
                                            <Badge colorPalette="purple">резерв</Badge>
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

                                {/* Полноценные кнопки, не тесные иконки: тапать с телефона */}
                                <Flex mt="4" gap="2" direction={{ base: 'column', sm: 'row' }}>
                                    <Button
                                        colorPalette="green"
                                        size="sm"
                                        flex="1"
                                        onClick={() => setConfirmTarget(order)}
                                    >
                                        <LuSend size={16} />
                                        Отправить в отгрузку
                                    </Button>
                                    <Button asChild variant="outline" size="sm" flex="1">
                                        <Link href={`/cabinet/orders/${order.id}`}>
                                            <LuEye size={16} />
                                            Открыть заказ
                                        </Link>
                                    </Button>
                                </Flex>
                            </Card.Body>
                        </Card.Root>
                    ))}
                </SimpleGrid>
            )}

            <Box mt="4">
                <Text fontSize="xs" color="fg.muted">
                    Пока заказ в резерве, товар удержан на складе и не уедет другому покупателю.
                    Не подтвердите до истечения срока — резерв снимется автоматически, и товар
                    вернётся в свободный остаток. Изменить состав или отменить заказ можно на его странице.
                </Text>
            </Box>
        </CabinetLayout>
    );
}
