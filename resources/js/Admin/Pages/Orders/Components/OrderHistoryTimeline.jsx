import { useState } from 'react';
import { Badge, Box, Button, Card, HStack, Stack, Text } from '@chakra-ui/react';
import {
    LuArrowRightLeft,
    LuChevronDown,
    LuChevronUp,
    LuClock,
    LuMessageSquare,
    LuMinus,
    LuPencilLine,
    LuPlus,
    LuTrendingDown,
    LuTrendingUp,
    LuUser,
} from 'react-icons/lu';
import { buildOrderTimeline } from '@/utils/orderTimeline';

const SOURCE_LABELS = {
    erp: '1С',
    admin: 'Админ',
    system: 'Система',
};

const SOURCE_COLORS = {
    erp: 'blue',
    admin: 'purple',
    system: 'gray',
};

const fmt = (v) =>
    Number(v || 0).toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export function OrderHistoryTimeline({ statusHistories = [], changeLogs = [] }) {
    const entries = buildOrderTimeline(statusHistories, changeLogs);

    return (
        <Card.Root>
            <Card.Header>
                <HStack gap="2">
                    <LuClock size={18} />
                    <Text fontWeight="semibold" fontSize="lg">История изменений</Text>
                    <Badge variant="subtle" colorPalette="gray" fontSize="2xs">
                        {entries.length}
                    </Badge>
                </HStack>
            </Card.Header>
            <Card.Body>
                {entries.length === 0 ? (
                    <Text color="gray.500">История изменений пуста</Text>
                ) : (
                    <Box position="relative">
                        <Box
                            position="absolute"
                            left="18px"
                            top="20px"
                            bottom="20px"
                            width="2px"
                            bg="gray.200"
                            _dark={{ bg: 'gray.600' }}
                        />
                        <Stack gap={5}>
                            {entries.map((entry, index) => (
                                <Box key={entry.id} position="relative" pl="50px">
                                    <Box
                                        position="absolute"
                                        left="10px"
                                        top="2px"
                                        width="18px"
                                        height="18px"
                                        borderRadius="full"
                                        bg={index === 0 ? indicatorColor(entry.type) : 'gray.300'}
                                        border="3px solid"
                                        borderColor="white"

                                        zIndex={1}
                                    />
                                    {entry.type === 'status_changed' && <StatusEntry entry={entry} />}
                                    {entry.type === 'items_updated' && <ItemsChangedEntry entry={entry} />}
                                    {entry.type === 'attributes_updated' && <AttributesChangedEntry entry={entry} />}
                                </Box>
                            ))}
                        </Stack>
                    </Box>
                )}
            </Card.Body>
        </Card.Root>
    );
}

function indicatorColor(type) {
    if (type === 'items_updated') return 'orange.500';
    if (type === 'attributes_updated') return 'purple.500';
    return 'blue.500';
}

function SourceBadge({ source, userName }) {
    if (!source) return null;
    const label = SOURCE_LABELS[source] ?? source;
    const color = SOURCE_COLORS[source] ?? 'gray';
    return (
        <HStack gap="1" fontSize="xs" color="fg.muted">
            <Badge variant="subtle" colorPalette={color} fontSize="2xs">{label}</Badge>
            {userName && (
                <HStack gap="0.5">
                    <LuUser size={12} />
                    <Text>{userName}</Text>
                </HStack>
            )}
        </HStack>
    );
}

function StatusEntry({ entry }) {
    const h = entry.data;
    return (
        <Stack gap={1}>
            <HStack gap="1.5">
                <LuArrowRightLeft size={14} style={{ color: 'var(--chakra-colors-blue-500)', flexShrink: 0 }} />
                <Text fontWeight="500" fontSize="sm">
                    {h.old_status ? (
                        <>
                            <Box as="span" color="orange.600">{h.old_status_label}</Box>
                            {' → '}
                            <Box as="span" color="green.600">{h.new_status_label}</Box>
                        </>
                    ) : (
                        <>
                            Создан со статусом{' '}
                            <Box as="span" color="blue.600" fontWeight="600">{h.new_status_label}</Box>
                        </>
                    )}
                </Text>
            </HStack>

            <HStack fontSize="xs" color="fg.muted" gap="1">
                <LuUser size={12} />
                <Text>{h.user_name}</Text>
                <Text>•</Text>
                <Text>{h.created_at_human}</Text>
                <Text>•</Text>
                <Text>{h.created_at}</Text>
            </HStack>

            {h.comment && (
                <Box
                    fontSize="sm"
                    bg="gray.50"
                    _dark={{ bg: 'gray.700' }}
                    p={3}
                    borderRadius="md"
                    borderLeftWidth="3px"
                    borderLeftColor="blue.400"
                    mt={1}
                >
                    <HStack align="start" gap={2}>
                        <LuMessageSquare size={14} style={{ marginTop: '2px', flexShrink: 0 }} />
                        <Text>{h.comment}</Text>
                    </HStack>
                </Box>
            )}
        </Stack>
    );
}

function ItemsChangedEntry({ entry }) {
    const [expanded, setExpanded] = useState(false);
    const c = entry.data;
    const changes = c.changes || {};
    const hasDetails = (changes.added?.length > 0) || (changes.removed?.length > 0) || (changes.modified?.length > 0);

    return (
        <Stack gap={1}>
            <HStack gap="1.5">
                <LuPencilLine size={14} style={{ color: 'var(--chakra-colors-orange-500)', flexShrink: 0 }} />
                <Text fontWeight="600" fontSize="sm" color="orange.700" _dark={{ color: 'orange.300' }}>
                    Состав заказа изменён
                </Text>
                <SourceBadge source={c.source} userName={c.user_name} />
            </HStack>

            {c.old_total != null && c.new_total != null && Math.abs(c.old_total - c.new_total) > 0.01 && (
                <HStack fontSize="sm" gap="1.5">
                    {c.new_total < c.old_total
                        ? <LuTrendingDown size={14} style={{ color: 'var(--chakra-colors-red-500)' }} />
                        : <LuTrendingUp size={14} style={{ color: 'var(--chakra-colors-green-500)' }} />
                    }
                    <Text color="fg.muted">Сумма:</Text>
                    <Text fontWeight="600" textDecoration="line-through" color="fg.muted">{fmt(c.old_total)} ₽</Text>
                    <Text>→</Text>
                    <Text fontWeight="700" color={c.new_total < c.old_total ? 'red.600' : 'green.600'}>
                        {fmt(c.new_total)} ₽
                    </Text>
                </HStack>
            )}

            <HStack fontSize="xs" color="fg.muted" gap="1">
                <LuClock size={12} />
                <Text>{c.created_at}</Text>
                <Text>•</Text>
                <Text>{c.created_at_human}</Text>
            </HStack>

            {hasDetails && (
                <Box mt={1}>
                    <Button
                        variant="ghost"
                        size="xs"
                        onClick={() => setExpanded(!expanded)}
                    >
                        {expanded ? <LuChevronUp size={14} /> : <LuChevronDown size={14} />}
                        {expanded ? 'Скрыть подробности' : 'Подробности'}
                    </Button>

                    {expanded && (
                        <Box mt={2} bg="gray.50" _dark={{ bg: 'gray.700' }} borderRadius="lg" p={3} fontSize="sm">
                            <Stack gap={2}>
                                {changes.added?.map((item, i) => (
                                    <HStack key={`add-${i}`} gap="2" align="start">
                                        <Box color="green.500" mt="1"><LuPlus size={14} /></Box>
                                        <Text>
                                            <Box as="span" fontWeight="600">«{item.product_name}»</Box>
                                            {' — '}добавлена позиция: кол-во {item.quantity}, цена {fmt(item.price)} ₽
                                        </Text>
                                    </HStack>
                                ))}

                                {changes.removed?.map((item, i) => (
                                    <HStack key={`rem-${i}`} gap="2" align="start">
                                        <Box color="red.500" mt="1"><LuMinus size={14} /></Box>
                                        <Text>
                                            <Box as="span" fontWeight="600" textDecoration="line-through">«{item.product_name}»</Box>
                                            {' — '}удалена из заказа
                                        </Text>
                                    </HStack>
                                ))}

                                {changes.modified?.map((item, i) => (
                                    <HStack key={`mod-${i}`} gap="2" align="start">
                                        <Box color="orange.500" mt="1"><LuPencilLine size={14} /></Box>
                                        <Box>
                                            <Text fontWeight="600">«{item.product_name}»</Text>
                                            <Stack gap={0.5} ml="2" mt="0.5">
                                                {item.changes?.quantity && (
                                                    <Text fontSize="xs" color="fg.muted">
                                                        Кол-во: {item.changes.quantity.old} → {item.changes.quantity.new}
                                                    </Text>
                                                )}
                                                {item.changes?.discount_percent && (
                                                    <Text fontSize="xs" color="fg.muted">
                                                        Корректировка цены: {item.changes.discount_percent.old}% → {item.changes.discount_percent.new}%
                                                    </Text>
                                                )}
                                                {item.changes?.final_price && (
                                                    <Text fontSize="xs" color="fg.muted">
                                                        Цена: {fmt(item.changes.final_price.old)} → {fmt(item.changes.final_price.new)} ₽
                                                    </Text>
                                                )}
                                                {item.changes?.base_price && (
                                                    <Text fontSize="xs" color="fg.muted">
                                                        Базовая цена: {fmt(item.changes.base_price.old)} → {fmt(item.changes.base_price.new)} ₽
                                                    </Text>
                                                )}
                                            </Stack>
                                        </Box>
                                    </HStack>
                                ))}
                            </Stack>
                        </Box>
                    )}
                </Box>
            )}
        </Stack>
    );
}

function AttributesChangedEntry({ entry }) {
    const [expanded, setExpanded] = useState(false);
    const c = entry.data;
    const attributes = c.changes?.attributes || {};
    const fields = Object.keys(attributes);

    return (
        <Stack gap={1}>
            <HStack gap="1.5">
                <LuPencilLine size={14} style={{ color: 'var(--chakra-colors-purple-500)', flexShrink: 0 }} />
                <Text fontWeight="600" fontSize="sm" color="purple.700" _dark={{ color: 'purple.300' }}>
                    Изменены данные заказа
                </Text>
                <SourceBadge source={c.source} userName={c.user_name} />
            </HStack>

            <Text fontSize="xs" color="fg.muted">
                {fields.map((f) => attributes[f].label).join(', ')}
            </Text>

            <HStack fontSize="xs" color="fg.muted" gap="1">
                <LuClock size={12} />
                <Text>{c.created_at}</Text>
                <Text>•</Text>
                <Text>{c.created_at_human}</Text>
            </HStack>

            {fields.length > 0 && (
                <Box mt={1}>
                    <Button
                        variant="ghost"
                        size="xs"
                        onClick={() => setExpanded(!expanded)}
                    >
                        {expanded ? <LuChevronUp size={14} /> : <LuChevronDown size={14} />}
                        {expanded ? 'Скрыть подробности' : 'Подробности'}
                    </Button>

                    {expanded && (
                        <Box mt={2} bg="gray.50" _dark={{ bg: 'gray.700' }} borderRadius="lg" p={3} fontSize="sm">
                            <Stack gap={2}>
                                {fields.map((field) => {
                                    const entry = attributes[field];
                                    const oldLabel = entry.old_label ?? formatAttr(entry.old);
                                    const newLabel = entry.new_label ?? formatAttr(entry.new);
                                    return (
                                        <HStack key={field} gap="2" align="start">
                                            <Box color="purple.500" mt="1"><LuPencilLine size={14} /></Box>
                                            <Box>
                                                <Text fontWeight="600">{entry.label}</Text>
                                                <Text fontSize="xs" color="fg.muted">
                                                    <Box as="span" textDecoration="line-through">{oldLabel}</Box>
                                                    {' → '}
                                                    <Box as="span" fontWeight="600">{newLabel}</Box>
                                                </Text>
                                            </Box>
                                        </HStack>
                                    );
                                })}
                            </Stack>
                        </Box>
                    )}
                </Box>
            )}
        </Stack>
    );
}

function formatAttr(v) {
    if (v === null || v === undefined || v === '') return '—';
    return String(v);
}
