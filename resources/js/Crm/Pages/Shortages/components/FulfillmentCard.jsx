import { Box, Flex, HStack, Text, VStack } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { Tooltip } from '@/components/ui/tooltip';
import { LuInfo } from 'react-icons/lu';

const money = (value) =>
    new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(value || 0);

const percent = (value) => (value === null || value === undefined ? '—' : `${String(value).replace('.', ',')} %`);

/**
 * Цвет показателя. Пороги грубые и намеренно постоянные: раздел должен читаться
 * одинаково у всех менеджеров, а «хорошо» и «плохо» здесь не настраиваются.
 */
const tone = (value) => {
    if (value === null || value === undefined) return 'fg.muted';
    if (value >= 97) return 'green.fg';
    if (value >= 90) return 'orange.fg';

    return 'red.fg';
};

function Metric({ label, value, hint, note }) {
    return (
        <VStack align="start" gap={0} minW="150px">
            <HStack gap={1}>
                <Text fontSize="xs" color="fg.muted">{label}</Text>
                {hint && (
                    <Tooltip content={hint} openDelay={300} showArrow>
                        <Box color="fg.muted" cursor="help" display="flex"><LuInfo size={12} /></Box>
                    </Tooltip>
                )}
            </HStack>
            <Text fontSize="2xl" fontWeight="bold" lineHeight="1.1" color={tone(value)}>
                {percent(value)}
            </Text>
            {note && <Text fontSize="xs" color="fg.muted">{note}</Text>}
        </VStack>
    );
}

/**
 * Степень удовлетворения заказов за период.
 *
 * Журнал показывает, что отменилось, но не показывает, много ли это: сотня
 * отмен — провал при трёхстах строках и погрешность при тридцати тысячах.
 * Здесь та же сотня превращается в долю довезённого.
 *
 * База — отгруженные заказы: пока заказ собирают, состав ещё изменится (склад
 * снимает позиции именно при сборке), и процент по нему был бы завышен.
 * Переключатель «все заказы» показывает текущую картину месяца, пока заказы
 * ещё в работе.
 */
export default function FulfillmentCard({ data, onChangeBasis }) {
    if (!data) {
        return null;
    }

    const basis = data.basis === 'all' ? 'all' : 'settled';
    const empty = !data.orders_count;

    return (
        <Box borderWidth="1px" borderRadius="lg" p={4} bg="bg.panel">
            <Flex gap={6} wrap="wrap" align="start" justify="space-between">
                <VStack align="start" gap={1}>
                    <Text fontWeight="semibold">Степень удовлетворения</Text>
                    <Text fontSize="xs" color="fg.muted" maxW="320px">
                        Какая доля заказанного за период доехала до клиента. Считается по заказам
                        с датой документа в периоде, а не по датам отмен.
                    </Text>
                </VStack>

                {empty ? (
                    <Text fontSize="sm" color="fg.muted">
                        За период нет {basis === 'settled' ? 'отгруженных ' : ''}заказов — считать не от чего.
                    </Text>
                ) : (
                    <HStack gap={8} wrap="wrap">
                        <Metric
                            label="По сумме"
                            value={data.amount_rate}
                            hint="Доля суммы заказов, которая не была отменена."
                            note={`${money(data.amount_total - data.amount_cancelled)} из ${money(data.amount_total)} ₽`}
                        />
                        <Metric
                            label="По строкам"
                            value={data.lines_rate}
                            hint="Доля строк заказов, доехавших до клиента."
                            note={`${data.lines_total - data.lines_cancelled} из ${data.lines_total}`}
                        />
                        <Metric
                            label="Заказы без недобора"
                            value={data.orders_rate}
                            hint="Доля заказов, в которых не отменилось ни одной строки. Клиент замечает именно это."
                            note={`${data.complete_orders} из ${data.orders_count}`}
                        />
                    </HStack>
                )}

                <HStack gap={1}>
                    <Tooltip
                        content="Заказы, состав которых уже не изменится: отгруженные, ожидающие оплаты и готовые к закрытию."
                        openDelay={300}
                        showArrow
                    >
                        <Button
                            size="xs"
                            variant={basis === 'settled' ? 'subtle' : 'ghost'}
                            onClick={() => onChangeBasis?.('settled')}
                        >
                            Отгруженные заказы
                        </Button>
                    </Tooltip>
                    <Button
                        size="xs"
                        variant={basis === 'all' ? 'subtle' : 'ghost'}
                        onClick={() => onChangeBasis?.('all')}
                    >
                        Все заказы
                    </Button>
                </HStack>
            </Flex>
        </Box>
    );
}
