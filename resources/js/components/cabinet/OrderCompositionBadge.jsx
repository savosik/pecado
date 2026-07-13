import { Box, Flex, HStack, Stack, Text } from '@chakra-ui/react';
import { LuTriangleAlert, LuPlus, LuMinus, LuArrowRight } from 'react-icons/lu';
import {
    HoverCardRoot,
    HoverCardTrigger,
    HoverCardContent,
    HoverCardArrow,
} from '@/components/ui/hover-card';

/**
 * Значок «внимание» с количеством изменений товарного состава заказа.
 * При наведении раскрывает карточку с перечнем изменений: добавленные,
 * выбывшие и позиции с изменённым количеством (например, 7 → 6 шт).
 * Названия товаров, у которых есть slug, кликабельны — клик перехватывается
 * глобальным обработчиком в bootstrap.js и открывает QuickView-диалог товара
 * (карточка портируется в body, поэтому вложенный <a> не конфликтует с внешней
 * ссылкой-карточкой заказа).
 */
export default function OrderCompositionBadge({ changes }) {
    const count = Number(changes?.count ?? 0);
    if (!changes || count <= 0) return null;

    const added = changes.added ?? [];
    const removed = changes.removed ?? [];
    const changed = changes.changed ?? [];

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
            <HoverCardContent maxW="360px">
                <HoverCardArrow />
                <Stack gap="2.5">
                    <HStack gap="1.5">
                        <Box color="orange.500"><LuTriangleAlert size={14} /></Box>
                        <Text fontWeight="700" fontSize="sm">Изменения состава заказа</Text>
                    </HStack>

                    {added.length > 0 && (
                        <Stack gap="1">
                            <SectionLabel>Добавлены</SectionLabel>
                            {added.map((item, i) => (
                                <HStack key={`add-${i}`} gap="1.5" align="start">
                                    <Box color="green.500" mt="0.5" flexShrink="0"><LuPlus size={13} /></Box>
                                    <CompositionItem item={item} suffix={qtySuffix(item.qty)} />
                                </HStack>
                            ))}
                        </Stack>
                    )}

                    {removed.length > 0 && (
                        <Stack gap="1">
                            <SectionLabel>Выбыли</SectionLabel>
                            {removed.map((item, i) => (
                                <HStack key={`rem-${i}`} gap="1.5" align="start">
                                    <Box color="red.500" mt="0.5" flexShrink="0"><LuMinus size={13} /></Box>
                                    <CompositionItem item={item} strikethrough suffix={qtySuffix(item.qty, 'было ')} />
                                </HStack>
                            ))}
                        </Stack>
                    )}

                    {changed.length > 0 && (
                        <Stack gap="1">
                            <SectionLabel>Изменено количество</SectionLabel>
                            {changed.map((item, i) => (
                                <HStack key={`chg-${i}`} gap="1.5" align="start">
                                    <Box color="orange.500" mt="0.5" flexShrink="0"><LuArrowRight size={13} /></Box>
                                    <CompositionItem
                                        item={item}
                                        suffix={
                                            <Box as="span" whiteSpace="nowrap">
                                                {item.from}&nbsp;→&nbsp;
                                                <Box
                                                    as="span"
                                                    fontWeight="600"
                                                    color={item.to < item.from ? 'red.500' : 'green.600'}
                                                    _dark={{ color: item.to < item.from ? 'red.300' : 'green.300' }}
                                                >
                                                    {item.to}
                                                </Box>
                                                &nbsp;шт
                                            </Box>
                                        }
                                    />
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

function SectionLabel({ children }) {
    return (
        <Text fontSize="2xs" fontWeight="600" color="fg.muted" textTransform="uppercase" letterSpacing="wide">
            {children}
        </Text>
    );
}

/** Хвост с количеством: «(2 шт)» или «(было 3 шт)». */
function qtySuffix(qty, prefix = '') {
    const n = Number(qty ?? 0);
    if (n <= 0) return null;
    return (
        <Box as="span" color="fg.muted" whiteSpace="nowrap">
            ({prefix}{n}&nbsp;шт)
        </Box>
    );
}

/**
 * Название позиции + опциональный хвост (количество). При наличии slug —
 * кликабельная ссылка на товар (QuickView), иначе просто текст (товар
 * отсутствует в каталоге).
 */
function CompositionItem({ item, strikethrough = false, suffix = null }) {
    const name = item?.name || '—';

    const nameNode = item?.slug ? (
        <Box
            as="a"
            href={`/products/${item.slug}`}
            fontWeight="500"
            color="pecado.600"
            textDecoration={strikethrough ? 'line-through' : undefined}
            _hover={{ textDecoration: 'underline', color: 'pecado.700' }}
            _dark={{ color: 'pecado.300', _hover: { color: 'pecado.200' } }}
        >
            {name}
        </Box>
    ) : (
        <Box as="span" color="fg.muted" textDecoration={strikethrough ? 'line-through' : undefined}>
            {name}
        </Box>
    );

    return (
        <Text fontSize="sm" lineHeight="1.35">
            {nameNode}
            {suffix ? <> {suffix}</> : null}
        </Text>
    );
}
