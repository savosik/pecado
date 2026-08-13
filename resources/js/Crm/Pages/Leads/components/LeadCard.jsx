import { useRef } from 'react';
import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { Badge, Box, HStack, IconButton, Text, VStack } from '@chakra-ui/react';
import { LuPencil, LuPhone, LuUser } from 'react-icons/lu';
import { Checkbox } from '@/components/ui/checkbox';
import { Tooltip } from '@/components/ui/tooltip';

const RUB = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 });

/**
 * Насколько палец может уехать, чтобы жест всё ещё считался кликом.
 * Совпадает с порогом активации сенсора на доске — иначе появилась бы щель,
 * в которой жест уже не драг, но ещё и не клик.
 */
const CLICK_SLOP = 6;

/**
 * Внешний вид карточки без поведения перетаскивания.
 *
 * Отдельно от `LeadCard`, потому что ровно то же самое рисуется в `DragOverlay`,
 * а второй `useSortable` на той же карточке ломает расчёт позиции.
 */
export function LeadCardView({ lead, dragging = false, selected = false, ...rest }) {
    const stale = (lead.days_on_stage ?? 0) >= 14;

    return (
        <Box
            borderWidth="1px"
            borderColor={selected ? 'colorPalette.solid' : 'border'}
            colorPalette={selected ? 'blue' : undefined}
            borderRadius="md"
            bg="bg"
            p={2}
            boxShadow={dragging ? 'lg' : undefined}
            _hover={{ borderColor: selected ? 'colorPalette.solid' : 'border.emphasized' }}
            {...rest}
        >
            <VStack align="stretch" gap={1}>
                {/* Место под кнопку в углу — иначе длинное имя уезжает под неё. */}
                <Text fontSize="sm" fontWeight="600" lineClamp={1} pr={5}>{lead.name}</Text>

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

                {lead.manager ? (
                    <HStack gap={1} color="fg.muted">
                        <LuUser size={10} style={{ flexShrink: 0 }} />
                        <Text fontSize="10px" lineClamp={1}>{lead.manager.name}</Text>
                    </HStack>
                ) : (
                    <Text fontSize="10px" color="orange.fg">Ничей — разобрать</Text>
                )}
            </VStack>
        </Box>
    );
}

/**
 * Карточка лида на доске.
 *
 * Компактная намеренно: колонок в воронке много, доска прокручивается
 * горизонтально, и каждая лишняя строка в карточке — это минус одна видимая
 * колонка. Всё остальное живёт в карточке лида, которая открывается по клику.
 */
export default function LeadCard({
    lead,
    onOpen,
    draggable = true,
    selectable = false,
    selected = false,
    onToggleSelect,
}) {
    const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
        id: lead.id,
        disabled: ! draggable,
    });

    // Клик и драг живут на одном элементе, поэтому «клик» распознаём сами:
    // после перетаскивания браузер всё равно присылает click, и без этой
    // проверки каждый перенос заканчивался бы открытием карточки.
    const origin = useRef(null);

    const onPointerDown = (event) => {
        origin.current = { x: event.clientX, y: event.clientY };
    };

    const onClick = (event) => {
        const start = origin.current;
        origin.current = null;

        if (! start) return;

        const moved = Math.abs(event.clientX - start.x) + Math.abs(event.clientY - start.y);

        if (moved <= CLICK_SLOP) onOpen?.(lead);
    };

    return (
        <Box
            position="relative"
            ref={setNodeRef}
            style={{ transform: CSS.Translate.toString(transform), transition }}
            css={{ '&:hover .lead-card-edit': { opacity: 1 } }}
        >
            {selectable && (
                // Поверх карточки, а не внутри неё: чекбокс не должен начинать
                // перетаскивание, поэтому он вне области с listeners.
                <Box position="absolute" top="6px" right="6px" zIndex={1}>
                    <Checkbox
                        size="sm"
                        checked={selected}
                        onCheckedChange={({ checked }) => onToggleSelect?.(lead, checked === true)}
                        aria-label={`Выбрать лида ${lead.name}`}
                    />
                </Box>
            )}

            {/* Карандаш вне области с listeners — иначе нажатие на него начинало бы
                перетаскивание. Виден всегда, а не только по наведению: открытие
                карточки кликом ниоткуда не следует, и его попросту не находят. */}
            {! selectable && (
                <Box
                    position="absolute"
                    top="4px"
                    right="4px"
                    zIndex={1}
                    className="lead-card-edit"
                    opacity={0.45}
                    transition="opacity 120ms"
                >
                    <Tooltip content="Открыть карточку лида" openDelay={400}>
                        <IconButton
                            size="2xs"
                            variant="ghost"
                            aria-label={`Открыть лида ${lead.name}`}
                            onClick={(event) => {
                                event.stopPropagation();
                                onOpen?.(lead);
                            }}
                        >
                            <LuPencil />
                        </IconButton>
                    </Tooltip>
                </Box>
            )}

            <LeadCardView
                lead={lead}
                selected={selected}
                opacity={isDragging ? 0.4 : 1}
                cursor={draggable ? 'grab' : 'pointer'}
                onPointerDown={onPointerDown}
                onClick={onClick}
                {...attributes}
                {...listeners}
            />
        </Box>
    );
}
