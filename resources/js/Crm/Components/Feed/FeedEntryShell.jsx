import { Badge, Box, HStack, Text, VStack } from '@chakra-ui/react';
import {
    LuFileText,
    LuMail,
    LuMessageSquare,
    LuPhoneIncoming,
    LuPhoneOutgoing,
    LuTarget,
    LuTruck,
} from 'react-icons/lu';

/**
 * Визуальный код ленты: у каждого типа записи свой цвет, иконка и подпись.
 *
 * Один словарь на всю ленту, а не набор палитр по компонентам: цвет типа — это
 * язык, на котором лента читается с одного взгляда, и он обязан быть одинаковым
 * в записи, в фильтре и в счётчике. Разъехавшиеся палитры превращают ленту
 * обратно в сплошную простыню.
 */
export const ENTRY_STYLE = {
    comment: { palette: 'gray', label: 'Комментарий', icon: LuMessageSquare },
    task: { palette: 'purple', label: 'Задача', icon: LuTarget },
    email: { palette: 'teal', label: 'Письмо', icon: LuMail },
    call: { palette: 'cyan', label: 'Звонок', icon: LuPhoneOutgoing },
    order: { palette: 'blue', label: 'Заказ', icon: LuFileText },
    shipment: { palette: 'green', label: 'Реализация', icon: LuTruck },
};

/**
 * Единая рамка записи ленты.
 *
 * Раньше каждый тип рисовал себя сам, и в итоге у задачи подпись «поставил такой-то»
 * висела снаружи рамки, у письма — внутри, а комментарий отличался от документа только
 * текстом. Общая оболочка задаёт один каркас: цветная полоса слева, иконка, бейдж типа,
 * автор и время в шапке, действия справа, тело ниже.
 *
 * @param {string} type — ключ ENTRY_STYLE
 * @param {string|null} author — кто оставил запись; null у документов из 1С
 * @param {string|null} time — метка времени
 * @param {ReactNode} title — заголовок записи (тема письма, название задачи, номер документа)
 * @param {ReactNode} badges — дополнительные бейджи в шапке (статус, срок)
 * @param {ReactNode} actions — кнопки справа
 * @param {boolean} pinned — закреплённая запись
 * @param {boolean} muted — приглушить (выполненная задача, отменённая)
 * @param {string|null} accent — переопределить палитру (входящий звонок, просроченная задача)
 */
export default function FeedEntryShell({
    type,
    author = null,
    time = null,
    title = null,
    badges = null,
    actions = null,
    pinned = false,
    muted = false,
    accent = null,
    incoming = false,
    children,
}) {
    const style = ENTRY_STYLE[type] || ENTRY_STYLE.comment;
    const palette = accent || style.palette;
    const Icon = type === 'call' && incoming ? LuPhoneIncoming : style.icon;

    return (
        <Box
            borderWidth="1px"
            borderColor={pinned ? 'yellow.400' : 'border.muted'}
            // Цветная полоса слева — главный различитель: она читается боковым
            // зрением при прокрутке, в отличие от бейджа, который надо прочесть.
            borderLeftWidth="3px"
            borderLeftColor={`${palette}.solid`}
            borderRadius="md"
            bg={pinned ? 'yellow.50' : 'bg.panel'}
            _dark={{ bg: pinned ? 'yellow.950' : 'bg.panel' }}
            px={4}
            py={3.5}
            opacity={muted ? 0.7 : 1}
            transition="border-color 0.15s"
            _hover={{ borderColor: pinned ? 'yellow.400' : 'border' }}
        >
            <VStack align="stretch" gap={2.5}>
                <HStack justify="space-between" align="start" gap={2}>
                    <HStack gap={2} flexWrap="wrap" flex="1" minW="0">
                        <HStack gap={1.5} color={`${palette}.fg`} flexShrink={0}>
                            <Icon size={14} />
                            <Badge colorPalette={palette} variant="subtle" size="sm">
                                {style.label}
                            </Badge>
                        </HStack>

                        {badges}

                        <Text fontSize="xs" color="fg.muted">
                            {author ? `${author}, ` : ''}{time}
                        </Text>

                        {pinned && (
                            <Badge colorPalette="yellow" variant="subtle" size="sm">Закреплено</Badge>
                        )}
                    </HStack>

                    {actions && <HStack gap={1} flexShrink={0}>{actions}</HStack>}
                </HStack>

                {title && (
                    <Text fontSize="sm" fontWeight="600" lineHeight="1.35">{title}</Text>
                )}

                {children}
            </VStack>
        </Box>
    );
}
