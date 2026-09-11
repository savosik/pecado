import { Link } from '@inertiajs/react';
import { Badge, Box, HStack, Text, VStack } from '@chakra-ui/react';
import { fmtDay, fmtRub0, plural } from '../../Salary/components/format';

/**
 * Ячейки, общие для списков партнёров: имя с пометками, деньги, дата с «молчит».
 */
export function PartnerName({ row }) {
    return (
        <VStack align="start" gap={0.5}>
            <Link href={`/crm/partners/${row.id}`}>
                <Text fontWeight="600" fontSize="sm" _hover={{ textDecoration: 'underline' }}>{row.name}</Text>
            </Link>
            <HStack gap={1} flexWrap="wrap">
                {row.in_novelty && <Badge size="xs" colorPalette="blue" variant="subtle">новый партнёр · П2</Badge>}
                {!row.ever_bought && <Badge size="xs" colorPalette="gray" variant="subtle">ни разу не покупал</Badge>}
                {row.abc && <Badge size="xs" variant="outline">{row.abc}</Badge>}
            </HStack>
        </VStack>
    );
}

export function Money({ value, muted = false, strong = false }) {
    const zero = !value;

    return (
        <Text fontVariantNumeric="tabular-nums" fontSize="sm" color={zero || muted ? 'fg.subtle' : undefined} fontWeight={strong ? '700' : undefined}>
            {zero ? '—' : fmtRub0(value)}
        </Text>
    );
}

export function BestMonth({ value }) {
    if (!value || !value.amount) {
        return <Text color="fg.subtle" fontSize="sm">—</Text>;
    }

    const [year, month] = String(value.period ?? '').split('-');
    const label = year && month ? `${month}.${year}` : '';

    return (
        <VStack align="end" gap={0}>
            <Money value={value.amount} />
            <Text fontSize="xs" color="fg.subtle">{label}</Text>
        </VStack>
    );
}

export function Assortment({ value }) {
    const taken = Number(value?.taken ?? 0);
    const total = Number(value?.total ?? 0);
    const share = total > 0 ? Math.min(1, taken / total) : 0;

    return (
        <VStack align="stretch" gap={1} minW="90px">
            <Text fontSize="xs" color="fg.muted" textAlign="right">{taken} из {total}</Text>
            <Box h="6px" bg="bg.muted" borderRadius="full" overflow="hidden" aria-label={`Категорий: ${taken} из ${total}`}>
                <Box h="100%" w={`${share * 100}%`} bg="blue.solid" borderRadius="full" />
            </Box>
        </VStack>
    );
}

export function LastPurchase({ row }) {
    if (!row.last_purchase_on) {
        return <Text color="fg.subtle" fontSize="sm">не покупал</Text>;
    }

    const silent = Number(row.silent_days ?? 0);
    const late = row.cycle_days > 0 && silent > row.cycle_days;

    return (
        <VStack align="start" gap={0}>
            <Text fontSize="sm">{fmtDay(row.last_purchase_on)}</Text>
            <Text fontSize="xs" color={late ? 'orange.fg' : 'fg.subtle'}>
                молчит {silent} {plural(silent, 'день', 'дня', 'дней')}
            </Text>
        </VStack>
    );
}

export function Debt({ value }) {
    if (!value || !value.amount) {
        return <Text color="fg.subtle" fontSize="sm">—</Text>;
    }

    return (
        <VStack align="end" gap={0}>
            <Text fontSize="sm" fontWeight={value.overdue ? '700' : undefined} color={value.overdue ? 'red.fg' : undefined} fontVariantNumeric="tabular-nums">
                {fmtRub0(value.amount)}
            </Text>
            {value.overdue && (
                <Text fontSize="xs" color="red.fg">просрочено {value.days} {plural(value.days, 'день', 'дня', 'дней')}</Text>
            )}
        </VStack>
    );
}

const TOUCH_LABEL = { call: 'звонок', email: 'письмо', task: 'задача' };

export function LastTouch({ value }) {
    if (!value) {
        return <Text color="fg.subtle" fontSize="sm">не было</Text>;
    }

    return (
        <VStack align="start" gap={0}>
            <Text fontSize="sm">{fmtDay(value.at)}</Text>
            <Text fontSize="xs" color="fg.subtle">{TOUCH_LABEL[value.kind] ?? value.kind}</Text>
        </VStack>
    );
}
