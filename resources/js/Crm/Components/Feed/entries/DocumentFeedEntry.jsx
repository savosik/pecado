import { Badge, Box, HStack, Text, VStack } from '@chakra-ui/react';
import { LuFileText, LuTruck } from 'react-icons/lu';

/**
 * Заказ или реализация в ленте.
 *
 * Одной формой на оба типа: для менеджера это события одной природы — «клиент
 * заказал» и «мы отгрузили», и разные карточки только мешали бы читать хронологию.
 *
 * Документ приходит из 1С: автора нет, править нечего — визуально он тише
 * записей менеджеров, чтобы лента читалась как разговор с вкраплениями фактов.
 */
export default function DocumentFeedEntry({ entry }) {
    const isOrder = entry.type === 'order';
    const Icon = isOrder ? LuFileText : LuTruck;

    return (
        <HStack
            align="start"
            gap={3}
            borderWidth="1px"
            borderColor="border.muted"
            borderRadius="md"
            bg="bg.subtle"
            px={3}
            py={2}
        >
            <Box color="fg.muted" pt={0.5}><Icon size={15} /></Box>

            <VStack align="stretch" gap={1} flex="1" minW="0">
                <HStack gap={2} flexWrap="wrap">
                    <Badge colorPalette={isOrder ? 'blue' : 'teal'} variant="subtle" size="sm">
                        {isOrder ? 'Заказ' : 'Реализация'}
                    </Badge>
                    {entry.entity?.url ? (
                        <Text fontSize="sm" fontWeight="600">
                            <a href={entry.entity.url}>{entry.title}</a>
                        </Text>
                    ) : (
                        <Text fontSize="sm" fontWeight="600">{entry.title}</Text>
                    )}
                    {entry.status_label && (
                        <Badge colorPalette={entry.status_color || 'gray'} variant="outline" size="sm">
                            {entry.status_label}
                        </Badge>
                    )}
                </HStack>

                <HStack gap={3} flexWrap="wrap">
                    <Text fontSize="sm" fontWeight="600">{entry.amount_label}</Text>
                    {entry.items_count > 0 && (
                        <Text fontSize="xs" color="fg.muted">позиций: {entry.items_count}</Text>
                    )}
                    <Text fontSize="xs" color="fg.muted">{entry.happened_at_label}</Text>
                </HStack>

                {entry.excerpt && (
                    <Text fontSize="xs" color="fg.muted" whiteSpace="pre-wrap">{entry.excerpt}</Text>
                )}
            </VStack>
        </HStack>
    );
}
