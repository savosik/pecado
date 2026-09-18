import { useMemo, useState } from 'react';
import { Badge, Box, Button, Card, Flex, HStack, Image, Input, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { LuClock3, LuCopy, LuPackageCheck, LuQrCode, LuShare2, LuTruck } from 'react-icons/lu';
import CabinetLayout from '../CabinetLayout';
import { Checkbox } from '@/components/ui/checkbox';
import { ConfirmDialog } from '@/shared/Panel/ConfirmDialog';
import { toastError, toastSuccess } from '@/utils/toast';

const places = (n) => {
    const mod10 = n % 10; const mod100 = n % 100;
    if (mod10 === 1 && mod100 !== 11) return `${n} место`;
    if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return `${n} места`;
    return `${n} мест`;
};

const timeText = (iso) => {
    if (!iso) return '';
    const date = new Date(iso);
    const time = date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
    return date.toDateString() === new Date().toDateString()
        ? `сегодня в ${time}`
        : `${date.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' })} в ${time}`;
};

/**
 * Раздел «Самовывоз» (эпик pick-00, pick-09): что собрано и ждёт курьера, что ещё собирается,
 * пропуска с QR-кодом. Сценарий мобильный: клиент выпускает пропуск и тут же пересылает
 * ссылку курьеру в мессенджер.
 */
export default function PickupIndex({ ready, picking, handed, passes, schedule, multiCompany }) {
    const [selected, setSelected] = useState([]);
    const [courier, setCourier] = useState('');
    const [busy, setBusy] = useState(false);
    const [revokeTarget, setRevokeTarget] = useState(null);
    const [zoom, setZoom] = useState(null);

    const selectedPlaces = useMemo(
        () => ready.filter((row) => selected.includes(row.id)).reduce((sum, row) => sum + row.packages_count, 0),
        [ready, selected],
    );

    const toggle = (id) => setSelected((list) => (list.includes(id) ? list.filter((x) => x !== id) : [...list, id]));

    const createPass = async (scope) => {
        setBusy(true);
        try {
            await axios.post('/cabinet/pickup/passes', { scope, goods_issue_ids: scope === 'selected' ? selected : [], courier_name: courier });
            toastSuccess('Пропуск готов', 'Перешлите ссылку курьеру — он покажет QR-код на складе.');
            setSelected([]); setCourier('');
            router.reload();
        } catch (err) {
            toastError('Пропуск не выпущен', err?.response?.data?.message || 'Попробуйте ещё раз.');
        } finally {
            setBusy(false);
        }
    };

    const revoke = async () => {
        setBusy(true);
        try {
            const { data } = await axios.post(`/cabinet/pickup/passes/${revokeTarget.id}/revoke`);
            toastSuccess('Пропуск отозван', data?.message);
        } catch (err) {
            toastError('Не получилось', err?.response?.data?.message || 'Попробуйте ещё раз.');
        } finally {
            setBusy(false); setRevokeTarget(null); router.reload();
        }
    };

    const share = async (pass) => {
        const text = `Пропуск на склад Pecado, код ${pass.code}. ${schedule.address}, ${schedule.week_text}.`;
        if (navigator.share) {
            try { await navigator.share({ title: 'Пропуск на самовывоз', text, url: pass.url }); return; } catch { /* отменили — скопируем ниже */ }
        }
        try {
            await navigator.clipboard.writeText(`${text} ${pass.url}`);
            toastSuccess('Ссылка скопирована', 'Вставьте её в сообщение курьеру.');
        } catch {
            toastError('Не удалось скопировать', pass.url);
        }
    };

    const orderLinks = (row) => row.orders.map((order, index) => (
        <Text as="span" key={order.id}>
            {index > 0 && ', '}
            <Link href={`/cabinet/orders/${order.id}`} style={{ fontWeight: 700 }}>{order.number}</Link>
        </Text>
    ));

    return (
        <CabinetLayout title="Самовывоз">
            <Head title="Самовывоз — Pecado" />

            <ConfirmDialog open={!!revokeTarget} onClose={() => setRevokeTarget(null)} onConfirm={revoke} isLoading={busy}
                title="Отозвать пропуск?" confirmLabel="Отозвать" cancelLabel="Оставить"
                description="Ссылка и код перестанут работать сразу. Если курьер уже в пути — выпустите новый пропуск и перешлите ему." />

            <Card.Root mb="4" bg="bg.muted">
                <Card.Body>
                    <SimpleGrid columns={{ base: 1, md: 2 }} gap="4">
                        <Box>
                            <Text fontSize="xs" color="fg.muted" textTransform="uppercase" letterSpacing="0.06em">Склад</Text>
                            <Text fontWeight="700">{schedule.address}</Text>
                            <Text fontSize="sm" color="fg.muted">{schedule.how_to_find}</Text>
                        </Box>
                        <Box>
                            <Text fontSize="xs" color="fg.muted" textTransform="uppercase" letterSpacing="0.06em">Выдача</Text>
                            <Text fontWeight="700">{schedule.week_text}</Text>
                            <Text fontSize="sm" color="fg.muted">
                                {schedule.is_open
                                    ? `Сегодня выдаём до ${schedule.closes_at}${schedule.cutoff_at ? `, к сборке на сегодня принимаем до ${schedule.cutoff_at}` : ''}`
                                    : schedule.opens_at
                                        ? `Сейчас склад закрыт, откроется в ${schedule.opens_at}`
                                        : 'Сегодня склад не работает'}
                            </Text>
                        </Box>
                    </SimpleGrid>
                </Card.Body>
            </Card.Root>

            {passes.length > 0 && (
                <Box mb="6">
                    <Text fontWeight="700" fontSize="lg" mb="3">Действующие пропуска</Text>
                    <SimpleGrid columns={{ base: 1, md: 2 }} gap="4">
                        {passes.map((pass) => (
                            <Card.Root key={pass.id} borderColor="green.muted" borderWidth="2px">
                                <Card.Body>
                                    <Flex gap="4" align="center">
                                        <Image src={pass.qr} alt="QR-код пропуска" boxSize={zoom === pass.id ? '220px' : '112px'} cursor="pointer"
                                            onClick={() => setZoom(zoom === pass.id ? null : pass.id)} bg="white" borderRadius="md" flexShrink={0} />
                                        <VStack align="flex-start" gap="1" minW="0">
                                            <Text fontSize="2xl" fontWeight="800" letterSpacing="0.12em" fontVariantNumeric="tabular-nums">{pass.code}</Text>
                                            <Text fontSize="sm">
                                                {pass.scope === 'all' ? 'На всё готовое' : 'На выбранные заказы'} · {pass.to_issue} компл. · {places(pass.packages_to_issue)}
                                            </Text>
                                            <Text fontSize="xs" color="fg.muted">Действует {pass.expires_text}{pass.courier_name ? ` · курьер: ${pass.courier_name}` : ''}</Text>
                                        </VStack>
                                    </Flex>
                                    <Flex mt="4" gap="2" direction={{ base: 'column', sm: 'row' }}>
                                        <Button colorPalette="green" flex="1" onClick={() => share(pass)}><LuShare2 size={16} /> Переслать курьеру</Button>
                                        <Button variant="outline" onClick={() => navigator.clipboard?.writeText(pass.url).then(() => toastSuccess('Ссылка скопирована', ''))}><LuCopy size={16} /> Ссылка</Button>
                                        <Button variant="outline" colorPalette="red" onClick={() => setRevokeTarget(pass)}>Отозвать</Button>
                                    </Flex>
                                </Card.Body>
                            </Card.Root>
                        ))}
                    </SimpleGrid>
                </Box>
            )}

            <Text fontWeight="700" fontSize="lg" mb="3">Готово к выдаче</Text>
            {ready.length === 0 ? (
                <Card.Root mb="6"><Card.Body>
                    <VStack py="6" gap="2" color="fg.muted">
                        <LuPackageCheck size={32} />
                        <Text fontWeight="600">Собранных заказов пока нет</Text>
                        <Text fontSize="sm" textAlign="center">Когда склад соберёт заказ с самовывозом, он появится здесь, а мы пришлём письмо.</Text>
                    </VStack>
                </Card.Body></Card.Root>
            ) : (
                <Box mb="6">
                    <VStack align="stretch" gap="2" mb="3">
                        {ready.map((row) => (
                            <Card.Root key={row.id} size="sm" borderColor={selected.includes(row.id) ? 'green.solid' : undefined}>
                                <Card.Body>
                                    <HStack gap="3" align="flex-start">
                                        <Checkbox mt="1" checked={selected.includes(row.id)} onCheckedChange={() => toggle(row.id)} aria-label="Выбрать для пропуска" />
                                        <Box flex="1" minW="0">
                                            <Text>{orderLinks(row)}</Text>
                                            <Text fontSize="sm" color="fg.muted">
                                                {places(row.packages_count)} · ждёт с {timeText(row.ready_since).replace('сегодня в ', '')}
                                                {multiCompany && row.company ? ` · ${row.company}` : ''}
                                            </Text>
                                            {row.orders.length > 1 && <Text fontSize="xs" color="fg.muted">Собраны вместе — выдаются одним комплектом</Text>}
                                        </Box>
                                        <Badge colorPalette="green" flexShrink={0}>собран</Badge>
                                    </HStack>
                                </Card.Body>
                            </Card.Root>
                        ))}
                    </VStack>
                    <Input mb="2" placeholder="Имя курьера (необязательно)" value={courier} onChange={(e) => setCourier(e.target.value)} maxLength={120} />
                    <Flex gap="2" direction={{ base: 'column', sm: 'row' }}>
                        <Button colorPalette="green" flex="1" loading={busy} onClick={() => createPass('all')}><LuQrCode size={16} /> Пропуск на всё готовое</Button>
                        <Button variant="outline" flex="1" loading={busy} disabled={selected.length === 0} onClick={() => createPass('selected')}>
                            Пропуск на выбранное{selected.length > 0 ? ` · ${places(selectedPlaces)}` : ''}
                        </Button>
                    </Flex>
                    <Text mt="2" fontSize="xs" color="fg.muted">
                        Заказы, не вошедшие в пропуск, останутся на складе и дождутся другого курьера. Курьеру по ссылке видны только адрес, часы и номера заказов.
                    </Text>
                </Box>
            )}

            {picking.length > 0 && (
                <Box mb="6">
                    <Text fontWeight="700" fontSize="lg" mb="3">Собираются</Text>
                    <VStack align="stretch" gap="2">
                        {picking.map((row) => (
                            <Card.Root key={row.id} size="sm"><Card.Body>
                                <HStack justify="space-between" gap="3">
                                    <Text minW="0">{orderLinks(row)}</Text>
                                    <Badge colorPalette="orange" flexShrink={0}><LuClock3 size={12} /> {row.promised_text ? `к ~${row.promised_text}` : 'в работе'}</Badge>
                                </HStack>
                            </Card.Body></Card.Root>
                        ))}
                    </VStack>
                </Box>
            )}

            {handed.length > 0 && (
                <Box>
                    <Text fontWeight="700" fontSize="lg" mb="3">Выдано за 7 дней</Text>
                    <VStack align="stretch" gap="2">
                        {handed.map((row) => (
                            <Card.Root key={row.id} size="sm"><Card.Body>
                                <HStack justify="space-between" gap="3">
                                    <Text minW="0">{orderLinks(row)}</Text>
                                    <HStack gap="1" color="fg.muted" fontSize="sm" flexShrink={0}>
                                        <LuTruck size={14} /><Text>{timeText(row.handed_at)}{row.recipient_name ? ` · ${row.recipient_name}` : ''}</Text>
                                    </HStack>
                                </HStack>
                            </Card.Body></Card.Root>
                        ))}
                    </VStack>
                </Box>
            )}
        </CabinetLayout>
    );
}
