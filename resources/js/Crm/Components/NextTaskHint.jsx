import { HStack, Text } from '@chakra-ui/react';
import { LuTarget } from 'react-icons/lu';
import { Badge } from '@chakra-ui/react';
import { Tooltip } from '@/components/ui/tooltip';

/**
 * Ближайшая задача по партнёру — компактная форма для строки таблицы.
 *
 * Состояние срока приходит с сервера готовым (`due_state`): разбирать даты
 * в JSX означало бы держать часовой пояс сервера в двух местах.
 */
const TONE = {
    overdue: { palette: 'red', label: 'просрочена' },
    today: { palette: 'orange', label: 'сегодня' },
    tomorrow: { palette: 'yellow', label: 'завтра' },
    week: { palette: 'blue', label: 'на неделе' },
    later: { palette: 'gray', label: 'позже' },
    none: { palette: 'gray', label: 'без срока' },
};

export default function NextTaskHint({ task }) {
    if (! task) {
        return <Text fontSize="sm" color="fg.muted">—</Text>;
    }

    const tone = TONE[task.due_state] ?? TONE.none;

    return (
        <Tooltip
            content={`${task.title}${task.due_at_full ? ` — до ${task.due_at_full}` : ''}. Исполнитель: ${task.assignee_name}.`}
            openDelay={400}
        >
            <HStack gap={1.5} maxW="240px">
                <LuTarget size={12} style={{ flexShrink: 0 }} />
                <Text fontSize="xs" lineClamp={1}>{task.title}</Text>
                <Badge size="sm" colorPalette={tone.palette} variant="subtle" flexShrink={0}>
                    {task.due_at_label ?? tone.label}
                </Badge>
            </HStack>
        </Tooltip>
    );
}
