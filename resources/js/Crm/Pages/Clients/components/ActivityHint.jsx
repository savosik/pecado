import { HStack, Text } from '@chakra-ui/react';
import { LuMessageSquare, LuTarget } from 'react-icons/lu';
import { Tooltip } from '@/components/ui/tooltip';

/**
 * Мелкая строка под именем партнёра: следующий шаг или последняя запись.
 *
 * Приоритет у задачи — что нужно сделать, важнее того, что записали вчера.
 * Текст обрезается одной строкой, полный виден в подсказке: колонка не должна
 * растягивать таблицу под чужой комментарий на три абзаца.
 *
 * @param {{kind: 'task'|'comment'|'none', text: string|null, at_label: string|null}} activity
 */
export default function ActivityHint({ activity }) {
    if (!activity || activity.kind === 'none' || !activity.text) return null;

    const isTask = activity.kind === 'task';
    const Icon = isTask ? LuTarget : LuMessageSquare;

    return (
        <Tooltip
            content={`${isTask ? 'Ближайшая задача' : 'Последний комментарий'}${activity.at_label ? ` (${activity.at_label})` : ''}: ${activity.text}`}
            openDelay={400}
        >
            <HStack gap={1} color="fg.muted" maxW="320px">
                <Icon size={11} style={{ flexShrink: 0 }} />
                <Text fontSize="11px" lineClamp={1}>{activity.text}</Text>
            </HStack>
        </Tooltip>
    );
}
