import { Badge, Box, HStack, Text, VStack } from '@chakra-ui/react';
import { LuPhoneIncoming, LuPhoneOutgoing } from 'react-icons/lu';

/**
 * Звонок в ленте.
 *
 * Итог разговора виден бейджем даже без текста: «не ответил» — это тоже работа
 * с клиентом, и в хронологии она должна остаться. Иначе менеджер, честно
 * набиравший пять раз, выглядит так же, как тот, кто не набирал вовсе.
 */
export default function CallFeedEntry({ entry }) {
    const call = entry.call;

    if (!call) return null;

    const Icon = call.direction === 'incoming' ? LuPhoneIncoming : LuPhoneOutgoing;

    return (
        <Box borderWidth="1px" borderRadius="md" p={3}>
            <VStack align="stretch" gap={2}>
                <HStack gap={2} flexWrap="wrap">
                    <HStack gap={1} color={`${call.direction_color}.fg`}>
                        <Icon size={14} />
                        <Badge colorPalette={call.direction_color} variant="subtle" size="sm">
                            {call.direction_label}
                        </Badge>
                    </HStack>
                    <Badge colorPalette={call.result_color} variant="outline" size="sm">
                        {call.result_label}
                    </Badge>
                    <Text fontSize="xs" color="fg.muted">
                        {entry.author?.name}, {entry.happened_at_label}
                    </Text>
                    {call.duration_label && (
                        <Text fontSize="xs" color="fg.muted">· {call.duration_label}</Text>
                    )}
                </HStack>

                {call.contact_name && (
                    <Text fontSize="xs" color="fg.muted">Говорили с: {call.contact_name}</Text>
                )}

                {call.summary && (
                    <Text fontSize="sm" whiteSpace="pre-wrap">{call.summary}</Text>
                )}

                {call.follow_up_task_id && (
                    <Text fontSize="xs" color="fg.muted">Поставлен следующий шаг</Text>
                )}
            </VStack>
        </Box>
    );
}
