import { Box, HStack, Text, VStack } from '@chakra-ui/react';
import {
    HoverCardRoot,
    HoverCardTrigger,
    HoverCardContent,
    HoverCardArrow,
} from '@/components/ui/hover-card';
import { Badge } from '@chakra-ui/react';
import ShareBar from '@/Crm/Components/ShareBar';
import { formatCompact, formatRub } from './format';

/**
 * Финансовое состояние партнёра рядом с его именем.
 *
 * Имя без денег ничего не говорит человеку, который решает, звонить ли по
 * завтрашнему сроку. В строке — долг, доля просрочки полосой и последний
 * платёж; всё остальное (возрастные корзины, ступень дебиторки) открывается
 * наведением, чтобы таблица осталась таблицей, а не карточкой.
 *
 * Доля просрочки считается строго: всё, что просрочено хоть на день. Ступень
 * дебиторки живёт по другим правилам — с льготой в пять банковских дней и
 * отсечкой 5 000 ₽, — поэтому она показана отдельной строкой с пояснением.
 * Без него первый же вопрос будет «почему тут красное, а в дебиторке нет».
 */
export default function PartnerFinanceCell({ finance, compact = false }) {
    if (!finance) {
        return <Text fontSize="10px" color="fg.muted">движений в регистре нет</Text>;
    }

    const { debt = 0, overdue = 0, overdue_share: share = 0 } = finance;

    if (debt <= 0 && overdue <= 0) {
        return (
            <Text fontSize="10px" color="green.fg">
                рассчитались
            </Text>
        );
    }

    return (
        <HoverCardRoot size="sm" openDelay={200} closeDelay={100} positioning={{ placement: 'top' }}>
            <HoverCardTrigger asChild>
                <VStack align="start" gap={0} cursor="help" minW={compact ? '120px' : '150px'}>
                    <HStack gap={2} width="100%">
                        <Text fontSize="xs" fontWeight="600" whiteSpace="nowrap">
                            {formatCompact(debt)}
                        </Text>
                        <ShareBar
                            value={share}
                            tone={share >= 50 ? 'red' : 'orange'}
                            caption={`${share}%`}
                            height="4px"
                        />
                    </HStack>
                    {finance.last_payment && (
                        <Text fontSize="10px" color="fg.muted" whiteSpace="nowrap">
                            платёж {dateLabel(finance.last_payment.date)} · {formatCompact(finance.last_payment.amount)}
                        </Text>
                    )}
                    {!finance.last_payment && (
                        <Text fontSize="10px" color="red.fg">не платил</Text>
                    )}
                </VStack>
            </HoverCardTrigger>

            <HoverCardContent maxWidth="320px">
                <HoverCardArrow />
                <VStack align="stretch" gap={2}>
                    <Row label="Должен сейчас" value={formatRub(debt)} bold />

                    <Box>
                        <Row
                            label="Просрочено"
                            value={formatRub(overdue)}
                            tone={overdue > 0 ? 'red' : undefined}
                        />
                        {overdue > 0 && (
                            <>
                                <ShareBar value={share} tone={share >= 50 ? 'red' : 'orange'} caption={`${share}% долга`} />
                                <VStack align="stretch" gap={0} mt={1}>
                                    {(finance.buckets ?? []).map((bucket) => (
                                        <HStack key={bucket.key} justify="space-between">
                                            <Text fontSize="10px" color="fg.muted">{bucket.label}</Text>
                                            <Text fontSize="10px">{formatCompact(bucket.amount)}</Text>
                                        </HStack>
                                    ))}
                                </VStack>
                            </>
                        )}
                    </Box>

                    <Row
                        label="Последний платёж"
                        value={finance.last_payment
                            ? `${dateLabel(finance.last_payment.date)} · ${formatRub(finance.last_payment.amount)}`
                            : 'платежей не было'}
                    />
                    {finance.last_payment && (
                        <Text fontSize="10px" color={finance.last_payment.days_ago > 30 ? 'red.fg' : 'fg.muted'} mt={-2}>
                            {finance.last_payment.days_ago === 0
                                ? 'сегодня'
                                : `${finance.last_payment.days_ago} дн. назад`}
                        </Text>
                    )}

                    {finance.debt_level && (
                        <Box borderTopWidth="1px" pt={2}>
                            <HStack justify="space-between" mb={1}>
                                <Text fontSize="10px" color="fg.muted">Ступень дебиторки</Text>
                                <Badge size="xs" colorPalette={finance.debt_level.color} variant="subtle">
                                    {finance.debt_level.label}
                                </Badge>
                            </HStack>
                            <Text fontSize="10px" color="fg.muted" lineHeight="1.3">
                                Ступень считают по правилам дебиторки: льгота 5 банковских дней и
                                отсечка 5 000 ₽. Полоса выше — строгий счёт, всё просроченное хоть
                                на день, поэтому числа могут расходиться.
                            </Text>
                        </Box>
                    )}
                </VStack>
            </HoverCardContent>
        </HoverCardRoot>
    );
}

const Row = ({ label, value, tone, bold }) => (
    <HStack justify="space-between" gap={4}>
        <Text fontSize="xs" color="fg.muted">{label}</Text>
        <Text
            fontSize="xs"
            fontWeight={bold ? '700' : '500'}
            color={tone === 'red' ? 'red.fg' : undefined}
            whiteSpace="nowrap"
        >
            {value}
        </Text>
    </HStack>
);

/** ISO-дата в человеческий вид без часовых поясов. */
const dateLabel = (iso) => (iso ? iso.split('-').reverse().join('.') : '—');
