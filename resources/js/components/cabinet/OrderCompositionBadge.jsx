import { Box, Flex, HStack, Stack, Text } from '@chakra-ui/react';
import { LuTriangleAlert, LuPlus, LuMinus } from 'react-icons/lu';
import {
    HoverCardRoot,
    HoverCardTrigger,
    HoverCardContent,
    HoverCardArrow,
} from '@/components/ui/hover-card';

/**
 * Значок «внимание» с количеством изменений товарного состава заказа.
 * При наведении раскрывает карточку с перечнем добавленных и выбывших
 * позиций. Названия товаров, у которых есть slug, кликабельны — клик
 * перехватывается глобальным обработчиком в bootstrap.js и открывает
 * QuickView-диалог товара (карточка портируется в body, поэтому вложенный
 * <a> не конфликтует с внешней ссылкой-карточкой заказа).
 */
export default function OrderCompositionBadge({ changes }) {
    const count = Number(changes?.count ?? 0);
    if (!changes || count <= 0) return null;

    const added = changes.added ?? [];
    const removed = changes.removed ?? [];

    return (
        <HoverCardRoot size="sm" openDelay={150} closeDelay={100} positioning={{ placement: 'top' }}>
            <HoverCardTrigger asChild>
                <Flex
                    as="span"
                    align="center"
                    gap="1"
                    px="1.5"
                    py="0.5"
                    borderRadius="full"
                    bg="orange.50"
                    color="orange.700"
                    border="1px solid"
                    borderColor="orange.200"
                    cursor="help"
                    flexShrink="0"
                    _dark={{ bg: 'orange.950', color: 'orange.200', borderColor: 'orange.800' }}
                    aria-label={`Изменения состава заказа: ${count}`}
                    onClick={(e) => {
                        // Не даём клику по значку увести на страницу заказа —
                        // взаимодействие сугубо по наведению.
                        e.preventDefault();
                        e.stopPropagation();
                    }}
                >
                    <LuTriangleAlert size={12} />
                    <Text as="span" fontSize="2xs" fontWeight="700" lineHeight="1">
                        {count}
                    </Text>
                </Flex>
            </HoverCardTrigger>
            <HoverCardContent maxW="340px">
                <HoverCardArrow />
                <Stack gap="2.5">
                    <HStack gap="1.5">
                        <Box color="orange.500"><LuTriangleAlert size={14} /></Box>
                        <Text fontWeight="700" fontSize="sm">Изменения состава заказа</Text>
                    </HStack>

                    {added.length > 0 && (
                        <Stack gap="1">
                            <Text fontSize="2xs" fontWeight="600" color="fg.muted" textTransform="uppercase" letterSpacing="wide">
                                Добавлены
                            </Text>
                            {added.map((item, i) => (
                                <HStack key={`add-${i}`} gap="1.5" align="start">
                                    <Box color="green.500" mt="0.5" flexShrink="0"><LuPlus size={13} /></Box>
                                    <CompositionItem item={item} />
                                </HStack>
                            ))}
                        </Stack>
                    )}

                    {removed.length > 0 && (
                        <Stack gap="1">
                            <Text fontSize="2xs" fontWeight="600" color="fg.muted" textTransform="uppercase" letterSpacing="wide">
                                Выбыли
                            </Text>
                            {removed.map((item, i) => (
                                <HStack key={`rem-${i}`} gap="1.5" align="start">
                                    <Box color="red.500" mt="0.5" flexShrink="0"><LuMinus size={13} /></Box>
                                    <CompositionItem item={item} strikethrough />
                                </HStack>
                            ))}
                        </Stack>
                    )}

                    <Text fontSize="2xs" color="fg.subtle">
                        Нажмите на товар, чтобы открыть быстрый просмотр
                    </Text>
                </Stack>
            </HoverCardContent>
        </HoverCardRoot>
    );
}

/**
 * Название позиции. При наличии slug — кликабельная ссылка на товар
 * (QuickView), иначе просто текст (товар отсутствует в каталоге).
 */
function CompositionItem({ item, strikethrough = false }) {
    const name = item?.name || '—';

    if (!item?.slug) {
        return (
            <Text fontSize="sm" color="fg.muted" textDecoration={strikethrough ? 'line-through' : undefined}>
                {name}
            </Text>
        );
    }

    return (
        <Box
            as="a"
            href={`/products/${item.slug}`}
            fontSize="sm"
            fontWeight="500"
            color="pecado.600"
            textDecoration={strikethrough ? 'line-through' : undefined}
            _hover={{ textDecoration: 'underline', color: 'pecado.700' }}
            _dark={{ color: 'pecado.300', _hover: { color: 'pecado.200' } }}
        >
            {name}
        </Box>
    );
}
