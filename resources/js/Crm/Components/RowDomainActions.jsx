import { HStack, IconButton } from '@chakra-ui/react';
import { LuMessageSquarePlus, LuTarget } from 'react-icons/lu';
import { Tooltip } from '@/components/ui/tooltip';

/**
 * Действия по строке таблицы: поставить задачу, оставить комментарий.
 *
 * Нужны там, где на список смотрят вслух — на брифинге по плану. Уход
 * в карточку ради одной записи означает потерянное место в разговоре,
 * поэтому запись делается из строки.
 *
 * Кнопка без обработчика не рисуется вовсе, а не показывается неактивной:
 * серая кнопка занимает место и обещает то, чего не будет.
 */
export default function RowDomainActions({ onTask, onComment }) {
    if (! onTask && ! onComment) {
        return null;
    }

    return (
        <HStack gap={1} justify="end">
            {onTask && (
                <Tooltip content="Поставить задачу" openDelay={400}>
                    <IconButton size="xs" variant="ghost" aria-label="Поставить задачу" onClick={onTask}>
                        <LuTarget />
                    </IconButton>
                </Tooltip>
            )}
            {onComment && (
                <Tooltip content="Оставить комментарий" openDelay={400}>
                    <IconButton size="xs" variant="ghost" aria-label="Оставить комментарий" onClick={onComment}>
                        <LuMessageSquarePlus />
                    </IconButton>
                </Tooltip>
            )}
        </HStack>
    );
}
