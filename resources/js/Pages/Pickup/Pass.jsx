import { Badge, Box, Button, Container, Image, Text, VStack } from '@chakra-ui/react';
import { Head } from '@inertiajs/react';
import { LuMapPin, LuPhone } from 'react-icons/lu';
import WarehouseMap from '@/components/common/WarehouseMap';

const places = (n) => {
    const mod10 = n % 10; const mod100 = n % 100;
    if (mod10 === 1 && mod100 !== 11) return `${n} место`;
    if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) return `${n} места`;
    return `${n} мест`;
};

/**
 * Пропуск на самовывоз для курьера (эпик pick-00, pick-10). Без входа и без шапки сайта:
 * страницу открывают с телефона у склада, возможно на плохой связи. Ни клиента, ни сумм,
 * ни состава здесь нет — ссылка могла уйти куда угодно.
 */
export default function PickupPass({ pass, schedule }) {
    const mapUrl = `https://yandex.ru/maps/?text=${encodeURIComponent(schedule.address)}`;

    return (
        <Box minH="100vh" bg="bg.muted" py="6">
            <Head title="Пропуск на самовывоз — Pecado">
                <meta name="robots" content="noindex, nofollow" />
            </Head>
            <Container maxW="md">
                <VStack align="stretch" gap="4">
                    <Image src="/logo.png" alt="Pecado" h="40px" objectFit="contain" alignSelf="center" />

                    {!pass ? (
                        <Box bg="bg" borderRadius="xl" p="6" textAlign="center">
                            <Text fontSize="xl" fontWeight="800" mb="2">Пропуск не действует</Text>
                            <Text color="fg.muted">Он отозван, истёк или ссылка скопирована не полностью. Попросите магазин прислать пропуск заново.</Text>
                        </Box>
                    ) : (
                        <Box bg="bg" borderRadius="xl" p="5" textAlign="center">
                            <Badge size="lg" colorPalette={pass.state === 'ready' ? 'green' : 'orange'} mb="3">
                                {pass.state === 'ready' && 'Можно забирать'}
                                {pass.state === 'picking' && 'Ещё собирается'}
                                {pass.state === 'empty' && 'Всё уже выдано'}
                            </Badge>
                            <Image src={pass.qr} alt="QR-код пропуска" boxSize="240px" mx="auto" bg="white" />
                            <Text fontSize="4xl" fontWeight="800" letterSpacing="0.14em" fontVariantNumeric="tabular-nums" mt="2">{pass.code}</Text>
                            <Text fontSize="sm" color="fg.muted">Покажите QR-код кладовщику или назовите шесть цифр</Text>

                            <Box mt="4" textAlign="left" borderTopWidth="1px" pt="3">
                                {pass.ready_sets > 0 && <Text fontWeight="700">Забрать: {pass.ready_sets} компл.{pass.ready_packages > 0 ? ` · ${places(pass.ready_packages)}` : ''}</Text>}
                                {pass.picking_sets > 0 && <Text color="fg.warning">Ещё собирается: {pass.picking_sets} компл. — их выдадут, когда будут готовы</Text>}
                                {pass.orders.length > 0 && (
                                    <Text fontSize="sm" color="fg.muted" mt="1">Заказы: {pass.orders.map((order) => order.number).join(', ')}</Text>
                                )}
                                <Text fontSize="sm" color="fg.muted" mt="1">Пропуск действует {pass.expires_text}</Text>
                            </Box>
                        </Box>
                    )}

                    <Box bg="bg" borderRadius="xl" p="5">
                        <Text fontSize="lg" fontWeight="800">{schedule.address}</Text>
                        <Text fontSize="sm" color="fg.muted" mb="3">{schedule.how_to_find}</Text>
                        <Box mb="4"><WarehouseMap coords={schedule.coords} title="Склад Pecado" /></Box>
                        <Text fontWeight="700">{schedule.week_text}</Text>
                        <Text fontSize="sm" color={schedule.is_open ? 'fg.muted' : 'fg.error'} mb="4">
                            {schedule.is_open ? `Сегодня выдаём до ${schedule.closes_at}. После закрытия заказы не выдаём.` : 'Сейчас склад закрыт.'}
                        </Text>
                        <VStack align="stretch" gap="2">
                            <Button asChild colorPalette="green" size="lg"><a href={mapUrl} target="_blank" rel="noreferrer noopener"><LuMapPin /> Построить маршрут</a></Button>
                            <Button asChild variant="outline" size="lg"><a href={`tel:${schedule.phone.replace(/[^+\d]/g, '')}`}><LuPhone /> Позвонить на склад</a></Button>
                        </VStack>
                    </Box>

                    <Text fontSize="xs" color="fg.muted" textAlign="center">
                        Вам выдадут только заказы из этого пропуска. Остальные заказы магазина заберёт другой курьер.
                    </Text>
                </VStack>
            </Container>
        </Box>
    );
}
