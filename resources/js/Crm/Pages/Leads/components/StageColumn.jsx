import { useDroppable } from '@dnd-kit/core';
import { SortableContext, verticalListSortingStrategy } from '@dnd-kit/sortable';
import { Box, Card, HStack, Text, VStack } from '@chakra-ui/react';

/**
 * Колонка воронки — зона приёма карточек.
 *
 * Существует ради `useDroppable`: без него колонка не участвует в определении
 * цели, и перенести лида можно было только попав точно в другую карточку —
 * то есть в пустую колонку никогда. `SortableContext` этого не заменяет,
 * он отвечает за порядок внутри списка, а не за приём извне.
 */
export default function StageColumn({ stage, leads, children }) {
    const { setNodeRef, isOver } = useDroppable({ id: `stage-${stage.id}` });

    return (
        <Box minW="240px" maxW="240px">
            <Card.Root
                size="sm"
                bg={isOver ? 'bg.emphasized' : 'bg.subtle'}
                borderWidth="1px"
                borderColor={isOver ? 'colorPalette.solid' : 'transparent'}
                colorPalette={stage.color || 'gray'}
                transition="background-color 120ms, border-color 120ms"
            >
                <Card.Body p={2}>
                    <HStack justify="space-between" mb={2}>
                        <HStack gap={1.5} minW={0}>
                            <Box
                                w="8px"
                                h="8px"
                                borderRadius="full"
                                bg="colorPalette.solid"
                                flexShrink={0}
                            />
                            <Text fontSize="xs" fontWeight="700" lineClamp={1}>{stage.name}</Text>
                        </HStack>
                        <Text fontSize="xs" color="fg.muted">{leads.length}</Text>
                    </HStack>

                    {/* Приёмник — весь список, а не отдельные карточки: пустая
                        колонка обязана принимать перетаскивание так же, как полная. */}
                    <SortableContext
                        items={leads.map((lead) => lead.id)}
                        strategy={verticalListSortingStrategy}
                    >
                        <VStack ref={setNodeRef} align="stretch" gap={2} minH="80px">
                            {children}

                            {leads.length === 0 && (
                                <Box
                                    borderWidth="1px"
                                    borderStyle="dashed"
                                    borderColor="border"
                                    borderRadius="md"
                                    py={4}
                                    textAlign="center"
                                >
                                    <Text fontSize="11px" color="fg.muted">Перетащите лида сюда</Text>
                                </Box>
                            )}
                        </VStack>
                    </SortableContext>
                </Card.Body>
            </Card.Root>
        </Box>
    );
}
