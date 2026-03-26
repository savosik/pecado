import { router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components';
import {
    Box,
    Text,
    Badge,
    HStack,
    VStack,
    SimpleGrid,
    Input,
    Separator,
    Table,
    IconButton,
    Flex,
    Card,
} from '@chakra-ui/react';
import {
    LuRefreshCw,
    LuCircleCheck,
    LuCircleAlert,
    LuCircle,
    LuTriangleAlert,
    LuInbox,
    LuSend,
    LuSkull,
} from 'react-icons/lu';
import { useState, useCallback } from 'react';

/**
 * Карточка статуса одной очереди.
 */
const QueueCard = ({ queue }) => {
    let colorPalette = 'green';
    let icon = LuCircleCheck;
    let statusText = 'Пустая';

    if (!queue.exists) {
        colorPalette = 'gray';
        icon = LuCircleAlert;
        statusText = 'Не найдена';
    } else if (queue.name.startsWith('erp_dlq.') && queue.total > 0) {
        colorPalette = 'red';
        icon = LuSkull;
        statusText = `${queue.total} мёртвых`;
    } else if (queue.total > 0) {
        colorPalette = 'yellow';
        icon = LuCircle;
        statusText = `${queue.ready} ожид. / ${queue.unacked} обраб.`;
    }

    const Icon = icon;

    return (
        <Card.Root
            size="sm"
            borderWidth="1px"
            borderColor={`${colorPalette}.200`}
            _dark={{ borderColor: `${colorPalette}.800` }}
        >
            <Card.Body p={3}>
                <HStack justify="space-between" mb={1}>
                    <Text fontSize="xs" fontFamily="mono" fontWeight="medium" truncate>
                        {queue.name}
                    </Text>
                    <Icon
                        size={16}
                        color={`var(--chakra-colors-${colorPalette}-500)`}
                    />
                </HStack>
                <Badge colorPalette={colorPalette} size="sm" variant="subtle">
                    {statusText}
                </Badge>
                {queue.exists && (
                    <Text fontSize="xs" color="fg.muted" mt={1}>
                        Воркеров: {queue.consumers}
                    </Text>
                )}
            </Card.Body>
        </Card.Root>
    );
};

/**
 * Секция с группой очередей.
 */
const QueueSection = ({ title, icon: SectionIcon, queues, colorPalette }) => {
    const totalMessages = queues.reduce((sum, q) => sum + q.total, 0);

    return (
        <Box>
            <HStack mb={3} gap={2}>
                <SectionIcon size={18} />
                <Text fontWeight="bold" fontSize="md">{title}</Text>
                {totalMessages > 0 && (
                    <Badge colorPalette={colorPalette} variant="solid" size="sm">
                        {totalMessages}
                    </Badge>
                )}
            </HStack>
            <SimpleGrid columns={{ base: 1, sm: 2, md: 3, lg: 4 }} gap={3}>
                {queues.map((q) => (
                    <QueueCard key={q.name} queue={q} />
                ))}
            </SimpleGrid>
        </Box>
    );
};

/**
 * Пагинатор.
 */
const Pagination = ({ data, paramName = 'page' }) => {
    if (data.last_page <= 1) return null;

    return (
        <HStack justify="center" gap={2} mt={4}>
            {data.links.map((link, i) => {
                if (!link.url) {
                    return (
                        <Text key={i} fontSize="sm" color="fg.muted" px={2}>
                            {link.label.replace('&laquo;', '«').replace('&raquo;', '»')}
                        </Text>
                    );
                }

                return (
                    <Box
                        key={i}
                        as="button"
                        px={3}
                        py={1}
                        fontSize="sm"
                        borderRadius="md"
                        bg={link.active ? 'blue.500' : 'transparent'}
                        color={link.active ? 'white' : 'fg.default'}
                        _hover={{ bg: link.active ? 'blue.600' : 'bg.muted' }}
                        onClick={() => router.visit(link.url, { preserveScroll: true })}
                    >
                        {link.label.replace('&laquo;', '«').replace('&raquo;', '»')}
                    </Box>
                );
            })}
        </HStack>
    );
};

export default function Index({ queues, processed, failedJobs, eventStats, eventTypes, filters }) {
    const [search, setSearch] = useState(filters.search || '');
    const [eventFilter, setEventFilter] = useState(filters.event || '');

    const handleRefresh = useCallback(() => {
        router.reload({ preserveScroll: true });
    }, []);

    const handleSearch = useCallback((e) => {
        const value = e.target.value;
        setSearch(value);

        // Debounced navigation
        clearTimeout(window._erpBusSearchTimeout);
        window._erpBusSearchTimeout = setTimeout(() => {
            router.get(
                route('admin.erp-bus.index'),
                { search: value, event: eventFilter },
                { preserveState: true, preserveScroll: true }
            );
        }, 500);
    }, [eventFilter]);

    const handleEventFilter = useCallback((e) => {
        const value = e.target.value;
        setEventFilter(value);
        router.get(
            route('admin.erp-bus.index'),
            { search, event: value },
            { preserveState: true, preserveScroll: true }
        );
    }, [search]);

    const hasError = queues?.error;

    return (
        <>
            <Flex justify="space-between" align="center" mb={6}>
                <PageHeader title="Шина ERP" />
                <IconButton
                    size="sm"
                    variant="outline"
                    aria-label="Обновить"
                    onClick={handleRefresh}
                >
                    <LuRefreshCw />
                </IconButton>
            </Flex>

            {/* Ошибка подключения */}
            {hasError && (
                <Box
                    bg="red.50"
                    _dark={{ bg: 'red.900/30' }}
                    borderWidth="1px"
                    borderColor="red.200"
                    _darkBorder={{ borderColor: 'red.700' }}
                    borderRadius="md"
                    p={4}
                    mb={6}
                >
                    <HStack gap={2}>
                        <LuTriangleAlert size={20} color="var(--chakra-colors-red-500)" />
                        <Text color="red.600" _dark={{ color: 'red.300' }} fontWeight="medium">
                            {queues.error}
                        </Text>
                    </HStack>
                </Box>
            )}

            {/* Статус очередей */}
            {!hasError && queues && (
                <VStack gap={6} align="stretch" mb={8}>
                    <QueueSection
                        title="Входящие (1С → Сайт)"
                        icon={LuInbox}
                        queues={queues.incoming || []}
                        colorPalette="yellow"
                    />
                    <QueueSection
                        title="DLQ (Мёртвые)"
                        icon={LuSkull}
                        queues={queues.dlq || []}
                        colorPalette="red"
                    />
                    <QueueSection
                        title="Исходящие (Сайт → 1С)"
                        icon={LuSend}
                        queues={queues.outgoing || []}
                        colorPalette="blue"
                    />
                </VStack>
            )}

            <Separator mb={6} />

            {/* Статистика по типам событий */}
            {eventStats && eventStats.length > 0 && (
                <Box mb={8}>
                    <Text fontWeight="bold" fontSize="md" mb={3}>
                        📊 Статистика по типам событий
                    </Text>
                    <SimpleGrid columns={{ base: 2, sm: 3, md: 4, lg: 6 }} gap={3}>
                        {eventStats.map((stat) => (
                            <Card.Root key={stat.event} size="sm">
                                <Card.Body p={3} textAlign="center">
                                    <Text fontSize="xs" fontFamily="mono" color="fg.muted" truncate>
                                        {stat.event}
                                    </Text>
                                    <Text fontSize="xl" fontWeight="bold" color="blue.500">
                                        {stat.count}
                                    </Text>
                                    <Text fontSize="xs" color="fg.muted">
                                        {stat.last_at
                                            ? new Date(stat.last_at).toLocaleString('ru-RU')
                                            : '—'}
                                    </Text>
                                </Card.Body>
                            </Card.Root>
                        ))}
                    </SimpleGrid>
                </Box>
            )}

            <Separator mb={6} />

            {/* Журнал обработанных сообщений */}
            <Box mb={8}>
                <Text fontWeight="bold" fontSize="md" mb={3}>
                    ✅ Журнал обработанных сообщений
                </Text>

                <HStack gap={3} mb={4}>
                    <Input
                        placeholder="Поиск по message_id..."
                        value={search}
                        onChange={handleSearch}
                        size="sm"
                        maxW="300px"
                    />
                    <Box as="select"
                        value={eventFilter}
                        onChange={handleEventFilter}
                        borderWidth="1px"
                        borderRadius="md"
                        px={3}
                        py={1}
                        fontSize="sm"
                        bg="transparent"
                        minW="200px"
                    >
                        <option value="">Все события</option>
                        {eventTypes.map((type) => (
                            <option key={type} value={type}>{type}</option>
                        ))}
                    </Box>
                </HStack>

                {processed.data.length === 0 ? (
                    <Box textAlign="center" py={8} color="fg.muted">
                        <Text>Нет обработанных сообщений</Text>
                        <Text fontSize="sm" mt={1}>
                            Если очереди пусты и здесь пусто — 1С вероятно не посылала сообщений
                        </Text>
                    </Box>
                ) : (
                    <>
                        <Box overflowX="auto">
                            <Table.Root size="sm" striped>
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>Message ID</Table.ColumnHeader>
                                        <Table.ColumnHeader>Событие</Table.ColumnHeader>
                                        <Table.ColumnHeader>Обработано</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {processed.data.map((msg) => (
                                        <Table.Row key={msg.message_id}>
                                            <Table.Cell>
                                                <Text fontFamily="mono" fontSize="xs">
                                                    {msg.message_id}
                                                </Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Badge colorPalette="blue" size="sm" variant="subtle">
                                                    {msg.event}
                                                </Badge>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text fontSize="sm" color="fg.muted">
                                                    {msg.processed_at
                                                        ? new Date(msg.processed_at).toLocaleString('ru-RU')
                                                        : '—'}
                                                </Text>
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        </Box>
                        <Pagination data={processed} />
                    </>
                )}
            </Box>

            <Separator mb={6} />

            {/* Ошибки (failed_jobs) */}
            <Box mb={8}>
                <Text fontWeight="bold" fontSize="md" mb={3}>
                    ❌ Ошибки обработки (failed_jobs)
                </Text>

                {failedJobs.data.length === 0 ? (
                    <Box textAlign="center" py={6} color="fg.muted">
                        <Text color="green.500" fontWeight="medium">Ошибок нет! 🎉</Text>
                    </Box>
                ) : (
                    <>
                        <Box overflowX="auto">
                            <Table.Root size="sm">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>ID</Table.ColumnHeader>
                                        <Table.ColumnHeader>Очередь</Table.ColumnHeader>
                                        <Table.ColumnHeader>Ошибка</Table.ColumnHeader>
                                        <Table.ColumnHeader>Дата</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {failedJobs.data.map((job) => (
                                        <Table.Row key={job.id}>
                                            <Table.Cell>
                                                <Text fontFamily="mono" fontSize="xs">
                                                    {job.id}
                                                </Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Badge colorPalette="red" size="sm" variant="subtle">
                                                    {job.queue}
                                                </Badge>
                                            </Table.Cell>
                                            <Table.Cell maxW="400px">
                                                <Text fontSize="xs" color="red.500" truncate title={job.exception}>
                                                    {job.exception
                                                        ? job.exception.substring(0, 200) + '...'
                                                        : '—'}
                                                </Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text fontSize="sm" color="fg.muted">
                                                    {job.failed_at
                                                        ? new Date(job.failed_at).toLocaleString('ru-RU')
                                                        : '—'}
                                                </Text>
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        </Box>
                        <Pagination data={failedJobs} paramName="failed_page" />
                    </>
                )}
            </Box>
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
