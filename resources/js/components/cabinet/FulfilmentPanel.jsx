import { Badge, Box, Button, Card, Flex, HStack, Text } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import { LuPackageCheck, LuQrCode } from 'react-icons/lu';

const STEPS = ['На складе', 'Сборка', 'Собран', 'Выдан'];
const STEPS_DELIVERY = ['На складе', 'Сборка', 'Отгружен'];

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

/** Бейдж стадии исполнения (pick-05): один понятный ответ вместо технического статуса 1С. */
export function FulfilmentBadge({ fulfilment, size = 'xs', solid = false }) {
    if (!fulfilment || fulfilment.stage === 'none') return null;

    return (
        <Badge colorPalette={fulfilment.color} variant={solid || fulfilment.stage === 'ready' ? 'solid' : 'subtle'}
            fontSize={size} fontWeight="600" px="2.5" py="1" borderRadius="full">
            {fulfilment.label}
        </Badge>
    );
}

/**
 * Плашка «что со сборкой» в карточке заказа (эпик pick-00, pick-05): шкала, число мест,
 * обещанное время, а для собранного самовывоза — кнопка «Пропуск курьеру».
 */
export default function FulfilmentPanel({ fulfilment }) {
    if (!fulfilment || fulfilment.step === 0) return null;

    const steps = fulfilment.is_pickup ? STEPS : STEPS_DELIVERY;
    const ready = fulfilment.stage === 'ready';
    const handed = fulfilment.stage === 'handed_over';
    const several = fulfilment.issues_total > 1;

    return (
        <Card.Root borderWidth="1px" borderColor={ready ? 'green.300' : 'border'} bg={ready ? 'green.50' : undefined}
            _dark={ready ? { bg: 'green.900/20', borderColor: 'green.700' } : undefined}>
            <Card.Body py="4">
                <Flex gap="3" justify="space-between" align={{ base: 'stretch', md: 'center' }} direction={{ base: 'column', md: 'row' }}>
                    <HStack gap="3" align="flex-start">
                        <Box color={ready ? 'green.600' : 'fg.muted'} mt="1"><LuPackageCheck size={22} /></Box>
                        <Box>
                            <Text fontWeight="700">{fulfilment.label}</Text>
                            <Text fontSize="sm" color="fg.muted">
                                {ready && `${places(fulfilment.packages_total)}${fulfilment.ready_since ? ` · ждёт с ${timeText(fulfilment.ready_since).replace('сегодня в ', '')}` : ''}. Отправляйте курьера с пропуском.`}
                                {handed && `Выдан ${timeText(fulfilment.handed_at)}${fulfilment.packages_handed ? ` · ${places(fulfilment.packages_handed)}` : ''}.`}
                                {!ready && !handed && (fulfilment.promise?.text || fulfilment.hint)}
                                {fulfilment.promise?.is_overdue && ' Склад задерживается — заказ в приоритете.'}
                            </Text>
                            {several && (
                                <Text fontSize="xs" color="fg.muted">
                                    Заказ собирается частями: готово {fulfilment.issues_done} из {fulfilment.issues_total}.
                                </Text>
                            )}
                        </Box>
                    </HStack>
                    {ready && (
                        <Button asChild colorPalette="green" flexShrink={0}>
                            <Link href="/cabinet/pickup"><LuQrCode size={16} /> Пропуск курьеру</Link>
                        </Button>
                    )}
                </Flex>

                <Flex gap="1" mt="4">
                    {steps.map((label, index) => {
                        const done = index < Math.min(fulfilment.step, steps.length);
                        return (
                            <Box key={label} flex="1">
                                <Box h="6px" borderRadius="full" bg={done ? 'green.solid' : 'bg.emphasized'} />
                                <Text mt="1" fontSize="xs" color={done ? 'fg' : 'fg.muted'} fontWeight={index === fulfilment.step - 1 ? '700' : '400'}>{label}</Text>
                            </Box>
                        );
                    })}
                </Flex>
                {fulfilment.events?.length > 0 && (
                    <Box mt="4" pt="3" borderTopWidth="1px">
                        <Text fontSize="xs" fontWeight="700" color="fg.muted" mb="1" textTransform="uppercase" letterSpacing="0.06em">История склада</Text>
                        {fulfilment.events.map((event) => (
                            <Flex key={`${event.at}-${event.label}`} justify="space-between" gap="3" fontSize="sm" py="0.5">
                                <Text color={event.tone === 'good' ? 'green.fg' : 'fg'}>{event.label}</Text>
                                <Text color="fg.muted" flexShrink={0}>{timeText(event.at).replace('сегодня в ', '')}</Text>
                            </Flex>
                        ))}
                    </Box>
                )}
            </Card.Body>
        </Card.Root>
    );
}
