import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Badge, Box, HStack, Text, VStack } from '@chakra-ui/react';
import { LuPhone, LuUser } from 'react-icons/lu';

const RUB = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 });

/**
 * Карточка лида на доске.
 *
 * Компактная намеренно: колонок в воронке много, доска прокручивается
 * горизонтально, и каждая лишняя строка в карточке — это минус одна видимая
 * колонка. Всё остальное живёт в карточке лида, которая открывается по клику.
 */
export default function LeadCard({ lead, onOpen, draggable = true }) {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
        id: lead.id,
        disabled: ! draggable,
    });

    const stale = (lead.days_on_stage ?? 0) >= 14;

    return (
        <Box
            ref={setNodeRef}
            style={{ transform: CSS.Translate.toString(transform), transition }}
            opacity={isDragging ? 0.4 : 1}
            borderWidth="1px"
            borderRadius="md"
            bg="bg"
            p={2}
            cursor={draggable ? 'grab' : 'pointer'}
            _hover={{ borderColor: 'border.emphasized' }}
            onClick={() => onOpen?.(lead)}
            {...attributes}
            {...listeners}
        >
            <VStack align="stretch" gap={1}>
                <Text fontSize="sm" fontWeight="600" lineClamp={1}>{lead.name}</Text>

                {lead.company_name && (
                    <Text fontSize="11px" color="fg.muted" lineClamp={1}>{lead.company_name}</Text>
                )}

                {lead.contact && (
                    <HStack gap={1} color="fg.muted">
                        <LuPhone size={10} style={{ flexShrink: 0 }} />
                        <Text fontSize="11px" lineClamp={1}>{lead.contact}</Text>
                    </HStack>
                )}

                <HStack gap={2} justify="space-between">
                    {lead.qualified_amount ? (
                        <Text fontSize="11px" fontWeight="medium">{RUB.format(lead.qualified_amount)} ₽</Text>
                    ) : <span />}

                    {lead.days_on_stage !== null && (
                        <Badge size="sm" variant="subtle" colorPalette={stale ? 'red' : 'gray'}>
                            {lead.days_on_stage} дн.
                        </Badge>
                    )}
                </HStack>

                {lead.manager && (
                    <HStack gap={1} color="fg.muted">
                        <LuUser size={10} style={{ flexShrink: 0 }} />
                        <Text fontSize="10px" lineClamp={1}>{lead.manager.name}</Text>
                    </HStack>
                )}
            </VStack>
        </Box>
    );
}
