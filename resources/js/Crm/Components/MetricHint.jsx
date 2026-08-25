import { Box } from '@chakra-ui/react';
import { LuInfo } from 'react-icons/lu';
import { Tooltip } from '@/components/ui/tooltip';

/**
 * Значок «i» рядом с цифрой сводки.
 *
 * Итоговые числа считаются по разным правилам — сумма остатков, число строк,
 * число документов, — и на глаз они не складываются. Без подсказки читатель
 * пытается их сложить, не получает сумму и перестаёт верить всей строке.
 */
export default function MetricHint({ text, label = 'Как считается' }) {
    return (
        <Tooltip content={text} positioning={{ placement: 'top' }} openDelay={200} contentProps={{ maxW: '340px' }}>
            <Box as="span" color="fg.subtle" cursor="help" display="inline-flex" aria-label={label}>
                <LuInfo size={13} />
            </Box>
        </Tooltip>
    );
}
