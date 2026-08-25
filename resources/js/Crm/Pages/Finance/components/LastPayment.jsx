import { Text, VStack } from '@chakra-ui/react';

/**
 * Когда партнёр платил в последний раз.
 *
 * Отвечает на вопрос, которого не видно ни в сумме, ни в сроке: клиент
 * старается платить и просто отстаёт — или замолчал совсем. Триста тысяч
 * долга у того, кто платил вчера, и у того, кто молчит полгода, — это два
 * разных разговора, а в таблице до сих пор они выглядели одинаково.
 *
 * Порог тишины — месяц: у большинства партнёров цикл закупок укладывается
 * в него, и молчание дольше означает, что оплаты прекратились, а не сдвинулись.
 */
const SILENCE_DAYS = 30;

export default function LastPayment({ date, days }) {
    if (! date) {
        return (
            <VStack align="start" gap={0}>
                <Text fontSize="sm" color="red.fg">не платил</Text>
                <Text fontSize="10px" color="fg.muted">платежей в регистре нет</Text>
            </VStack>
        );
    }

    const silent = days > SILENCE_DAYS;

    return (
        <VStack align="start" gap={0}>
            <Text fontSize="sm" whiteSpace="nowrap" color={silent ? 'red.fg' : undefined}>{date}</Text>
            <Text fontSize="10px" color={silent ? 'red.fg' : 'fg.muted'} whiteSpace="nowrap">
                {days === 0 ? 'сегодня' : `${days} дн. назад`}
            </Text>
        </VStack>
    );
}
