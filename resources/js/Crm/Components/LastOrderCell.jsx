import { Text, VStack } from '@chakra-ui/react';
import { Tooltip } from '@/components/ui/tooltip';

const RUB = new Intl.NumberFormat('ru-RU', {
    style: 'currency',
    currency: 'RUB',
    maximumFractionDigits: 0,
});

/**
 * Дата и сумма последнего заказа партнёра.
 *
 * Это **заказ**, а не отгрузка: намерение клиента, а не факт продажи. Факт
 * в колонке «План / факт» считается по отгрузкам, поэтому цифры не обязаны
 * совпадать — подсказка проговаривает это прямо, иначе на брифинге разницу
 * прочтут как ошибку.
 *
 * Партнёр без заказов показывает прочерк, а не «0 ₽»: «не заказывал ни разу»
 * и «заказал на ноль» — разные вещи.
 *
 * @param {{value: {number: string|null, at_label: string|null, amount_rub: number}|null}} props
 */
export default function LastOrderCell({ value }) {
    if (! value) {
        return <Text fontSize="sm" color="fg.muted">—</Text>;
    }

    return (
        <Tooltip
            content={`Последний заказ${value.number ? ` № ${value.number}` : ''}${value.at_label ? ` от ${value.at_label}` : ''}. Это заказ, а не отгрузка: факт продаж считается по отгрузкам.`}
            openDelay={400}
        >
            <VStack align="start" gap={0}>
                <Text fontSize="sm" fontWeight="medium">{RUB.format(value.amount_rub)}</Text>
                <Text fontSize="11px" color="fg.muted">{value.at_label ?? '—'}</Text>
            </VStack>
        </Tooltip>
    );
}
