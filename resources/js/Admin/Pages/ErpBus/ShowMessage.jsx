import { Link } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components';
import {
    Box,
    Text,
    Badge,
    HStack,
    VStack,
    IconButton,
    Flex,
    Card,
    Separator,
} from '@chakra-ui/react';
import {
    LuArrowLeft,
    LuInbox,
    LuSend,
    LuCircleCheck,
    LuCircleX,
    LuCopy,
} from 'react-icons/lu';
import { useState, useCallback } from 'react';
import { toaster } from '@/components/ui/toaster';

/**
 * Рекурсивный рендер JSON с красивым форматированием и подсветкой.
 */
const JsonValue = ({ value, depth = 0 }) => {
    const [collapsed, setCollapsed] = useState(depth > 2);

    if (value === null) {
        return <Text as="span" color="gray.400" fontStyle="italic">null</Text>;
    }

    if (typeof value === 'boolean') {
        return <Text as="span" color="purple.400" fontWeight="semibold">{value ? 'true' : 'false'}</Text>;
    }

    if (typeof value === 'number') {
        return <Text as="span" color="blue.400">{value}</Text>;
    }

    if (typeof value === 'string') {
        // Подсветка UUID-подобных строк
        const isUuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value);
        // Подсветка дат
        const isDate = /^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}:\d{2})?/.test(value);

        let color = 'green.400';
        if (isUuid) color = 'orange.400';
        if (isDate) color = 'teal.400';

        return <Text as="span" color={color}>"{value}"</Text>;
    }

    if (Array.isArray(value)) {
        if (value.length === 0) {
            return <Text as="span" color="fg.muted">[]</Text>;
        }

        if (collapsed) {
            return (
                <Text as="span" color="fg.muted" cursor="pointer" onClick={() => setCollapsed(false)}
                    _hover={{ color: 'fg' }}>
                    [{value.length} элементов ▸]
                </Text>
            );
        }

        return (
            <Box>
                <Text as="span" color="fg.muted" cursor="pointer" onClick={() => setCollapsed(true)}
                    _hover={{ color: 'fg' }}>
                    [▾
                </Text>
                <Box pl={5} borderLeftWidth="1px" borderColor="whiteAlpha.200" ml={1}>
                    {value.map((item, i) => (
                        <Box key={i} py={0.5}>
                            <JsonValue value={item} depth={depth + 1} />
                            {i < value.length - 1 && <Text as="span" color="fg.muted">,</Text>}
                        </Box>
                    ))}
                </Box>
                <Text as="span" color="fg.muted">]</Text>
            </Box>
        );
    }

    if (typeof value === 'object') {
        const keys = Object.keys(value);
        if (keys.length === 0) {
            return <Text as="span" color="fg.muted">{'{}'}</Text>;
        }

        if (collapsed) {
            return (
                <Text as="span" color="fg.muted" cursor="pointer" onClick={() => setCollapsed(false)}
                    _hover={{ color: 'fg' }}>
                    {'{'}{keys.length} полей ▸{'}'}
                </Text>
            );
        }

        return (
            <Box>
                <Text as="span" color="fg.muted" cursor="pointer" onClick={() => setCollapsed(true)}
                    _hover={{ color: 'fg' }}>
                    {'{'} ▾
                </Text>
                <Box pl={5} borderLeftWidth="1px" borderColor="whiteAlpha.200" ml={1}>
                    {keys.map((key, i) => (
                        <Box key={key} py={0.5}>
                            <Text as="span" color="red.300" fontWeight="medium">"{key}"</Text>
                            <Text as="span" color="fg.muted">: </Text>
                            <JsonValue value={value[key]} depth={depth + 1} />
                            {i < keys.length - 1 && <Text as="span" color="fg.muted">,</Text>}
                        </Box>
                    ))}
                </Box>
                <Text as="span" color="fg.muted">{'}'}</Text>
            </Box>
        );
    }

    return <Text as="span">{String(value)}</Text>;
};

/**
 * Информационная строка в карточке.
 */
const InfoRow = ({ label, children }) => (
    <Flex gap={3} py={1.5} align="baseline">
        <Text fontSize="sm" fontWeight="medium" color="fg.muted" minW="140px" flexShrink={0}>
            {label}
        </Text>
        <Box fontSize="sm">{children}</Box>
    </Flex>
);

export default function ShowMessage({ message }) {
    const handleCopyJson = useCallback(() => {
        const json = JSON.stringify(message.payload, null, 2);
        navigator.clipboard.writeText(json).then(() => {
            toaster.create({ title: 'JSON скопирован в буфер обмена', type: 'success' });
        }).catch(() => {
            toaster.create({ title: 'Не удалось скопировать', type: 'error' });
        });
    }, [message.payload]);

    return (
        <>
            <Flex justify="space-between" align="center" mb={6}>
                <HStack gap={3}>
                    <Link href={route('admin.erp-bus.messages')}>
                        <IconButton size="sm" variant="ghost" aria-label="Назад к списку">
                            <LuArrowLeft />
                        </IconButton>
                    </Link>
                    <PageHeader title={`Сообщение #${message.id}`} />
                </HStack>
            </Flex>

            {/* Мета-информация */}
            <Card.Root mb={6}>
                <Card.Body p={5}>
                    <VStack align="stretch" gap={0}>
                        <InfoRow label="ID">
                            <Text fontFamily="mono">{message.id}</Text>
                        </InfoRow>
                        <InfoRow label="Направление">
                            <Badge
                                colorPalette={message.direction === 'incoming' ? 'yellow' : 'blue'}
                                size="sm"
                                variant="subtle"
                            >
                                <HStack gap={1}>
                                    {message.direction === 'incoming' ? <LuInbox size={12} /> : <LuSend size={12} />}
                                    <Text>{message.direction === 'incoming' ? '1С → Сайт' : 'Сайт → 1С'}</Text>
                                </HStack>
                            </Badge>
                        </InfoRow>
                        <InfoRow label="Событие">
                            <Badge colorPalette="purple" size="sm" variant="subtle">
                                {message.event}
                            </Badge>
                        </InfoRow>
                        <InfoRow label="Очередь">
                            <Text fontFamily="mono" fontSize="sm">
                                {message.routing_key || '—'}
                            </Text>
                        </InfoRow>
                        <InfoRow label="Message ID">
                            <Text fontFamily="mono" fontSize="sm">
                                {message.message_id || '—'}
                            </Text>
                        </InfoRow>
                        <InfoRow label="Статус">
                            <Badge
                                colorPalette={message.status === 'success' ? 'green' : 'red'}
                                size="sm"
                                variant="subtle"
                            >
                                <HStack gap={1}>
                                    {message.status === 'success'
                                        ? <LuCircleCheck size={12} />
                                        : <LuCircleX size={12} />
                                    }
                                    <Text>{message.status === 'success' ? 'Успешно' : 'Ошибка'}</Text>
                                </HStack>
                            </Badge>
                        </InfoRow>
                        {message.error_message && (
                            <InfoRow label="Ошибка">
                                <Text color="red.400" fontSize="sm">
                                    {message.error_message}
                                </Text>
                            </InfoRow>
                        )}
                        <InfoRow label="Дата">
                            <Text fontSize="sm">
                                {message.created_at
                                    ? new Date(message.created_at).toLocaleString('ru-RU', {
                                        year: 'numeric',
                                        month: '2-digit',
                                        day: '2-digit',
                                        hour: '2-digit',
                                        minute: '2-digit',
                                        second: '2-digit',
                                    })
                                    : '—'}
                            </Text>
                        </InfoRow>
                    </VStack>
                </Card.Body>
            </Card.Root>

            {/* JSON Payload */}
            <Card.Root>
                <Card.Body p={0}>
                    <Flex justify="space-between" align="center" px={5} pt={4} pb={2}>
                        <Text fontWeight="bold" fontSize="md">
                            📦 Payload (JSON)
                        </Text>
                        <IconButton
                            size="sm"
                            variant="ghost"
                            onClick={handleCopyJson}
                            aria-label="Копировать JSON"
                            title="Копировать JSON"
                        >
                            <LuCopy />
                        </IconButton>
                    </Flex>
                    <Separator />
                    <Box
                        p={5}
                        fontFamily="mono"
                        fontSize="sm"
                        lineHeight="1.6"
                        bg="gray.950"
                        _light={{ bg: 'gray.50' }}
                        borderBottomRadius="md"
                        overflowX="auto"
                    >
                        {message.payload ? (
                            <JsonValue value={message.payload} depth={0} />
                        ) : (
                            <Text color="fg.muted" fontStyle="italic">Пустой payload</Text>
                        )}
                    </Box>
                </Card.Body>
            </Card.Root>
        </>
    );
}

ShowMessage.layout = (page) => <AdminLayout>{page}</AdminLayout>;
