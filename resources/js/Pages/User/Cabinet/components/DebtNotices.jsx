import { useEffect } from 'react';
import { Box, Flex, HStack, Stack, Text, Badge } from '@chakra-ui/react';
import { Link, usePage } from '@inertiajs/react';
import { LuTriangleAlert, LuCalendarClock, LuReceipt, LuFileText, LuCircleCheck } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { toaster } from '@/components/ui/toaster';
import { formatPrice } from '@/utils/formatPrice';

// Лестница долга в кабинете (карточка debt-03). Три поверхности:
//  - DebtBanner — сквозной баннер на всех страницах кабинета, только когда
//    действует ограничение (предзаказы/заказы закрыты) + один тост за смену ступени;
//  - DebtStatusCard — карточка на дашборде при любой видимой ступени;
//  - DueSoonCard — «срок подходит» за несколько дней до даты.
// Норма невидима: без пропса `debt` ни один компонент ничего не рендерит.

const palette = {
    yellow: { bg: 'yellow.50', border: 'yellow.200', fg: 'yellow.900', icon: 'yellow.600', dark: { bg: 'yellow.900', border: 'yellow.700', fg: 'yellow.100' } },
    orange: { bg: 'orange.50', border: 'orange.200', fg: 'orange.900', icon: 'orange.600', dark: { bg: 'orange.900', border: 'orange.700', fg: 'orange.100' } },
    red: { bg: 'red.50', border: 'red.200', fg: 'red.900', icon: 'red.600', dark: { bg: 'red.900', border: 'red.700', fg: 'red.100' } },
    green: { bg: 'green.50', border: 'green.200', fg: 'green.900', icon: 'green.600', dark: { bg: 'green.900', border: 'green.700', fg: 'green.100' } },
};

const storageKey = (userId) => `pecado:debt-level:${userId || 'anon'}`;

function readSeen(key) {
    try {
        return window.localStorage.getItem(key);
    } catch {
        return null;
    }
}

function writeSeen(key, value) {
    try {
        if (value === null) {
            window.localStorage.removeItem(key);
        } else {
            window.localStorage.setItem(key, value);
        }
    } catch {
        // Приватный режим или запрет хранилища — тост просто покажется ещё раз.
    }
}

function DebtActions({ debt, size = 'sm' }) {
    const links = debt?.links || {};

    return (
        <HStack gap="2" wrap="wrap">
            {links.payments && (
                <Button as={Link} href={links.payments} size={size} colorPalette="red" variant="solid">
                    <LuReceipt /> К оплатам
                </Button>
            )}
            {links.reconciliation && (
                <Button as={Link} href={links.reconciliation} size={size} variant="outline">
                    <LuFileText /> Акт сверки
                </Button>
            )}
            {!links.payments && links.documents && (
                <Button as={Link} href={links.documents} size={size} variant="outline">
                    <LuFileText /> Документы
                </Button>
            )}
        </HStack>
    );
}

/**
 * Один тост за смену ступени, не при каждом входе — иначе быстро слепнут.
 */
function useDebtToast(debt, userId) {
    useEffect(() => {
        const key = storageKey(userId);

        if (!debt?.visible) {
            writeSeen(key, null);
            return;
        }

        if (readSeen(key) === debt.key) {
            return;
        }

        writeSeen(key, debt.key);
        toaster.create({
            type: debt.restricted ? 'error' : 'warning',
            title: debt.level_label,
            description: `${debt.hint} Просрочено ${formatPrice(debt.overdue_amount)}.`,
            duration: 9000,
            meta: debt.links?.payments ? { buttons: [{ label: 'К оплатам', onClick: () => window.location.assign(debt.links.payments) }] } : undefined,
        });
    }, [debt?.key, debt?.visible, userId]);
}

export function DebtBanner() {
    const { debt, auth } = usePage().props;
    useDebtToast(debt, auth?.user?.id);

    if (!debt?.restricted) {
        return null;
    }

    const tone = palette.red;

    return (
        <Box
            role="status"
            borderRadius="xl"
            border="1px solid"
            borderColor={tone.border}
            bg={tone.bg}
            _dark={{ bg: tone.dark.bg, borderColor: tone.dark.border }}
            p="4"
            mb="5"
        >
            <Flex gap="4" align={{ base: 'flex-start', md: 'center' }} direction={{ base: 'column', md: 'row' }}>
                <HStack align="flex-start" gap="3" flex="1" minW="0">
                    <Box color={tone.icon} fontSize="2xl" lineHeight="1" mt="0.5">
                        <LuTriangleAlert />
                    </Box>
                    <Stack gap="1">
                        <Text fontWeight="semibold" color={tone.fg} _dark={{ color: tone.dark.fg }}>
                            {debt.level_label}: просрочено {formatPrice(debt.overdue_amount)}
                        </Text>
                        <Text fontSize="sm" color={tone.fg} _dark={{ color: tone.dark.fg }}>
                            {debt.hint} Ограничение снимается автоматически в день поступления оплаты.
                        </Text>
                    </Stack>
                </HStack>
                <DebtActions debt={debt} />
            </Flex>
        </Box>
    );
}

export function DebtStatusCard() {
    const { debt } = usePage().props;

    if (!debt?.visible) {
        return null;
    }

    const tone = palette[debt.color] || palette.red;

    return (
        <Box
            borderRadius="xl"
            border="1px solid"
            borderColor={tone.border}
            bg="bg"
            p="4"
            mb="6"
        >
            <Flex gap="3" align="flex-start" wrap="wrap">
                <Box color={tone.icon} fontSize="2xl" lineHeight="1" mt="0.5">
                    <LuTriangleAlert />
                </Box>
                <Stack gap="3" flex="1" minW="0">
                    <Stack gap="1">
                        <HStack gap="2" wrap="wrap">
                            <Text fontWeight="bold" color="fg" fontSize="lg">
                                Есть просроченные оплаты на {formatPrice(debt.overdue_amount)}
                            </Text>
                            <Badge colorPalette={debt.color} variant="subtle">{debt.level_label}</Badge>
                            {debt.pause && (
                                <Badge colorPalette="green" variant="subtle">
                                    <LuCircleCheck /> ограничения сняты до {debt.pause.until}
                                </Badge>
                            )}
                        </HStack>
                        <Text fontSize="sm" color="fg.muted">
                            Самый ранний срок оплаты — {debt.oldest_due_date || '—'}.
                            {' '}{debt.hint}
                        </Text>
                    </Stack>

                    {debt.contractors?.length > 0 && (
                        <Stack gap="1.5">
                            {debt.contractors.map((row) => (
                                <Flex key={row.company_id} justify="space-between" gap="3" fontSize="sm" wrap="wrap">
                                    <HStack gap="2" minW="0">
                                        <Text color="fg" fontWeight="medium" truncate>{row.company_name}</Text>
                                        {row.level !== 'overdue' && (
                                            <Badge colorPalette={row.level === 'hold' || row.level === 'no_orders' ? 'red' : 'orange'} variant="subtle" size="xs">
                                                {row.level_label}
                                            </Badge>
                                        )}
                                    </HStack>
                                    <Text color="fg.muted" whiteSpace="nowrap">
                                        {formatPrice(row.overdue_amount)} · срок {row.oldest_due_date || '—'}
                                    </Text>
                                </Flex>
                            ))}
                        </Stack>
                    )}

                    <DebtActions debt={debt} />
                </Stack>
            </Flex>
        </Box>
    );
}

export function DueSoonCard() {
    const { debt } = usePage().props;
    const soon = debt?.due_soon;

    if (!soon) {
        return null;
    }

    const tone = palette.yellow;

    return (
        <Box
            borderRadius="xl"
            border="1px solid"
            borderColor={tone.border}
            bg="bg"
            p="4"
            mb="6"
        >
            <Flex gap="3" align="flex-start" wrap="wrap">
                <Box color={tone.icon} fontSize="2xl" lineHeight="1" mt="0.5">
                    <LuCalendarClock />
                </Box>
                <Stack gap="3" flex="1" minW="0">
                    <Stack gap="1">
                        <Text fontWeight="bold" color="fg" fontSize="lg">
                            Приближается срок оплаты: {formatPrice(soon.amount)}
                        </Text>
                        <Text fontSize="sm" color="fg.muted">
                            Ближайшая дата — {soon.nearest_date}. Документов: {soon.count}.
                        </Text>
                    </Stack>
                    <Stack gap="1.5">
                        {soon.lines.map((line, index) => (
                            <Flex key={`${line.document}-${index}`} justify="space-between" gap="3" fontSize="sm" wrap="wrap">
                                <Text color="fg" minW="0" truncate>
                                    {line.document}{line.document_date ? ` от ${line.document_date}` : ''}
                                    {line.company_name ? ` · ${line.company_name}` : ''}
                                    {line.organization_name ? ` → ${line.organization_name}` : ''}
                                </Text>
                                <Text color="fg.muted" whiteSpace="nowrap">
                                    {formatPrice(line.amount)} · до {line.due_date}
                                </Text>
                            </Flex>
                        ))}
                        {soon.count > soon.lines.length && (
                            <Text fontSize="xs" color="fg.muted">и ещё {soon.count - soon.lines.length}</Text>
                        )}
                    </Stack>
                    <DebtActions debt={debt} />
                </Stack>
            </Flex>
        </Box>
    );
}
