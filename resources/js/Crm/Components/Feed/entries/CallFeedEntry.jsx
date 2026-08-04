import { Badge, HStack, Text } from '@chakra-ui/react';
import FeedEntryShell from '../FeedEntryShell';

/**
 * Звонок в ленте.
 *
 * Итог виден бейджем даже без текста: «не ответил» — тоже работа с клиентом,
 * и в хронологии она должна остаться. Иначе менеджер, честно набиравший пять раз,
 * выглядит так же, как тот, кто не набирал вовсе.
 */
export default function CallFeedEntry({ entry }) {
    const call = entry.call;

    if (!call) return null;

    const badges = (
        <>
            <Badge colorPalette={call.direction_color} variant="subtle" size="sm">
                {call.direction_label}
            </Badge>
            <Badge colorPalette={call.result_color} variant="outline" size="sm">
                {call.result_label}
            </Badge>
        </>
    );

    return (
        <FeedEntryShell
            type="call"
            author={entry.author?.name}
            time={entry.happened_at_label}
            badges={badges}
            incoming={call.direction === 'incoming'}
            // Неудачная попытка приглушена: она в ленте нужна, но внимания
            // на себя оттягивать не должна.
            muted={call.result === 'no_answer' || call.result === 'busy'}
        >
            <HStack gap={3} flexWrap="wrap">
                {call.contact_name && (
                    <Text fontSize="xs" color="fg.muted">Говорили с: {call.contact_name}</Text>
                )}
                {call.duration_label && (
                    <Text fontSize="xs" color="fg.muted">Длительность: {call.duration_label}</Text>
                )}
                {call.follow_up_task_id && (
                    <Badge colorPalette="purple" variant="outline" size="sm">Поставлен следующий шаг</Badge>
                )}
            </HStack>

            {call.summary && <Text fontSize="sm" whiteSpace="pre-wrap">{call.summary}</Text>}
        </FeedEntryShell>
    );
}
